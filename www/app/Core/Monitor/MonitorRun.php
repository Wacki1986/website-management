<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Db\Connection;
use App\Core\Events\EventLog;
use App\Core\Log\Logger;
use App\Core\Modules\Modules;
use App\Core\Security\RateLimiter;
use App\Core\Service\ServiceRepository;
use App\Core\Settings\Settings;
use App\Core\Sites\SiteRepository;
use Throwable;

/**
 * Jeden průchod monitoru — volá ho cron každých 5 minut.
 *
 * Kroky: dostupnost → SSL → doména → data z pluginu → denní blok →
 * (reporty, P4). Každý krok má vlastní try/catch: selhání jednoho nesmí
 * zastavit ostatní. Celý běh hlídá časový rozpočet (sdílený hosting má
 * `max_execution_time`) a zámek `flock`, aby se dva běhy nepotkaly.
 *
 * Výsledek se zapisuje do `settings.monitor_last_run` — z něj čte patička
 * bočního menu a Nastavení → Monitoring.
 */
final class MonitorRun
{
    public const LOCK_FILE = 'monitor.lock';

    public function __construct(
        private readonly Connection $db,
        private readonly SiteRepository $sites,
        private readonly UptimeRepository $uptime,
        private readonly SslChecker $ssl,
        private readonly DomainChecker $domain,
        private readonly PluginClient $plugins,
        private readonly SnapshotImporter $importer,
        private readonly SecurityAudit $security,
        private readonly AlertEngine $alerts,
        private readonly Notifier $notifier,
        private readonly MonitorSettings $settings,
        private readonly Settings $rawSettings,
        private readonly RateLimiter $limiter,
        private readonly EventLog $events,
        private readonly Logger $logger,
        private readonly string $lockPath,
        private ?UptimeClient $uptimeClient = null,
        private ?ServiceRepository $service = null,
        // Bez něj (testy mimo Kernel) se volitelné moduly nesbírají.
        private ?Modules $modules = null,
    ) {
    }

    /**
     * @return array{status: string, checked: int, down: int, ssl: int, domain: int, pulled: int, reports: int, seconds: float, error: ?string}
     */
    public function run(?int $now = null, ?int $budgetSeconds = null): array
    {
        $now ??= time();
        $started = microtime(true);
        $budget = $budgetSeconds ?? self::defaultBudget();
        $deadline = $started + $budget;
        $summary = ['status' => 'ok', 'checked' => 0, 'down' => 0, 'ssl' => 0, 'domain' => 0, 'pulled' => 0, 'reports' => 0, 'seconds' => 0.0, 'error' => null];

        $lock = @fopen($this->lockPath, 'c');

        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['status' => 'busy', 'skipped' => 'běží předchozí spuštění'] + $summary;
        }

        // Ukončení spojení klienta (wget) nesmí zabít běh v půlce.
        ignore_user_abort(true);

        foreach (['uptime', 'ssl', 'domain', 'snapshots', 'daily'] as $name) {
            if (microtime(true) > $deadline) {
                $this->logger->warning('Monitor: došel čas, krok přeskočen', ['step' => $name]);
                break;
            }

            try {
                switch ($name) {
                    case 'uptime':
                        $summary['checked'] = $this->stepUptime($now, $summary['down']);
                        break;
                    case 'ssl':
                        $summary['ssl'] = $this->stepSsl($now, $deadline);
                        break;
                    case 'domain':
                        $summary['domain'] = $this->stepDomain($now, $deadline);
                        break;
                    case 'snapshots':
                        $summary['pulled'] = $this->stepSnapshots($now, $deadline);
                        break;
                    default:
                        $this->stepDaily($now);
                }
            } catch (Throwable $e) {
                $summary['status'] = 'error';
                $summary['error'] = $name . ': ' . $e->getMessage();
                $this->logger->error('Monitor: krok selhal', ['step' => $name, 'error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
            }
        }

        // Reporty (P4) se zapojí sem přes hook.
        foreach ($this->afterSteps as $name => $step) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                $summary['reports'] += (int) $step($now, $deadline);
            } catch (Throwable $e) {
                $summary['status'] = 'error';
                $summary['error'] = $name . ': ' . $e->getMessage();
                $this->logger->error('Monitor: krok selhal', ['step' => $name, 'error' => $e->getMessage()]);
            }
        }

