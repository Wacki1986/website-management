<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\UserRepository;
use App\Core\Events\EventLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Monitor\PhpSupport;
use App\Core\Reports\ReportSchedule;
use App\Core\Security\RateLimiter;
use App\Core\Service\ServiceSchedule;
use App\Core\Sites\ApiKey;
use App\Core\Sites\SiteRepository;
use App\Core\Sites\SiteStatus;
use App\Core\Views\Pagination;

/**
 * Weby — seznam, přidání a detail se sedmi záložkami (návrh
 * `weby*.html`, `detail-webu-*.html`). Každá záložka je vlastní URL.
 *
 * Záložky Servis a Reporty mají vlastní controllery (etapy P3, P4).
 * Vzdálené akce na pluginech (aktualizovat, smazat) jsou v1 jen
 * neaktivní tlačítka — API pluginu na ně má připravený prostor.
 */
final class SiteController extends Controller
{
    use SiteHeaderTrait;

    // -----------------------------------------------------------------
    // Seznam a přidání
    // -----------------------------------------------------------------

    public function index(): Response
    {
        $request = $this->request();
        $q = $request->string('q');
        $level = in_array($request->string('stav'), ['problem', 'attention', 'ok'], true) ? $request->string('stav') : '';
        $clientId = $request->int('klient');
        $grouped = $request->bool('seskupit');

        $all = [];
        $counts = ['all' => 0, 'problem' => 0, 'attention' => 0, 'ok' => 0];
        $sites = $this->kernel->sites()->all(['q' => $q, 'client' => $clientId]);
        $percents = $this->kernel->uptime()->percentsFor(array_map(static fn (array $s): int => (int) $s['id'], $sites), 30);
        $plans = $this->kernel->service()->plansFor(array_map(static fn (array $s): int => (int) $s['id'], $sites));
        $reportSettings = $this->kernel->reports()->settingsFor(array_map(static fn (array $s): int => (int) $s['id'], $sites));
        $today = date('Y-m-d');

        foreach ($sites as $site) {
            $state = SiteStatus::of($site);
            $counts['all']++;

            if (isset($counts[$state['level']])) {
                $counts[$state['level']]++;
            }

            $percent = $percents[(int) $site['id']] ?? null;

            $all[] = $site + [
                'state' => $state,
                'uptime' => $percent !== null ? number_format($percent, $percent >= 99.95 ? 0 : 1, ',', ' ') . ' %' : '—',
                'uptimeTone' => $percent === null ? 'faint' : ($percent < 99 ? 'error' : ($percent < 99.9 ? 'warning' : '')),
                'host' => SiteRepository::host((string) $site['url']),
                'phpEol' => (string) ($site['snap_php_version'] ?? '') !== '' && PhpSupport::isEol((string) $site['snap_php_version']),
                'phpMinor' => PhpSupport::minor((string) ($site['snap_php_version'] ?? '')),
                'wpMinor' => (string) ($site['snap_wp_version'] ?? ''),
                'updates' => (int) ($site['snap_plugins_updates'] ?? 0) + (($site['snap_wp_update_version'] ?? null) !== null ? 1 : 0),
                'service' => ServiceSchedule::cell($plans[(int) $site['id']] ?? null, $today),
                'report' => self::reportCell($reportSettings[(int) $site['id']] ?? null),
            ];
        }

        $rows = $level === '' ? $all : array_values(array_filter($all, static fn (array $s): bool => $s['state']['level'] === $level));

        // Řazení podle stavu: problémy nahoru, pak pozornost, pak v pořádku.
        $order = ['problem' => 0, 'attention' => 1, 'unknown' => 2, 'ok' => 3];
        usort($rows, static fn (array $a, array $b): int => [$order[$a['state']['level']] ?? 9, $a['name']] <=> [$order[$b['state']['level']] ?? 9, $b['name']]);

        // Seskupení podle klienta: hlavičky `.table__group` (návrh `weby-seskupeno.html`).
        $groups = [];

        if ($grouped) {
            foreach ($rows as $row) {
                $groups[(string) ($row['client_name'] ?? 'Bez klienta')][] = $row;
            }

            ksort($groups);
        }

        $problems = $counts['problem'];

        return $this->view('sites/index', [
            'title' => 'Weby',
            'rows' => $rows,
            'groups' => $groups,
            'grouped' => $grouped,
            'q' => $q,
            'level' => $level,
            'clientId' => $clientId,
            'clients' => $this->kernel->clients()->options(),
            'counts' => $counts,
            'meta' => get_count($counts['all'], 'monitorovaný web', 'monitorované weby', 'monitorovaných webů')
                . ($problems > 0 ? ' · ' . get_count($problems, 's problémem', 's problémem', 's problémem') : ''),
            'shown' => count($rows),
        ]);
    }

