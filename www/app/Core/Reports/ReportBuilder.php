<?php

declare(strict_types=1);

namespace App\Core\Reports;

use App\Core\Events\EventLog;
use App\Core\Monitor\AlertRepository;
use App\Core\Monitor\PhpSupport;
use App\Core\Monitor\SecurityAudit;
use App\Core\Monitor\SnapshotImporter;
use App\Core\Monitor\UptimeRepository;
use App\Core\Service\ServiceChecklists;
use App\Core\Service\ServiceRepository;
use App\Core\Service\ServiceSchedule;
use App\Core\Sites\ContentFreshness;
use App\Core\Sites\SiteRepository;
use DateTimeImmutable;

/**
 * Souhrn pro klientský report za období — čísla a věty, ze kterých
 * `ReportRenderer` skládá e-mail. Ukládá se jako `summary_json`, aby šel
 * odeslaný report kdykoli znovu vykreslit i zobrazit v seznamu
 * („uptime 99,4 % · 12 aktualizací · 1 výpadek").
 *
 * Aktualizace se odvozují z rozdílu snapshotů (události plugin/core
 * `updated`); dvě aktualizace téhož pluginu za den se sloučí.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly UptimeRepository $uptime,
        private readonly AlertRepository $alerts,
        private readonly EventLog $events,
        private readonly ServiceRepository $service,
        private readonly SecurityAudit $security,
        private readonly SnapshotImporter $snapshots,
    ) {
    }

    /**
     * @param array<string, mixed> $site řádek webu se snapshotem (`findWithSnapshot`)
     * @return array<string, mixed>
     */
    public function build(array $site, string $from, string $to, string $label, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $siteId = (int) $site['id'];
        $days = (int) ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%a')) + 1;
        $host = SiteRepository::host((string) $site['url']);

        // --- Dostupnost --------------------------------------------------
        $stats = $this->uptime->stats($siteId, $from, $to);
        $dayRows = [];

        foreach ($this->uptime->days($siteId, $days, $to) as $day) {
            $dayRows[] = ['day' => $day['day'], 'tone' => $day['tone'], 'downtime_min' => $day['downtime_min']];
        }

        $outages = [];
        $longest = null;

        foreach ($this->alerts->outagesBetween($siteId, $from, $to) as $alert) {
            $end = $alert['resolved_at'] ?? null;
            $minutes = $end !== null ? max(1, (int) round((strtotime((string) $end) - strtotime((string) $alert['opened_at'])) / 60)) : null;
            $outage = ['from' => $alert['opened_at'], 'to' => $end, 'minutes' => $minutes];
            $outages[] = $outage;

            if ($minutes !== null && ($longest === null || $minutes > $longest['minutes'])) {
                $longest = $outage;
            }
        }

        $percent = $stats['percent'];
        $interval = (int) ($site['check_interval_min'] ?? 15);

        // --- Aktualizace --------------------------------------------------
        $coreUpdates = [];
        $pluginUpdates = [];
        $installed = [];

        foreach ($this->events->between($siteId, $from, $to, [EventLog::KIND_PLUGIN, EventLog::KIND_CORE]) as $event) {
            $detail = json_decode((string) ($event['detail'] ?? '{}'), true) ?: [];
            $action = (string) ($detail['action'] ?? '');

            if ($event['kind'] === EventLog::KIND_CORE && $action === 'updated') {
                $coreUpdates[] = (string) ($detail['to'] ?? '');
            } elseif ($event['kind'] === EventLog::KIND_PLUGIN && $action === 'updated') {
                $pluginUpdates[(string) ($detail['plugin'] ?? '')] = true;
            } elseif ($event['kind'] === EventLog::KIND_PLUGIN && $action === 'installed') {
                $installed[] = (string) ($detail['plugin'] ?? '');
            }
        }

        unset($pluginUpdates['']);
        $pluginNames = array_keys($pluginUpdates);
        $updatesTotal = count($coreUpdates) + count($pluginNames);

        // --- Servis -------------------------------------------------------
        $services = [];

        // `tasks` + `note` jdou do reportu pod sebe; `description` (jedna věta)
        // zůstává pro uložené reporty z doby před úkoly a pro historii.
        foreach ($this->service->logsBetween($siteId, $from, $to) as $log) {
            $kind = ServiceSchedule::KINDS[(string) $log['kind']] ?? ServiceSchedule::KINDS['small'];
            $checklist = ServiceChecklists::decode($log['checklist'] ?? null);
            $services[] = [
                'date' => (string) $log['performed_on'],
                'kind' => (string) $log['kind'],
                'kindLabel' => $kind['label'],
                'description' => ServiceChecklists::text((string) $log['description'], $checklist),
                'tasks' => ServiceChecklists::doneLabels($checklist),
                'note' => trim((string) $log['description']),
                'minutes' => $log['minutes'] !== null ? (int) $log['minutes'] : null,
            ];
        }

        $plan = $this->service->plan($siteId);
        $nextService = $plan !== null && (int) $plan['is_active'] === 1 && $plan['next_date'] !== null
            ? ['date' => (string) $plan['next_date'], 'kindLabel' => ServiceSchedule::KINDS[(string) $plan['kind']]['label'] ?? 'Servis']
            : null;

        // --- Co jsme udělali (věty) ---------------------------------------
        $done = [];

        if ($coreUpdates !== []) {
            $done[] = ['strong' => 'Aktualizovali jsme WordPress', 'text' => 'na verzi ' . end($coreUpdates) . ' — obsahuje bezpečnostní opravy.'];
        }

        if ($pluginNames !== []) {
            $named = array_slice($pluginNames, 0, 2);
            $done[] = [
                'strong' => 'Aktualizovali jsme ' . get_count(count($pluginNames), 'doplněk', 'doplňky', 'doplňků') . ',',
                'text' => count($pluginNames) > 2 ? 'mimo jiné ' . implode(' a ', $named) . '.' : implode(' a ', $named) . '.',
            ];
        }

        $backupAt = $site['snap_last_backup_at'] ?? null;

        if ($backupAt !== null && strtotime((string) $backupAt) >= strtotime($from)) {
            $done[] = ['strong' => 'Zkontrolovali jsme zálohy,', 'text' => 'poslední záloha z ' . get_czech_date((string) $backupAt) . ' je v pořádku.'];
        } elseif ((string) ($site['backup_note'] ?? '') !== '') {
            $done[] = ['strong' => 'Zkontrolovali jsme zálohy,', 'text' => 'web se zálohuje ' . $site['backup_note'] . '.'];
        }

        $sslTo = $site['ssl_valid_to'] ?? null;
        $sslDays = $sslTo !== null ? (int) floor((strtotime((string) $sslTo) - strtotime($today)) / 86400) : null;

        if ($sslTo !== null && $sslDays !== null && $sslDays > 30) {
            $done[] = ['strong' => 'Bezpečnostní certifikát je v pořádku,', 'text' => 'platí do ' . self::longDate((string) $sslTo) . '.'];
        }

        $done[] = ['strong' => 'Hlídali jsme dostupnost webu', 'text' => $stats['checks'] > 0
            ? 'nepřetržitě, ' . number_format($stats['checks'], 0, ',', ' ') . '× za období (každých ' . $interval . ' minut).'
            : 'a připravili jsme pravidelné kontroly každých ' . $interval . ' minut.'];

        // --- Doporučení ---------------------------------------------------
        $recommendations = [];
        $php = (string) ($site['snap_php_version'] ?? '');

        if ($php !== '' && PhpSupport::isEol($php, strtotime($today))) {
            $recommendations[] = ['title' => 'Novější verze PHP', 'text' => 'Váš hosting stále běží na starší verzi PHP (' . PhpSupport::minor($php) . '), pro kterou už nevycházejí bezpečnostní opravy. Přechod na novější verzi zabere zhruba hodinu a doporučujeme ho udělat co nejdřív. Ozvěte se, domluvíme termín.'];
        }

        if ($sslDays !== null && $sslDays < 0) {
            $recommendations[] = ['title' => 'Prošlý bezpečnostní certifikát', 'text' => 'Certifikát webu vypršel ' . self::longDate((string) $sslTo) . '. Prohlížeče návštěvníky varují — řešíme to s hostingem přednostně.'];
        } elseif ($sslDays !== null && $sslDays <= 30) {
            $recommendations[] = ['title' => 'Certifikát brzy vyprší', 'text' => 'Bezpečnostní certifikát platí do ' . self::longDate((string) $sslTo) . '. Pohlídáme jeho obnovení; kdyby se hosting ozval kvůli platbě, dejte nám vědět.'];
        }

        $audit = $this->security->stored($siteId);

        if ($audit !== null && (int) ($audit['missing'] ?? 0) > 0) {
            $missing = (int) $audit['missing'];
            $recommendations[] = ['title' => 'Posílení zabezpečení', 'text' => 'Z pěti doporučených bezpečnostních opatření ' . ($missing === 1 ? 'jedno na webu zatím chybí' : $missing . ' na webu zatím chybí') . '. Rádi je doplníme v rámci příštího servisu — ozvěte se, když to chcete dřív.'];
        }

        // --- Titulek a předmět ---------------------------------------------
        $seriousOutage = $stats['downtime_min'] > 30 || ($percent !== null && $percent < 99);
        $allGood = $outages === [] && $recommendations === [] && ($percent === null || $percent >= 99.9);
        $periodPhrase = self::periodPhrase($from, $to, $label);

        $headline = match (true) {
            $allGood => 'Váš web běžel ' . $periodPhrase . ' bez problému',
            $seriousOutage => 'Váš web měl ' . $periodPhrase . ' výpadek, řešili jsme ho',
            default => 'Váš web běžel ' . $periodPhrase . ' bez vážného problému',
        };

        $subject = $allGood ? 'Váš web ' . $periodPhrase . ': vše v pořádku' : 'Zpráva o vašem webu — ' . mb_strtolower($label);

        $uptimeNote = match (true) {
            $stats['checks'] === 0 => 'Kontroly dostupnosti za toto období ještě neběžely.',
            $stats['downtime_min'] === 0 => 'Web byl dostupný po celé období, návštěvníci nic nepoznali.',
            $longest !== null => 'Web byl nedostupný celkem ' . self::minutes($stats['downtime_min']) . ', z toho nejdéle ' . self::longDate((string) $longest['from'], false) . ' (' . self::minutes((int) $longest['minutes']) . '). Ve zbytku období běžel bez problému.',
            default => 'Web byl nedostupný celkem ' . self::minutes($stats['downtime_min']) . '.',
        };

        return [
            'period' => ['from' => $from, 'to' => $to, 'label' => $label, 'phrase' => $periodPhrase, 'days' => $days],
            'site' => ['name' => (string) $site['name'], 'host' => $host, 'url' => (string) $site['url'], 'client' => (string) ($site['client_name'] ?? '')],
            'headline' => $headline,
            'subject' => $subject,
            'allGood' => $allGood,
            'uptime' => [
                'percent' => $percent,
                'percentLabel' => $percent !== null ? number_format($percent, $percent >= 99.95 ? 0 : 1, ',', ' ') . ' %' : '—',
                'downtime_min' => $stats['downtime_min'],
                'downtimeLabel' => $stats['downtime_min'] > 0 ? self::minutes($stats['downtime_min']) . ' mimo provoz' : 'žádný výpadek',
                'checks' => $stats['checks'],
                'checksLabel' => number_format($stats['checks'], 0, ',', ' '),
                'intervalLabel' => 'každých ' . $interval . ' minut',
                'outages' => $outages,
                'days' => $dayRows,
                'note' => $uptimeNote,
            ],
            'updates' => [
                'total' => $updatesTotal,
                'core' => $coreUpdates,
                'plugins' => $pluginNames,
                'installed' => $installed,
                'noteLabel' => $coreUpdates !== [] ? 'včetně WordPressu' : ($updatesTotal > 0 ? 'doplňky webu' : 'nic nečekalo'),
            ],
            'done' => $done,
            'content' => $this->content($siteId, $today),
            'services' => $services,
            'nextService' => $nextService,
            'recommendations' => $recommendations,
            'technical' => [
                'wp' => (string) ($site['snap_wp_version'] ?? ''),
                'php' => (string) ($site['snap_php_version'] ?? ''),
                'theme' => (string) ($site['snap_theme_name'] ?? ''),
                'plugins_total' => (int) ($site['snap_plugins_total'] ?? 0),
                'plugins_active' => (int) ($site['snap_plugins_active'] ?? 0),
                'plugins_updates' => (int) ($site['snap_plugins_updates'] ?? 0),
                'ssl_valid_to' => $sslTo,
                'hosting' => (string) ($site['hosting_note'] ?? ''),
            ],
            'summaryLine' => implode(' · ', array_filter([
                $percent !== null ? 'uptime ' . number_format($percent, $percent >= 99.95 ? 0 : 1, ',', ' ') . ' %' : null,
                get_count($updatesTotal, 'aktualizace', 'aktualizace', 'aktualizací'),
                $outages !== [] ? get_count(count($outages), 'výpadek', 'výpadky', 'výpadků') : null,
                $services !== [] ? get_count(count($services), 'servis', 'servisy', 'servisů') : null,
            ])),
        ];
    }

    /**
     * Sekce „Obsah webu": typy obsahu z posledních dat pluginu, kdy naposledy
     * něco přibylo, a výzva ke spolupráci, když web obsahově stojí.
     *
     * Stáří se počítá k datu reportu (ne k datu načtení dat), měřítko je
     * stejné jako na záložce Obsah (`ContentFreshness`). O celkovém stavu
     * rozhoduje nejčerstvější typ — když klient píše aspoň novinky, web žije.
     *
     * @return array{types: array<int, array<string, mixed>>, freshestDays: ?int, tone: string, invite: ?array{title: string, text: string}}
     */
    private function content(int $siteId, string $today): array
    {
        $snapshot = $this->snapshots->snapshot($siteId);
        $types = [];
        $freshest = null;

        foreach ((array) ($snapshot['data']['content']['post_types'] ?? []) as $type) {
            $published = (int) ($type['published'] ?? 0);

            // Prázdné typy (nepoužívané šablonou) klienta nezajímají.
            if ($published === 0) {
                continue;
            }

            $latest = is_array($type['latest'] ?? null) ? $type['latest'] : null;
            $date = $latest !== null ? (string) ($latest['date'] ?? '') : '';
            $days = $date !== '' ? ContentFreshness::daysSince($date, $today) : null;

            $types[] = [
                'label' => (string) ($type['label'] ?? $type['slug'] ?? ''),
                'published' => $published,
                'latestTitle' => $latest !== null ? ContentFreshness::title((string) ($latest['title'] ?? '')) : '',
                'days' => $days,
                'tone' => ContentFreshness::tone($days),
                'ageLabel' => $days !== null ? 'naposledy ' . ContentFreshness::ageLabel($days) : 'bez data',
            ];

            if ($days !== null && ($freshest === null || $days < $freshest)) {
                $freshest = $days;
            }
        }

        $tone = ContentFreshness::tone($freshest);

        return [
            'types' => $types,
            'freshestDays' => $freshest,
            'tone' => $tone,
            'invite' => match ($tone) {
                'warning' => [
                    'title' => 'Web by si zasloužil něco nového',
                    'text' => 'Poslední obsah na webu přibyl před ' . $freshest . ' dny. Pravidelné novinky pomáhají ve vyhledávačích i u návštěvníků. Ozvěte se — rádi s vámi projdeme nápady, texty nebo fotky a na webu můžeme pracovat společně.',
                ],
                'error' => [
                    'title' => 'Web obsahově stojí',
                    'text' => 'Na webu už ' . $freshest . ' dní nepřibylo nic nového a návštěvníci i vyhledávače to poznají. Ozvěte se — domluvíme se, jak web oživit, a můžeme na něm pracovat společně.',
                ],
                default => null,
            },
        ];
    }

    /** „celý srpen" / „ve 3. čtvrtletí" / „v týdnu 31. 8. – 6. 9." */
    private static function periodPhrase(string $from, string $to, string $label): string
    {
        $f = new DateTimeImmutable($from);
        $t = new DateTimeImmutable($to);

        if ($f->format('Y-m-01') === $from && $t->format('Y-m-t') === $to && $f->format('Y-m') === $t->format('Y-m')) {
            return 'celý ' . get_czech_month((int) $f->format('n'));
        }

        if (preg_match('/^(\d)\. čtvrtletí (\d{4})$/u', $label, $m) === 1) {
            return 've ' . $m[1] . '. čtvrtletí ' . $m[2];
        }

        return 'od ' . $f->format('j. n.') . ' do ' . $t->format('j. n. Y');
    }

    /** „12. listopadu 2026" (s časem: „22. srpna 14:05"). */
    public static function longDate(string $date, bool $withYear = true): string
    {
        $ts = strtotime($date);

        if ($ts === false) {
            return '—';
        }

        $text = date('j', $ts) . '. ' . get_czech_month((int) date('n', $ts), 'genitive');

        return $withYear ? $text . ' ' . date('Y', $ts) : $text . ' ' . date('H:i', $ts);
    }

    /** „2 h 44 min" / „40 min". */
    public static function minutes(int $minutes): string
    {
        if ($minutes >= 60) {
            return intdiv($minutes, 60) . ' h' . ($minutes % 60 > 0 ? ' ' . ($minutes % 60) . ' min' : '');
        }

        return $minutes . ' min';
    }
}
