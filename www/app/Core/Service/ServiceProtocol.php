<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Events\EventLog;
use App\Core\Sites\SiteRepository;

/**
 * Servisní protokol — PDF k jednomu zapsanému servisu, které studio
 * posílá klientovi (třeba spolu s fakturou). Klientský report servis
 * neobsahuje; o servisu se klient dozví právě z protokolu.
 *
 * Protokol ukazuje, co klienta zajímá: web, datum, druh servisu, hotové
 * úkoly z checklistu, poznámku a aktualizace, které monitor ten den na
 * webu zaznamenal (WordPress, pluginy, PHP), stáří webu a příští servis
 * podle plánu. Kdo servis dělal a kolik
 * času zabral, do něj záměrně nejde.
 *
 * Tahle třída jen skládá hotové texty pro šablonu `pdf/service-protocol`;
 * PDF z ní vyrobí `PdfRenderer`.
 */
final class ServiceProtocol
{
    /** Druhy událostí, ze kterých se skládá přehled aktualizací. */
    public const EVENT_KINDS = [EventLog::KIND_CORE, EventLog::KIND_PLUGIN, EventLog::KIND_SYSTEM];

    /**
     * Okno, ve kterém se hledají aktualizace: celý den servisu a dopoledne
     * po něm. Aktualizace z wp-adminu monitor zjistí až při nejbližší
     * kontrole webu (po 3–12 hodinách) — po večerním servisu tedy ráno.
     *
     * @return array{0: string, 1: string} od, do (Y-m-d H:i:s)
     */
    public static function eventWindow(string $performedOn): array
    {
        return [$performedOn . ' 00:00:00', date('Y-m-d', (int) strtotime($performedOn . ' +1 day')) . ' 12:00:00'];
    }

    /**
     * Proměnné šablony `pdf/service-protocol`.
     *
     * @param array<string, mixed> $site   web (s `client_name`, `platform`)
     * @param array<string, mixed> $log    zápis servisu (`service_logs`)
     * @param array<int, array<string, mixed>> $events události z `eventWindow()` (druhy `EVENT_KINDS`)
     * @param array{name: string} $studio jméno studia (popisek loga; patičku protokol nemá)
     * @param string $logoPath absolutní cesta k logu pro tmavé záhlaví (Dompdf čte soubor, ne URL)
     * @param string $today    datum vystavení (Y-m-d)
     * @param array<string, mixed>|null $plan plán servisu webu (`service_plans`) — kvůli příštímu termínu
     * @return array<string, mixed>
     */
    public static function data(array $site, array $log, array $events, array $studio, string $logoPath, string $today, ?array $plan = null): array
    {
        $kind = ServiceSchedule::KINDS[(string) $log['kind']] ?? ServiceSchedule::KINDS['small'];
        $done = (string) $log['status'] !== 'skipped';
        $host = SiteRepository::host((string) $site['url']);
        $tasks = ServiceChecklists::doneLabels(ServiceChecklists::decode($log['checklist'] ?? null));
        $updates = self::updates($events);
        $updateRows = self::updateRows($updates);
        // Název webu pod doménou jen když říká něco navíc.
        $siteName = mb_strtolower((string) $site['name']) !== mb_strtolower($host) ? (string) $site['name'] : '';

        return [
            'title' => 'Servisní protokol č. ' . (int) $log['id'] . ' · ' . $host,
            'number' => (string) (int) $log['id'],
            'issued' => get_czech_date($today),
            'kindLabel' => $kind['label'],
            'siteHost' => $host,
            'subline' => implode(' · ', array_filter([$siteName, (string) ($site['client_name'] ?? '')], static fn (string $part): bool => $part !== '')),
            'launched' => self::launched($site['launched_on'] ?? null, (string) $log['performed_on']),
            'date' => get_czech_date((string) $log['performed_on']),
            'status' => $done ? ['tone' => 'ok', 'label' => 'Provedeno'] : ['tone' => 'warning', 'label' => 'Přeskočeno'],
            'tiles' => self::tiles($tasks, $updates, SiteRepository::isWordpress($site)),
            // Úkoly ve dvou sloupcích; lichý poslední řádek dostane prázdnou buňku.
            'taskRows' => array_map(static fn (array $pair): array => array_pad($pair, 2, ''), array_chunk($tasks, 2)),
            'updateRows' => $updateRows,
            'updateCount' => $updateRows !== [] ? (string) count($updateRows) : '',
            'otherChanges' => self::otherChanges($updates),
            'updatesNote' => 'Podle monitoringu webu ' . get_czech_date((string) $log['performed_on']) . ' a následující dopoledne.',
            'nextService' => self::nextService($plan, (string) $log['performed_on']),
            'note' => trim((string) $log['description']),
            'logoPath' => $logoPath,
            'studioName' => $studio['name'],
        ];
    }