    public function createForm(): Response
    {
        return $this->view('sites/form', [
            'title' => 'Přidat web',
            'values' => ['name' => '', 'url' => '', 'client_id' => $this->request()->int('klient'), 'check_interval_min' => 15],
            'errors' => [],
            'clients' => $this->kernel->clients()->options(),
            'intervals' => SiteRepository::INTERVALS,
        ]);
    }

    public function store(): Response
    {
        $request = $this->request();
        $values = [
            'name' => mb_substr($request->string('name'), 0, 150),
            'url' => SiteRepository::normalizeUrl($request->string('url')),
            'client_id' => $request->int('client_id'),
            'check_interval_min' => $request->int('check_interval_min', 15),
        ];
        $errors = [];

        if ($values['url'] === '') {
            $errors['url'] = 'Zadejte adresu webu, třeba kavarnadobra.cz.';
        } elseif (str_starts_with($values['url'], 'http://') && !$this->kernel->env('allow_insecure_sites', false)) {
            $errors['url'] = 'Web musí běžet na https:// — přes http by API klíč šel po síti nešifrovaně.';
        } elseif ($this->kernel->sites()->findByUrl($values['url']) !== null) {
            $errors['url'] = 'Tenhle web už v monitoringu je.';
        }

        if ($values['name'] === '') {
            $values['name'] = SiteRepository::host($values['url']);
        }

        if (!isset(SiteRepository::INTERVALS[$values['check_interval_min']])) {
            $values['check_interval_min'] = 15;
        }

        if ($values['client_id'] !== null && $this->kernel->clients()->find($values['client_id']) === null) {
            $values['client_id'] = null;
        }

        if ($errors !== []) {
            return $this->view('sites/form', [
                'title' => 'Přidat web',
                'values' => $values,
                'errors' => $errors,
                'clients' => $this->kernel->clients()->options(),
                'intervals' => SiteRepository::INTERVALS,
            ], 422);
        }

        $id = $this->kernel->sites()->create($values);
        $key = ApiKey::generate();
        $this->kernel->sites()->setApiKey($id, $key);
        $this->kernel->events()->record($id, EventLog::KIND_SETTINGS, 'ok', 'Web přidán do monitoringu', [], $this->actorName());
        $this->kernel->audit()->record($id, $values['name'], AuditLog::ACTION_SITE_ADD, true, 'Přidán web ' . $values['url']);

        // Klíč se ukáže celý jen jednou — hned na Nastavení webu.
        $_SESSION['fresh_api_key'][$id] = $key;

        return $this->redirectWithFlash('weby/' . $id . '/nastaveni', 'Web je přidaný. Zkopírujte API klíč do pluginu MEDIAGRAFIK Monitor na webu.');
    }

    // -----------------------------------------------------------------
    // Záložky detailu
    // -----------------------------------------------------------------