        $summary['seconds'] = round(microtime(true) - $started, 1);
        $summary['at'] = date('Y-m-d H:i:s', $now);
        $summary['ok'] = $summary['status'] === 'ok';

        $this->rawSettings->set('monitor_last_run', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        if ($summary['status'] === 'error') {
            $this->notifier->cronFailed((string) $summary['error']);
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        return $summary;
    }

    /** @var array<string, callable(int, float): int> další kroky (reporty) */
    private array $afterSteps = [];

    /** Zapojení dalšího kroku po kontrolách — reporty (P4). @param callable(int, float): int $step */
    public function addStep(string $name, callable $step): void
    {
        $this->afterSteps[$name] = $step;
    }

    /**
     * Ruční kontrola jednoho webu („Zkontrolovat teď"): dostupnost, SSL,
     * data z pluginu a bezpečnostní kontrola — stejné cesty jako cron.
     *
     * @param array<string, mixed> $site
     * @return array{uptime: array{ok: bool, status: int, ms: int, error: ?string}, ssl: ?array, plugin: ?array, changes: array<int, string>, audit: ?array}
     */
    public function checkOne(array $site, ?int $now = null): array
    {
        $now ??= time();
        $stamp = date('Y-m-d H:i:s', $now);
        $client = $this->uptimeClient();

        $result = $client->check((string) $site['url']);
        $this->uptime->record((int) $site['id'], $result, 'manual', (int) $site['check_interval_min'], $stamp);
        $this->alerts->afterUptime($site, $result, $stamp);

        $ssl = null;

        if (str_starts_with((string) $site['url'], 'https://')) {
            $ssl = $this->ssl->check(SiteRepository::host((string) $site['url']), 443, $now);
            $this->alerts->afterSsl($this->sites->find((int) $site['id']) ?? $site, $ssl, $stamp);
        }

        $plugin = null;
        $changes = [];
        $audit = null;
        $key = $this->sites->apiKey($site);

        if ($key !== null) {
            $plugin = $this->plugins->summary((string) $site['url'], $key, $this->modulesFor([(int) $site['id']])[(int) $site['id']] ?? []);
            $import = $this->importer->import($this->sites->find((int) $site['id']) ?? $site, $plugin, $stamp);
            $changes = $import['changes'];
            $fresh = $this->sites->find((int) $site['id']) ?? $site;
            $this->alerts->afterSnapshot($fresh, $this->importer->snapshot((int) $site['id']), $stamp);

            if ($import['imported']) {
                $audit = $this->security->run($fresh, (array) ($plugin['data']['security'] ?? []), $stamp);
            }
        }

        return ['uptime' => $result, 'ssl' => $ssl, 'plugin' => $plugin, 'changes' => $changes, 'audit' => $audit];
    }

    /** „Zkontrolovat vše": jen dostupnost všech webů najednou. @return array{checked: int, down: int} */
    public function checkAll(?int $now = null): array
    {
        $now ??= time();
        $down = 0;
        $checked = $this->checkUptime($this->sites->all(), $now, $down, 'manual');

        return ['checked' => $checked, 'down' => $down];
    }

    // -----------------------------------------------------------------
    // Kroky
    // -----------------------------------------------------------------

    private function stepUptime(int $now, int &$down): int
    {
        return $this->checkUptime($this->dueForUptime($now), $now, $down, 'cron');
    }

    /**
     * @param array<int, array<string, mixed>> $sites
     */
    private function checkUptime(array $sites, int $now, int &$down, string $source): int
    {
        if ($sites === []) {
            return 0;
        }

        $urls = [];

        foreach ($sites as $site) {
            $urls[(int) $site['id']] = (string) $site['url'];
        }

        $results = $this->uptimeClient()->checkMany($urls);
        $stamp = date('Y-m-d H:i:s', $now);

        foreach ($sites as $site) {
            $result = $results[(int) $site['id']] ?? null;

            if ($result === null) {
                continue;
            }

            $this->uptime->record((int) $site['id'], $result, $source, (int) $site['check_interval_min'], $stamp);
            $state = $this->alerts->afterUptime($site, $result, $stamp);

            if ($state['status'] === 'down') {
                $down++;
            }
        }

        return count($sites);
    }

    /** Weby, kterým vypršel interval kontroly. @return array<int, array<string, mixed>> */
    private function dueForUptime(int $now): array
    {
        return $this->db->select(
            'SELECT s.*, c.name AS client_name FROM sites s LEFT JOIN clients c ON c.id = s.client_id
             WHERE s.removed_at IS NULL AND s.watch_uptime = 1
               AND (s.last_check_at IS NULL OR s.last_check_at <= DATE_SUB(:now, INTERVAL s.check_interval_min MINUTE))
             ORDER BY s.last_check_at',
            ['now' => date('Y-m-d H:i:s', $now)],
        );
    }

    private function stepSsl(int $now, float $deadline): int
    {
        $sites = $this->db->select(
            "SELECT s.*, c.name AS client_name FROM sites s LEFT JOIN clients c ON c.id = s.client_id
             WHERE s.removed_at IS NULL AND s.watch_ssl = 1 AND s.url LIKE 'https://%'
               AND (s.ssl_checked_at IS NULL OR s.ssl_checked_at <= DATE_SUB(:now, INTERVAL 24 HOUR))
             ORDER BY s.ssl_checked_at LIMIT 20",
            ['now' => date('Y-m-d H:i:s', $now)],
        );
        $done = 0;

        foreach ($sites as $site) {
            if (microtime(true) > $deadline - 10) {
                break;
            }

            $ssl = $this->ssl->check(SiteRepository::host((string) $site['url']), 443, $now);
            $this->alerts->afterSsl($site, $ssl, date('Y-m-d H:i:s', $now));
            $done++;
        }

        return $done;
    }

    private function stepDomain(int $now, float $deadline): int
    {
        if (!$this->settings->bool('rule_domain_on')) {
            return 0;
        }

        $sites = $this->db->select(
            'SELECT s.*, c.name AS client_name FROM sites s LEFT JOIN clients c ON c.id = s.client_id
             WHERE s.removed_at IS NULL
               AND (s.domain_checked_at IS NULL OR s.domain_checked_at <= DATE_SUB(:now, INTERVAL 7 DAY))
             ORDER BY s.domain_checked_at LIMIT 10',
            ['now' => date('Y-m-d H:i:s', $now)],
        );
        $done = 0;

        foreach ($sites as $site) {
            if (microtime(true) > $deadline - 10) {
                break;
            }

            $this->alerts->afterDomain($site, $this->domain->check(SiteRepository::host((string) $site['url']), $now), date('Y-m-d H:i:s', $now));
            $done++;
        }

        return $done;
    }

    private function stepSnapshots(int $now, float $deadline): int
    {
        $hours = $this->settings->int('monitor_snapshot_hours');
        $sites = $this->db->select(
            'SELECT s.*, c.name AS client_name FROM sites s LEFT JOIN clients c ON c.id = s.client_id
             WHERE s.removed_at IS NULL AND s.api_key IS NOT NULL
               AND (s.last_snapshot_at IS NULL OR s.last_snapshot_at <= DATE_SUB(:now, INTERVAL :hours HOUR))
             ORDER BY s.last_snapshot_at LIMIT 8',
            ['now' => date('Y-m-d H:i:s', $now), 'hours' => $hours],
        );

        if ($sites === [] || microtime(true) > $deadline - 30) {
            return 0;
        }

        $batch = [];
        $modules = $this->modulesFor(array_map(static fn (array $site): int => (int) $site['id'], $sites));

        foreach ($sites as $site) {
            $key = $this->sites->apiKey($site);

            if ($key !== null) {
                $batch[(int) $site['id']] = ['url' => (string) $site['url'], 'key' => $key, 'modules' => $modules[(int) $site['id']] ?? []];
            }
        }

        $results = $this->plugins->summaryMany($batch);
        $stamp = date('Y-m-d H:i:s', $now);
        $done = 0;

        foreach ($sites as $site) {
            $result = $results[(int) $site['id']] ?? null;

            if ($result === null) {
                continue;
            }

            $import = $this->importer->import($site, $result, $stamp);
            $fresh = $this->sites->find((int) $site['id']) ?? $site;
            $this->alerts->afterSnapshot($fresh, $this->importer->snapshot((int) $site['id']), $stamp);

            // Bezpečnostní kontrola jednou denně, ne s každou snapshotou.
            if ($import['imported'] && ($site['snap_security_checked_at'] ?? null) === null) {
                $this->security->run($fresh, (array) ($result['data']['security'] ?? []), $stamp);
            }

            $done++;
        }

        return $done;
    }

    /** Jednou denně po ranní hodině: souhrn aktualizací, retence, úklid. */
    private function stepDaily(int $now): void
    {
        $today = date('Y-m-d', $now);
        $hour = $this->settings->int('monitor_digest_hour');

        if ((int) date('G', $now) < $hour || $this->rawSettings->get('monitor_daily_done') === $today) {
            return;
        }

        $this->rawSettings->set('monitor_daily_done', $today);

        // Souhrn aktualizací za weby s hlídáním.
        $digest = [];

        foreach ($this->db->select(
            'SELECT s.id, s.name, s.url, ss.plugins_updates, ss.wp_update_version FROM sites s
             JOIN site_snapshots ss ON ss.site_id = s.id
             WHERE s.removed_at IS NULL AND s.watch_updates = 1 AND (ss.plugins_updates > 0 OR ss.wp_update_version IS NOT NULL)
             ORDER BY ss.plugins_updates DESC',
        ) as $row) {
            $digest[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'url' => (string) $row['url'],
                'plugins' => (int) $row['plugins_updates'], 'core' => $row['wp_update_version']];
        }

        $this->notifier->updatesDigest($digest);

        // Servis po termínu — pravidlo se vyhodnocuje jednou denně nad
        // všemi aktivními plány (otevře i sám zavře).
        if ($this->service !== null) {
            foreach ($this->service->activePlans() as $row) {
                $this->alerts->afterServicePlan($row, $row, date('Y-m-d H:i:s', $now));
            }
        }

        // Retence.
        $this->uptime->purgeOlderThan($this->settings->int('monitor_history_months'));
        $this->events->purgeOlderThan(24);
        $this->limiter->purge();
        $this->db->execute('DELETE FROM sites WHERE removed_at IS NOT NULL AND removed_at < :before', ['before' => date('Y-m-d H:i:s', strtotime('-12 months', $now))]);
    }

    /**
     * Moduly, jejichž data má plugin u webu poslat (`?modules=`).
     *
     * @param array<int, int> $siteIds
     * @return array<int, array<int, string>>
     */
    private function modulesFor(array $siteIds): array
    {
        return $this->modules?->forSites($siteIds) ?? [];
    }

    private function uptimeClient(): UptimeClient
    {
        return $this->uptimeClient ??= new UptimeClient($this->settings->int('monitor_timeout_s'));
    }

    /** Rozpočet podle `max_execution_time` hostingu, s rezervou. */
    public static function defaultBudget(): int
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit <= 0 ? 50 : max(10, min(50, $limit - 10));
    }

    /** Poslední běh pro UI. @return array<string, mixed>|null */
    public static function lastRun(Settings $settings): ?array
    {
        $decoded = json_decode($settings->get('monitor_last_run'), true);

        return is_array($decoded) ? $decoded : null;
    }
}