    /**
     * Události → co se změnilo. Víc aktualizací téhož pluginu se slije
     * do jedné (první „z", poslední „na"); co se vrátilo na původní verzi,
     * vypadne.
     *
     * @param array<int, array<string, mixed>> $events seřazené od nejstarší
     * @return array{core: ?array{from: string, to: string}, php: ?array{from: string, to: string}, plugins: array<string, array{from: string, to: string}>, installed: array<int, string>, removed: array<int, string>}
     */
    public static function updates(array $events): array
    {
        $result = ['core' => null, 'php' => null, 'plugins' => [], 'installed' => [], 'removed' => []];

        foreach ($events as $event) {
            $detail = json_decode((string) ($event['detail'] ?? '{}'), true) ?: [];
            $action = (string) ($detail['action'] ?? '');
            $from = (string) ($detail['from'] ?? '');
            $to = (string) ($detail['to'] ?? '');
            $plugin = (string) ($detail['plugin'] ?? '');

            if ($event['kind'] === EventLog::KIND_CORE && $action === 'updated') {
                $result['core'] = ['from' => $result['core']['from'] ?? $from, 'to' => $to];
            } elseif ($event['kind'] === EventLog::KIND_SYSTEM && $action === 'php') {
                $result['php'] = ['from' => $result['php']['from'] ?? $from, 'to' => $to];
            } elseif ($event['kind'] !== EventLog::KIND_PLUGIN || $plugin === '') {
                continue;
            } elseif ($action === 'updated') {
                $result['plugins'][$plugin] = ['from' => $result['plugins'][$plugin]['from'] ?? $from, 'to' => $to];
            } elseif ($action === 'installed') {
                $result['installed'][] = $plugin;
            } elseif ($action === 'removed') {
                $result['removed'][] = $plugin;
            }
        }

        $result['plugins'] = array_filter($result['plugins'], static fn (array $change): bool => $change['from'] !== $change['to']);
        ksort($result['plugins'], SORT_NATURAL | SORT_FLAG_CASE);

        foreach (['core', 'php'] as $key) {
            if ($result[$key] !== null && $result[$key]['from'] === $result[$key]['to']) {
                $result[$key] = null;
            }
        }

        $result['installed'] = array_values(array_unique($result['installed']));
        $result['removed'] = array_values(array_unique($result['removed']));

        return $result;
    }

    /**
     * Velká čísla nahoře: hotové úkoly, aktualizované pluginy, WordPress.
     * Web mimo WordPress má jen úkoly — pluginy ani WordPress nemá.
     *
     * @param array<int, string> $tasks
     * @param array<string, mixed> $updates z `updates()`
     * @return array<int, array{label: string, value: string, note: string, tone: string}>
     */
    private static function tiles(array $tasks, array $updates, bool $isWordpress): array
    {
        $tiles = [[
            'label' => 'Hotové úkoly',
            'value' => $tasks !== [] ? (string) count($tasks) : '—',
            'note' => $tasks !== [] ? get_plural(count($tasks), 'úkol', 'úkoly', 'úkolů') . ' ze servisního seznamu' : 'bez seznamu úkolů',
            'tone' => 'ok',
        ]];

        if (!$isWordpress) {
            return $tiles;
        }

        $plugins = count($updates['plugins']);
        $installed = count($updates['installed']);
        $tiles[] = [
            'label' => 'Aktualizované pluginy',
            'value' => (string) $plugins,
            'note' => $installed > 0 ? '+ ' . get_count($installed, 'nový plugin', 'nové pluginy', 'nových pluginů') : ($plugins > 0 ? 'na nejnovější verze' : 'vše bylo aktuální'),
            'tone' => 'brand',
        ];
        $tiles[] = [
            'label' => 'WordPress',
            'value' => $updates['core'] !== null ? $updates['core']['to'] : 'beze změny',
            'note' => $updates['core'] !== null ? 'aktualizováno z ' . $updates['core']['from'] : 'verze byla aktuální',
            'tone' => 'neutral',
        ];

        return $tiles;
    }