    public function overview(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $reportSettings = $this->kernel->reports()->settings((int) $id);
        $reportRecipients = $this->kernel->reports()->recipientEmails((int) $id);
        $reportActive = $reportSettings !== null && (int) $reportSettings['is_active'] === 1;
        $reportCard = [
            'active' => $reportActive,
            'line1' => $reportActive
                ? mb_convert_case(ReportSchedule::shortLabel((string) $reportSettings['frequency']), MB_CASE_TITLE, 'UTF-8') . ' → ' . ($reportRecipients !== [] ? implode(', ', $reportRecipients) : 'bez adresáta')
                : 'Reporty jsou vypnuté.',
            'line2' => $reportActive
                ? (($reportSettings['last_sent_at'] ?? null) !== null ? 'Poslední odeslán ' . get_czech_date((string) $reportSettings['last_sent_at']) : 'Zatím žádný neodešel')
                : 'Zapněte je v Nastavení webu.',
        ];
        $snapshot = $this->kernel->snapshots()->snapshot((int) $id);
        $now = time();

        $metrics = null;

        if ($snapshot !== null) {
            $phpEol = PhpSupport::isEol((string) $snapshot['php_version'], $now);
            $metrics = [
                'wp' => ['value' => (string) $snapshot['wp_version'], 'update' => $snapshot['wp_update_version']],
                'php' => ['value' => (string) $snapshot['php_version'], 'eol' => $phpEol, 'daysLeft' => PhpSupport::daysLeft((string) $snapshot['php_version'], $now)],
                'db' => ['value' => trim($snapshot['db_type'] . ' ' . PhpSupport::minor((string) $snapshot['db_version'])), 'size' => $snapshot['db_size_mb'] !== null ? $snapshot['db_size_mb'] . ' MB' : ''],
                'theme' => ['value' => (string) $snapshot['theme_name'], 'note' => trim($snapshot['theme_version'] . ((int) $snapshot['theme_is_child'] === 1 ? ' · child theme' : ''))],
            ];
        }

        $inactive = $snapshot !== null ? (int) $snapshot['plugins_total'] - (int) $snapshot['plugins_active'] : 0;

        $summary = [
            ['label' => 'Pluginy', 'value' => $snapshot !== null ? $snapshot['plugins_total'] . ($inactive > 0 ? ' · ' . $inactive . ' neaktivní' : ' · vše aktivní') : '—', 'tone' => ''],
            ['label' => 'Čekající aktualizace', 'value' => $snapshot !== null ? (string) $snapshot['plugins_updates'] : '—', 'tone' => $snapshot !== null && (int) $snapshot['plugins_updates'] > 0 ? 'warning' : 'ok'],
            ['label' => 'SSL certifikát', 'value' => $site['ssl_valid_to'] !== null ? 'do ' . get_czech_date((string) $site['ssl_valid_to']) : 'zatím nezjištěno', 'tone' => ''],
            ['label' => 'Zálohy', 'value' => $snapshot !== null && $snapshot['last_backup_at'] !== null ? 'poslední ' . get_when((string) $snapshot['last_backup_at']) : ((string) $site['backup_note'] !== '' ? (string) $site['backup_note'] : '—'), 'tone' => ''],
            ['label' => 'Hosting', 'value' => (string) $site['hosting_note'] !== '' ? (string) $site['hosting_note'] : '—', 'tone' => ''],
            ['label' => 'Poslední kontrola', 'value' => $site['last_check_at'] !== null ? get_when((string) $site['last_check_at']) : ($site['last_snapshot_at'] !== null ? get_when((string) $site['last_snapshot_at']) : 'zatím žádná'), 'tone' => ''],
        ];

        // Pásek 30 dní a souhrn (STAVY.md: 100 % · do 30 min · nad 30 min).
        $days = $this->kernel->uptime()->days((int) $id, 30);
        $stats = $this->kernel->uptime()->stats((int) $id, $days[0]['day'], $days[29]['day']);
        $outages = $this->kernel->alerts()->outagesBetween((int) $id, $days[0]['day'], $days[29]['day']);

        // Otevřené alerty: první nejzávažnější do inverzní karty, ostatní jako seznam.
        $openAlerts = array_map(static fn (array $a): array => $a + [
            'age' => get_duration((string) $a['opened_at'], date('Y-m-d H:i:s')),
            'since' => get_when((string) $a['opened_at']),
        ], $this->kernel->alerts()->openForSite((int) $id));

        return $this->view('sites/overview', $this->header($site, 'prehled') + [
            'reportCard' => $reportCard,
            'metrics' => $metrics,
            'summary' => $summary,
            'events' => array_map([$this, 'eventRow'], $this->kernel->events()->latest((int) $id, 6)),
            'eventCount' => $this->kernel->events()->count((int) $id),
            'snapshotAt' => $snapshot !== null ? (string) $snapshot['fetched_at'] : null,
            'uptime' => [
                'days' => $days,
                'percent' => $stats['percent'],
                'checks' => $stats['checks'],
                'downtime' => $stats['downtime_min'] > 0 ? self::minutesLabel($stats['downtime_min']) : '',
                'outages' => count($outages),
                'avgMs' => $stats['avg_ms'],
                'axis' => [get_czech_date($days[0]['day']), get_czech_date($days[15]['day']), 'dnes'],
            ],
            'openAlerts' => $openAlerts,
            'lastIncident' => $this->lastIncident((int) $id),
        ]);
    }

