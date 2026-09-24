<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use App\Core\Monitor\AlertRepository;
use App\Core\Monitor\MonitorSettings;
use App\Core\Monitor\PhpSupport;
use App\Core\Monitor\UptimeRepository;
use App\Core\Service\ServiceRepository;
use App\Core\Service\ServiceSchedule;
use App\Core\Sites\SiteRepository;
use App\Core\Sites\SiteStatus;

/**
 * Data pro dashboard (návrh `dashboard*.html`): hero karta s weby, které
 * vyžadují zásah, čtyři metriky a tabulka „Vyžaduje řešení". Čistě čtení
 * z repozitářů, aby šel dashboard otestovat nad testovací databází.
 */
final class DashboardData
{
    /** Po kolika minutách bez běhu cronu se monitor hlásí jako neběžící. */
    public const STALE_FACTOR = 3;

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly UptimeRepository $uptime,
        private readonly AlertRepository $alerts,
        private readonly ServiceRepository $service,
        private readonly MonitorSettings $settings,
    ) {
    }

    /**
     * @param array{level?: string, client?: ?int, q?: string} $filter
     * @param array<string, mixed>|null $lastRun `settings.monitor_last_run`
     * @return array<string, mixed>
     */
    public function build(array $filter, ?array $lastRun, ?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        $all = $this->sites->all();
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $all);
        $percents = $this->uptime->percentsFor($ids, 30, $today);
        $plans = $this->service->plansFor($ids);

        $counts = ['all' => count($all), 'problem' => 0, 'attention' => 0, 'ok' => 0, 'unknown' => 0];
        $updates = ['total' => 0, 'sites' => 0, 'security' => 0];
        $rows = [];
        $hero = [];
        $checkedAny = false;

        foreach ($all as $site) {
            $state = SiteStatus::of($site, $now);
            $counts[$state['level']] = ($counts[$state['level']] ?? 0) + 1;
            $siteUpdates = (int) ($site['snap_plugins_updates'] ?? 0) + (($site['snap_wp_update_version'] ?? null) !== null ? 1 : 0);

            if ($siteUpdates > 0) {
                $updates['total'] += $siteUpdates;
                $updates['sites']++;
                $updates['security'] += (int) ($site['snap_security_updates'] ?? 0);
            }

            if (($site['last_check_at'] ?? null) !== null) {
                $checkedAny = true;
            }

            if ($state['level'] === 'ok') {
                continue;
            }

            $row = $this->row($site, $state, $percents[(int) $site['id']] ?? null, $plans[(int) $site['id']] ?? null, $today, $now);

            if ($state['level'] === 'problem') {
                $hero[] = $this->heroItem($site, $state, $now);
            }

            if (!self::matches($row, $filter)) {
                continue;
            }

            $rows[] = $row;
        }

        // Problémy nahoru, pak podle názvu.
        $order = ['problem' => 0, 'attention' => 1, 'unknown' => 2];
        usort($rows, static fn (array $a, array $b): int => [$order[$a['level']] ?? 9, $a['name']] <=> [$order[$b['level']] ?? 9, $b['name']]);

        $alertCounts = $this->alerts->counts($today);
        $lastOutage = $this->alerts->lastResolvedOutage();
        $needs = $counts['problem'] + $counts['attention'] + $counts['unknown'];

        return [
            'counts' => $counts,
            'needs' => $needs,
            'hero' => [
                'items' => array_slice($hero, 0, 4),
                'more' => max(0, count($hero) - 4),
                'attention' => $counts['attention'] + $counts['unknown'],
                'calm' => $hero === [],
                'calmText' => $hero === [] ? self::calmText($counts['all'], $updates['total'], $lastOutage, $now) : '',
                'footer' => $hero === []
                    ? ($counts['all'] > 0 ? 'Všech ' . get_count($counts['all'], 'web běží', 'weby běží', 'webů běží') . ($updates['total'] > 0 ? ', ' . get_count($updates['total'], 'aktualizace čeká', 'aktualizace čekají', 'aktualizací čeká') . ' na schválení' : '') : 'Zatím žádný web v monitoringu')
                    : 'Dalších ' . get_count($counts['attention'] + $counts['unknown'], 'web vyžaduje', 'weby vyžadují', 'webů vyžaduje') . ' pozornost (zastaralé WP / PHP)',
                'timestamp' => $lastRun !== null ? get_when((string) ($lastRun['at'] ?? ''), $now) . ' · poslední kontrola' : 'cron zatím neběžel',
            ],
            'metrics' => [
                'sites' => ['value' => $counts['all'], 'sub' => $counts['ok'] . ' v pořádku · ' . ($counts['all'] - $counts['ok']) . ' s odchylkou'],
                'attention' => ['value' => $counts['attention'] + $counts['unknown'], 'sub' => 'zastaralé WP / PHP, plugin, neaktivní pluginy'],
                'updates' => ['value' => $updates['total'], 'sub' => $updates['total'] > 0 ? 'na ' . get_count($updates['sites'], 'webu', 'webech', 'webech') . ($updates['security'] > 0 ? ' · ' . get_count($updates['security'], 'bezpečnostní', 'bezpečnostní', 'bezpečnostních') : '') : 'nic nečeká'],
                'alerts' => ['value' => $alertCounts['open'], 'sub' => $alertCounts['open'] > 0 ? get_count($alertCounts['today'], 'nový dnes', 'nové dnes', 'nových dnes') . ' · ' . get_count($alertCounts['ignored'], 'ignorovaný', 'ignorované', 'ignorovaných') : 'žádné aktivní alerty'],
            ],
            'rows' => $rows,
            'monitor' => self::monitorState($lastRun, $this->settings->int('monitor_interval_min'), $now),
            'loading' => $counts['all'] > 0 && !$checkedAny && $lastRun === null,
        ];
    }

    /**
     * Stav monitoru pro pruh nad dashboardem a patičku menu:
     * ok | failed | stale | never.
     *
     * @param array<string, mixed>|null $lastRun
     * @return array{state: string, at: ?string, error: string}
     */
    public static function monitorState(?array $lastRun, int $intervalMin, int $now): array
    {
        if ($lastRun === null || ($lastRun['at'] ?? null) === null) {
            return ['state' => 'never', 'at' => null, 'error' => ''];
        }

        $at = (string) $lastRun['at'];
        $age = $now - (int) strtotime($at);
        $limit = max(20, $intervalMin * self::STALE_FACTOR) * 60;

        if ($age > $limit) {
            return ['state' => 'stale', 'at' => $at, 'error' => 'Cron se neozval ' . get_duration($at, date('Y-m-d H:i:s', $now)) . '.'];
        }

        if (($lastRun['ok'] ?? false) !== true) {
            return ['state' => 'failed', 'at' => $at, 'error' => (string) ($lastRun['error'] ?? 'průchod skončil chybou')];
        }

        return ['state' => 'ok', 'at' => $at, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $site
     * @param array{level: string, tone: string, label: string} $state
     * @param array<string, mixed>|null $plan
     * @return array<string, mixed>
     */
    private function row(array $site, array $state, ?float $percent, ?array $plan, string $today, int $now): array
    {
        $php = (string) ($site['snap_php_version'] ?? '');
        $wpUpdate = ($site['snap_wp_update_version'] ?? null) !== null;
        $updates = (int) ($site['snap_plugins_updates'] ?? 0) + ($wpUpdate ? 1 : 0);
        $service = ServiceSchedule::cell($plan, $today);

        return [
            'id' => (int) $site['id'],
            'name' => (string) $site['name'],
            'icon' => (string) ($site['icon'] ?? ''),
            'host' => SiteRepository::host((string) $site['url']),
            'clientId' => $site['client_id'] !== null ? (int) $site['client_id'] : null,
            'clientName' => (string) ($site['client_name'] ?? ''),
            'level' => $state['level'],
            'state' => $state,
            'uptime' => $percent !== null ? number_format($percent, $percent >= 99.95 ? 0 : 1, ',', ' ') . ' %' : '—',
            'uptimeTone' => $percent === null ? 'faint' : ($percent < 99 ? 'error' : ($percent < 99.9 ? 'warning' : '')),
            'wp' => (string) ($site['snap_wp_version'] ?? ''),
            'wpTone' => $wpUpdate ? 'warning' : '',
            'php' => PhpSupport::minor($php),
            'phpTone' => PhpSupport::tone($php, $now),
            'db' => trim((string) ($site['snap_db_type'] ?? '') . ' ' . PhpSupport::minor((string) ($site['snap_db_version'] ?? ''))),
            'updates' => $updates,
            'service' => $service['label'],
            'serviceTone' => $service['tone'] === 'faint' && $state['level'] !== 'ok' ? 'warning' : $service['tone'],
            'checked' => get_ago($site['last_check_at'] ?? null, $now),
        ];
    }

    /**
     * Položka hero karty: co je špatně a jak dlouho.
     *
     * @param array<string, mixed> $site
     * @param array{level: string, tone: string, label: string} $state
     * @return array<string, mixed>
     */
    private function heroItem(array $site, array $state, int $now): array
    {
        $host = SiteRepository::host((string) $site['url']);
        $nowText = date('Y-m-d H:i:s', $now);
        $open = $this->alerts->openForSite((int) $site['id']);
        $down = null;

        foreach ($open as $alert) {
            if ($alert['type'] === 'down') {
                $down = $alert;
            }
        }

        if ($state['label'] === 'Nedostupný') {
            $code = (int) ($site['last_status_code'] ?? 0);
            $since = $down !== null ? (string) $down['opened_at'] : (string) ($site['last_ok_at'] ?? $site['last_check_at'] ?? $nowText);

            return [
                'id' => (int) $site['id'],
                'name' => (string) $site['name'],
                'icon' => (string) ($site['icon'] ?? ''),
                'note' => $host . ' · ' . ($code > 0 ? 'HTTP ' . $code : 'server neodpovídá') . ', ' . get_count((int) $site['consecutive_failures'], 'kontrola po sobě selhala', 'kontroly po sobě selhaly', 'kontrol po sobě selhalo'),
                'duration' => get_duration($since, $nowText),
                'kind' => 'mimo provoz',
            ];
        }

        $sslTo = (string) ($site['ssl_valid_to'] ?? '');

        return [
            'id' => (int) $site['id'],
            'name' => (string) $site['name'],
            'icon' => (string) ($site['icon'] ?? ''),
            'note' => $host . ' · certifikát vypršel ' . date('j. n.', (int) strtotime($sslTo)) . ', prohlížeč hlásí varování',
            'duration' => get_duration($sslTo, $nowText),
            'kind' => 'neplatné SSL',
        ];
    }

    /** @param array<string, mixed>|null $lastOutage */
    private static function calmText(int $siteCount, int $updates, ?array $lastOutage, int $now): string
    {
        if ($siteCount === 0) {
            return 'Přidejte první web — dashboard se začne plnit po první kontrole.';
        }

        if ($lastOutage === null) {
            return 'Žádný web nehlásí problém a monitor zatím nezaznamenal žádný výpadek.';
        }

        $minutes = max(1, (int) round(((int) strtotime((string) $lastOutage['resolved_at']) - (int) strtotime((string) $lastOutage['opened_at'])) / 60));

        return 'Žádný web nehlásí problém. Poslední incident byl ' . date('j. n.', (int) strtotime((string) $lastOutage['opened_at'])) . ' – ' . $minutes . 'minutový výpadek u webu ' . $lastOutage['site_name'] . '.';
    }

    /** @param array<string, mixed> $row @param array{level?: string, client?: ?int, q?: string} $filter */
    private static function matches(array $row, array $filter): bool
    {
        $level = $filter['level'] ?? '';

        if ($level === 'problem' && $row['level'] !== 'problem') {
            return false;
        }

        if ($level === 'attention' && $row['level'] === 'problem') {
            return false;
        }

        if (($filter['client'] ?? null) !== null && $row['clientId'] !== (int) $filter['client']) {
            return false;
        }

        $q = trim((string) ($filter['q'] ?? ''));

        return $q === '' || mb_stripos($row['name'] . ' ' . $row['host'] . ' ' . $row['clientName'], $q) !== false;
    }
}