    /**
     * Řádky tabulky aktualizací: WordPress, PHP, pak pluginy podle abecedy.
     *
     * @param array<string, mixed> $updates z `updates()`
     * @return array<int, array{name: string, type: string, from: string, to: string}>
     */
    private static function updateRows(array $updates): array
    {
        $rows = [];

        if ($updates['core'] !== null) {
            $rows[] = ['name' => 'WordPress', 'type' => 'jádro webu'] + $updates['core'];
        }

        if ($updates['php'] !== null) {
            $rows[] = ['name' => 'PHP', 'type' => 'serverové prostředí'] + $updates['php'];
        }

        foreach ($updates['plugins'] as $name => $change) {
            $rows[] = ['name' => (string) $name, 'type' => 'plugin'] + $change;
        }

        return $rows;
    }

    /**
     * Nové a odebrané pluginy — v PDF samostatné zvýrazněné řádky pod
     * tabulkou, aby nezapadly mezi aktualizacemi.
     *
     * @param array<string, mixed> $updates z `updates()`
     * @return array<int, array{tone: string, symbol: string, label: string, count: string, plugins: array<int, string>}>
     */
    private static function otherChanges(array $updates): array
    {
        $groups = [
            ['tone' => 'ok', 'symbol' => '+', 'label' => 'Nově nainstalováno', 'plugins' => $updates['installed']],
            ['tone' => 'warning', 'symbol' => '−', 'label' => 'Odebráno', 'plugins' => $updates['removed']],
        ];
        $changes = [];

        foreach ($groups as $group) {
            if ($group['plugins'] !== []) {
                $changes[] = $group + ['count' => get_count(count($group['plugins']), 'plugin', 'pluginy', 'pluginů')];
            }
        }

        return $changes;
    }

    /**
     * Stáří webu do rámečku v záhlaví: „5 let" + „spuštěn 15. 3. 2021".
     * Počítá se ke dni servisu (ne ke dni stažení, ať starý protokol pořád
     * říká totéž). Bez data spuštění, nebo když web v den servisu ještě
     * nejel, rámeček není.
     *
     * @return array{age: string, since: string}|null
     */
    private static function launched(?string $launchedOn, string $performedOn): ?array
    {
        if ($launchedOn === null || $launchedOn === '' || $launchedOn > $performedOn) {
            return null;
        }

        $age = (new \DateTimeImmutable($launchedOn))->diff(new \DateTimeImmutable($performedOn));

        return [
            'age' => match (true) {
                $age->y > 0 => get_count($age->y, 'rok', 'roky', 'let'),
                $age->m > 0 => get_count($age->m, 'měsíc', 'měsíce', 'měsíců'),
                default => 'pár dní',
            },
            'since' => 'spuštěn ' . get_czech_date($launchedOn),
        ];
    }

    /**
     * Příští servis podle plánu. Jen zapnutý plán s termínem po tomhle
     * servisu — přeskočený servis termín neposouvá a „příští" by pak
     * ukazovalo na ten, o kterém protokol je. Popis druhu
     * (`KINDS[...]['note']`) se nepíše — klientovi by sliboval rozsah,
     * který servis ve skutečnosti mít nemusí.
     *
     * @param array<string, mixed>|null $plan
     * @return array{kind: string, date: string, rhythm: string}|null
     */
    private static function nextService(?array $plan, string $performedOn): ?array
    {
        if ($plan === null || (int) $plan['is_active'] !== 1 || ($plan['next_date'] ?? null) === null || (string) $plan['next_date'] <= $performedOn) {
            return null;
        }

        $kind = ServiceSchedule::KINDS[(string) $plan['kind']] ?? ServiceSchedule::KINDS['small'];
        $frequency = (string) $plan['frequency'];

        return [
            'kind' => $kind['label'],
            'date' => get_czech_date((string) $plan['next_date']),
            'rhythm' => $frequency !== 'once' && isset(ServiceSchedule::FREQUENCIES[$frequency])
                ? 'opakuje se ' . mb_strtolower(ServiceSchedule::FREQUENCIES[$frequency]['label'])
                : 'jednorázový servis',
        ];
    }

    /**
     * Název staženého souboru: `servisni-protokol-penam-cz-2026-10-07.pdf` —
     * podle webu a data, ať se v e-mailu i ve složce dá poznat bez otevření.
     *
     * @param array<string, mixed> $site
     * @param array<string, mixed> $log
     */
    public static function filename(array $site, array $log): string
    {
        $host = (string) preg_replace('/^www\./', '', strtolower(SiteRepository::host((string) $site['url'])));
        $host = trim((string) preg_replace('/[^a-z0-9]+/', '-', $host), '-');

        return 'servisni-protokol-' . ($host !== '' ? $host . '-' : '') . (string) $log['performed_on'] . '.pdf';
    }
}