    public function plugins(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $snapshot = $this->kernel->snapshots()->snapshot((int) $id);
        $q = $this->request()->string('q');
        $rows = [];
        $latestUpdate = null;

        foreach ($this->kernel->snapshots()->plugins((int) $id, $q) as $plugin) {
            $rows[] = $plugin + [
                'inactive' => (int) $plugin['is_active'] !== 1,
                'action' => (int) $plugin['has_update'] === 1 ? 'Aktualizovat' : ((int) $plugin['is_active'] !== 1 ? 'Smazat' : ''),
            ];

            if ($plugin['version_changed_at'] !== null && ($latestUpdate === null || $plugin['version_changed_at'] > $latestUpdate['at'])) {
                $latestUpdate = ['at' => (string) $plugin['version_changed_at'], 'name' => $plugin['name'] . ' ' . $plugin['version']];
            }
        }

        $total = $snapshot !== null ? (int) $snapshot['plugins_total'] : 0;
        $active = $snapshot !== null ? (int) $snapshot['plugins_active'] : 0;

        return $this->view('sites/plugins', $this->header($site, 'pluginy') + [
            'rows' => $rows,
            'q' => $q,
            'metrics' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'updates' => $snapshot !== null ? (int) $snapshot['plugins_updates'] : 0,
                'securityUpdates' => $snapshot !== null ? (int) $snapshot['security_updates'] : 0,
                'latestUpdate' => $latestUpdate,
            ],
            'hasSnapshot' => $snapshot !== null,
        ]);
    }

    public function content(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $snapshot = $this->kernel->snapshots()->snapshot((int) $id);
        $rows = [];

        foreach ((array) ($snapshot['data']['content']['post_types'] ?? []) as $type) {
            $latest = is_array($type['latest'] ?? null) ? $type['latest'] : null;
            $daysAgo = $latest !== null && isset($latest['days_ago']) ? (int) $latest['days_ago'] : null;

            $rows[] = [
                'label' => (string) ($type['label'] ?? $type['slug'] ?? ''),
                'slug' => (string) ($type['slug'] ?? ''),
                'published' => (int) ($type['published'] ?? 0),
                'drafts' => (int) ($type['drafts'] ?? 0),
                'latestTitle' => $latest !== null ? (string) ($latest['title'] ?? '') : '—',
                'age' => $daysAgo === null ? ['tone' => 'muted', 'label' => 'bez obsahu']
                    : ['tone' => $daysAgo < 30 ? 'ok' : ($daysAgo < 90 ? 'warning' : 'error'),
                        'label' => $daysAgo === 0 ? 'dnes' : ($daysAgo === 1 ? 'včera' : 'před ' . $daysAgo . ' dny')],
            ];
        }

        return $this->view('sites/content', $this->header($site, 'obsah') + [
            'rows' => $rows,
            'fetchedAt' => $snapshot !== null ? (string) $snapshot['fetched_at'] : null,
        ]);
    }

    public function security(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $audit = $this->kernel->securityAudit()->stored((int) $id);

        return $this->view('sites/security', $this->header($site, 'zabezpeceni') + $this->securityView($audit));
    }

    /** „Ověřit znovu" — stáhne blok security z pluginu a spustí vnější sondy. */
    public function securityCheck(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $key = $this->kernel->sites()->apiKey($site);
        $pluginSecurity = null;

        if ($key !== null) {
            $result = $this->kernel->pluginClient()->security((string) $site['url'], $key);
            $pluginSecurity = $result['ok'] ? (array) ($result['data']['security'] ?? []) : null;
        }

        if ($this->kernel->snapshots()->snapshot((int) $id) === null) {
            return $this->redirectWithFlash('weby/' . $id . '/zabezpeceni', 'Nejdřív je potřeba načíst data z pluginu — klikněte na „Zkontrolovat teď".', 'error');
        }

        $result = $this->kernel->securityAudit()->run($site, $pluginSecurity);
        $this->kernel->events()->record((int) $id, EventLog::KIND_SECURITY, $result['tone'], sprintf(
            'Bezpečnostní kontrola: %d z %d opatření nasazeno', $result['deployed'], count($result['checks']),
        ), ['missing' => $result['missing'], 'partial' => $result['partial']], $this->actorName());

        return $this->redirectWithFlash('weby/' . $id . '/zabezpeceni', 'Bezpečnostní kontrola proběhla.');
    }

    public function history(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $total = $this->kernel->events()->count((int) $id);
        $pagination = Pagination::fromRequest($this->request(), $total, get_url('weby/' . $id . '/historie'));

        return $this->view('sites/history', $this->header($site, 'prehled') + [
            'events' => array_map([$this, 'eventRow'], $this->kernel->events()->page((int) $id, $pagination->perPage, $pagination->offset())),
            'pagination' => $pagination,
        ]);
    }

    public function settings(string $id): Response
    {
        $site = $this->siteOr404((int) $id);

        // Čerstvě vygenerovaný klíč se ukáže jednou a ze session zmizí.
        $fresh = $_SESSION['fresh_api_key'][(int) $id] ?? null;
        unset($_SESSION['fresh_api_key'][(int) $id]);

        $reportSettings = $this->kernel->reports()->settings((int) $id);
        $recipients = $this->kernel->reports()->recipients((int) $id);
        $report = [
            'is_active' => $reportSettings !== null && (int) $reportSettings['is_active'] === 1,
            'frequency' => $reportSettings !== null ? (string) $reportSettings['frequency'] : 'monthly',
            'send_day' => $reportSettings !== null ? (int) $reportSettings['send_day'] : 1,
            'send_hour' => $reportSettings !== null ? (int) $reportSettings['send_hour'] : 6,
            'requires_approval' => $reportSettings !== null && (int) $reportSettings['requires_approval'] === 1,
        ];
        $lastSent = $reportSettings['last_sent_at'] ?? null;
        $nextSend = $reportSettings['next_send_at'] ?? null;

        return $this->view('sites/settings', $this->header($site, 'nastaveni') + [
            'report' => $report,
            'reportNote' => $report['is_active']
                ? 'Aktivní · ' . mb_strtolower(ReportSchedule::describe($report['frequency'], $report['send_day'], $report['send_hour'])) . ($lastSent !== null ? ', poslední odeslán ' . get_czech_date((string) $lastSent) : ', zatím žádný neodešel')
                : 'Vypnuté — klient zatím žádné zprávy nedostává.',
            'reportNext' => $report['is_active'] && $nextSend !== null ? 'Nejbližší odeslání ' . get_when((string) $nextSend) : ($report['is_active'] ? 'Jen ruční odesílání tlačítkem u webu.' : 'Po zapnutí se spočítá nejbližší termín.'),
            'recipients' => $recipients,
            'sendDays' => ReportSchedule::sendDayOptions($report['frequency']),
            'hours' => ReportSchedule::hourOptions(),
            'frequencies' => ReportSchedule::FREQUENCIES,
            'clientEmailHint' => $recipients === [] && (string) ($site['client_email'] ?? '') !== '' ? 'Klient má na kartě adresu ' . (string) $site['client_email'] . ' — přidejte ji sem, reporty se posílají jen na adresy v tomto seznamu.' : '',
            'freshKey' => is_string($fresh) ? $fresh : null,
            'keyMasked' => (string) $site['api_key_hint'] !== '' ? ApiKey::masked((string) $site['api_key_hint']) : '',
            'clients' => $this->kernel->clients()->options(),
            'intervals' => SiteRepository::INTERVALS,
            'pluginVersion' => $this->kernel->pluginDistribution()->version(),
            'pluginInfoUrl' => $this->kernel->appUrl('plugin/mediagrafik-monitor/plugin-info.json'),
        ]);
    }

    public function update(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $request = $this->request();
        $data = [
            'name' => mb_substr($request->string('name'), 0, 150) ?: (string) $site['name'],
            'client_id' => $request->int('client_id'),
            'check_interval_min' => isset(SiteRepository::INTERVALS[$request->int('check_interval_min', 15)]) ? $request->int('check_interval_min', 15) : 15,
            'admin_url' => mb_substr($request->string('admin_url'), 0, 255),
            'hosting_note' => mb_substr($request->string('hosting_note'), 0, 120),
            'backup_note' => mb_substr($request->string('backup_note'), 0, 120),
        ];

        if ($data['client_id'] !== null && $this->kernel->clients()->find($data['client_id']) === null) {
            $data['client_id'] = null;
        }

        $this->kernel->sites()->update((int) $id, $data);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_SITE_EDIT, true, 'Upraveno nastavení webu');

        return $this->redirectWithFlash('weby/' . $id . '/nastaveni', 'Nastavení webu je uložené.');
    }

    /** Přepínače hlídání — tři samostatné toggle, jeden formulář. */
    public function updateWatch(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $request = $this->request();
        $data = [
            'watch_uptime' => $request->bool('watch_uptime') ? 1 : 0,
            'watch_updates' => $request->bool('watch_updates') ? 1 : 0,
            'watch_ssl' => $request->bool('watch_ssl') ? 1 : 0,
        ];

        $this->kernel->sites()->update((int) $id, $data);
        $this->kernel->events()->record((int) $id, EventLog::KIND_SETTINGS, 'ok', sprintf(
            'Hlídání: dostupnost %s, aktualizace %s, SSL %s',
            $data['watch_uptime'] ? 'zap' : 'vyp', $data['watch_updates'] ? 'zap' : 'vyp', $data['watch_ssl'] ? 'zap' : 'vyp',
        ), $data, $this->actorName());

        return $this->redirectWithFlash('weby/' . $id . '/nastaveni', 'Hlídání je uložené.');
    }

    /** Nový API klíč — starý přestane platit hned; plugin dostane nový ručně. */
    public function regenerateKey(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $key = ApiKey::generate();
        $this->kernel->sites()->setApiKey((int) $id, $key);
        $_SESSION['fresh_api_key'][(int) $id] = $key;
        $this->kernel->events()->record((int) $id, EventLog::KIND_SETTINGS, 'warning', 'Vygenerován nový API klíč — plugin na webu potřebuje nový', [], $this->actorName());
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_SITE_KEY, true, 'Nový API klíč');

        return $this->redirectWithFlash('weby/' . $id . '/nastaveni', 'Nový klíč je vygenerovaný. Starý přestal platit — vložte nový do pluginu na webu.');
    }

    public function removeForm(string $id): Response
    {
        $site = $this->siteOr404((int) $id);

        return $this->view('sites/remove', $this->header($site, 'nastaveni') + ['host' => SiteRepository::host((string) $site['url'])]);
    }

    /** Odebrání z monitoringu: potvrzuje se opsáním domény. */
    public function remove(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $host = SiteRepository::host((string) $site['url']);

        if (mb_strtolower($this->request()->string('confirm')) !== mb_strtolower($host)) {
            return $this->redirectWithFlash('weby/' . $id . '/odebrat', 'Pro potvrzení opište doménu webu: ' . $host, 'error');
        }

        $this->kernel->sites()->remove((int) $id);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_SITE_REMOVE, true, 'Web odebrán z monitoringu');

        return $this->redirectWithFlash('weby', 'Web ' . $site['name'] . ' je odebraný z monitoringu. Historie zůstane 12 měsíců v archivu.');
    }

    /**
     * „Zkontrolovat teď": dostupnost, SSL, data z pluginu a bezpečnostní
     * kontrola — stejné cesty jako cron (`MonitorRun::checkOne()`).
     * Rate limit jednou za minutu — tlačítko není DDoS na klientův web.
     */
    public function checkNow(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $limiter = $this->kernel->limiter();

        if ($limiter->tooMany('site-check', (string) $id, 1, 1)) {
            return $this->redirectWithFlash('weby/' . $id, 'Kontrola už před chvílí proběhla — zkuste to za minutu.', 'warning');
        }

        // Limiter počítá jen neúspěchy (success = 0) — pro nás je každý pokus „neúspěch“.
        $limiter->record('site-check', (string) $id, false);

        $result = $this->kernel->monitor()->checkOne($site);
        $parts = [
            $result['uptime']['ok'] ? 'HTTP ' . $result['uptime']['status'] . ' · ' . $result['uptime']['ms'] . ' ms' : 'nedostupný (' . (string) $result['uptime']['error'] . ')',
        ];

        if ($result['ssl'] !== null) {
            $parts[] = $result['ssl']['ok'] ? 'SSL do ' . get_czech_date((string) $result['ssl']['valid_to']) : 'SSL: ' . (string) $result['ssl']['error'];
        }

        if ($result['plugin'] === null) {
            $parts[] = 'bez API klíče';
        } elseif (!$result['plugin']['ok']) {
            $parts[] = 'plugin neodpověděl: ' . (string) $result['plugin']['error'];
        } else {
            $parts[] = 'data načtena' . ($result['audit'] !== null ? ' · zabezpečení ' . $result['audit']['deployed'] . ' z ' . count($result['audit']['checks']) : '');
            $parts[] = $result['changes'] === [] ? 'beze změn' : implode(', ', array_slice($result['changes'], 0, 3)) . (count($result['changes']) > 3 ? ' …' : '');
        }

        $tone = !$result['uptime']['ok'] || ($result['plugin'] !== null && !$result['plugin']['ok']) ? 'warning' : 'success';

        return $this->redirectWithFlash('weby/' . $id, implode(' · ', $parts), $tone);
    }

    /** „Zkontrolovat vše" — dostupnost všech webů najednou. */
    public function checkAll(): Response
    {
        $result = $this->kernel->monitor()->checkAll();

        return $this->redirectWithFlash('weby', sprintf('Zkontrolováno %d webů, %d nedostupných.', $result['checked'], $result['down']), $result['down'] > 0 ? 'warning' : 'success');
    }

    // -----------------------------------------------------------------
    // Pomocníci
    // -----------------------------------------------------------------

    /**
     * Buňka „Report" v seznamech: „měsíčně · 1. 10." / „vypnuté".
     *
     * @param array<string, mixed>|null $settings
     * @return array{label: string, tone: string}
     */
    public static function reportCell(?array $settings): array
    {
        if ($settings === null || (int) $settings['is_active'] !== 1) {
            return ['label' => 'vypnuté', 'tone' => 'faint'];
        }

        $next = $settings['next_send_at'] ?? null;

        return [
            'label' => ReportSchedule::shortLabel((string) $settings['frequency']) . ($next !== null ? ' · ' . date('j. n.', strtotime((string) $next)) : ''),
            'tone' => '',
        ];
    }

    /** @param array<string, mixed>|null $audit @return array<string, mixed> */
    private function securityView(?array $audit): array
    {
        if ($audit === null) {
            return ['checks' => [], 'score' => null, 'todo' => [], 'checkedAt' => null];
        }

        $todo = array_values(array_filter($audit['checks'], static fn (array $c): bool => in_array($c['status'], ['error', 'warning', 'unknown'], true)));
        usort($todo, static fn (array $a, array $b): int => ['error' => 0, 'warning' => 1, 'unknown' => 2][$a['status']] <=> ['error' => 0, 'warning' => 1, 'unknown' => 2][$b['status']]);

        $total = count($audit['checks']);
        $missing = (int) $audit['missing'];
        $partial = (int) $audit['partial'] + (int) $audit['unknown'];
        $worst = $todo[0]['label'] ?? null;

        return [
            'checks' => $audit['checks'],
            'score' => [
                'tone' => $audit['tone'],
                'label' => $audit['tone'] === 'ok' ? 'Zabezpečení kompletní' : 'Zabezpečení neúplné',
                'value' => $audit['deployed'] . ' z ' . $total . ' nasazeno',
                'note' => $audit['tone'] === 'ok'
                    ? 'Všechna opatření platí. Kontrola běží při každém servisu.'
                    : trim(($missing > 0 ? $missing . ' chybí úplně' : '') . ($missing > 0 && $partial > 0 ? ', ' : '') . ($partial > 0 ? $partial . ' s výhradou' : '') . '.'
                        . ($worst !== null ? ' Nejrizikovější: ' . mb_strtolower($worst) . '.' : '')),
            ],
            'todo' => $todo,
            'checkedAt' => (string) $audit['checked_at'],
        ];
    }

    /** „20 h 9 min" z minut. */
    private static function minutesLabel(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60) . ' h' . ($minutes % 60 > 0 ? ' ' . ($minutes % 60) . ' min' : '') : $minutes . ' min';
    }

    /** Poslední vyřešený incident pro klidovou kartu („Poslední incident 22. 8. – 18minutový výpadek"). */
    private function lastIncident(int $siteId): ?string
    {
        $last = $this->kernel->db()->selectOne(
            "SELECT * FROM alerts WHERE site_id = :id AND status = 'resolved' AND severity = 'error' ORDER BY opened_at DESC LIMIT 1",
            ['id' => $siteId],
        );

        if ($last === null) {
            return null;
        }

        return 'Poslední incident ' . get_czech_date((string) $last['opened_at']) . ' – ' . mb_strtolower((string) $last['title'])
            . ($last['resolved_at'] !== null ? ' (' . get_duration((string) $last['opened_at'], (string) $last['resolved_at']) . ')' : '') . '.';
    }

    /** Řádek historie pro šablonu. @param array<string, mixed> $event @return array<string, mixed> */
    private function eventRow(array $event): array
    {
        return $event + [
            'kindLabel' => EventLog::KINDS[(string) $event['kind']] ?? (string) $event['kind'],
            'when' => get_when((string) $event['created_at']),
        ];
    }

    private function actorName(): string
    {
        $user = $this->kernel->auth()->current();

        return $user !== null ? UserRepository::displayName($user) : '';
    }

    /** Web včetně snapshoty, nebo 404 (i pro odebrané weby). @return array<string, mixed> */
    private function siteOr404(int $id): array
    {
        $site = $this->kernel->sites()->findWithSnapshot($id);

        if ($site === null || $site['removed_at'] !== null) {
            throw HttpException::notFound('Web neexistuje nebo byl odebrán z monitoringu.');
        }

        return $site;
    }
}
