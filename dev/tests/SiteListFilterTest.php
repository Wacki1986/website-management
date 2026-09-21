<?php

declare(strict_types=1);

/**
 * Seznam webů: hledání, filtr klienta a odvození stavu (SiteStatus) —
 * to, podle čeho se řadí a seskupuje stránka Weby i dashboard.
 */

use App\Core\Security\Secrets;
use App\Core\Sites\SiteRepository;
use App\Core\Sites\SiteStatus;

return [
    'hledání podle názvu, adresy i klienta; filtr klienta; odebrané weby chybí' => function (): void {
        $db = freshTestDb();
        $sites = new SiteRepository($db, new Secrets(str_repeat('ab', 32)));
        $client = $db->insert('clients', ['name' => 'Zlatý Lev a.s.', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00']);
        $a = $sites->create(['name' => 'Hotel Zlatý Lev', 'url' => 'https://hotelzlatylev.cz', 'client_id' => $client]);
        $b = $sites->create(['name' => 'Kavárna Dobrá', 'url' => 'https://kavarnadobra.cz']);
        $c = $sites->create(['name' => 'Odebraný', 'url' => 'https://odebrany.cz']);
        $sites->remove($c);

        assertSame([$a, $b], array_map(static fn (array $s): int => (int) $s['id'], $sites->all()));
        assertSame([$a], array_map(static fn (array $s): int => (int) $s['id'], $sites->all(['q' => 'zlatý'])));
        assertSame([$b], array_map(static fn (array $s): int => (int) $s['id'], $sites->all(['q' => 'kavarnadobra'])));
        assertSame([$a], array_map(static fn (array $s): int => (int) $s['id'], $sites->all(['q' => 'a.s.'])));
        assertSame([$a], array_map(static fn (array $s): int => (int) $s['id'], $sites->all(['client' => $client])));
        assertSame([], $sites->all(['q' => 'odebrany']));
        assertSame('Zlatý Lev a.s.', $sites->all(['client' => $client])[0]['client_name']);
    },

    'stav webu: pořadí pravidel od nejhoršího' => function (): void {
        $now = strtotime('2026-09-19 10:00:00');
        $base = ['status' => 'ok', 'last_check_at' => '2026-09-19 09:55:00', 'api_status' => 'ok', 'api_failures' => 0];

        assertSame('problem', SiteStatus::of(array_merge($base, ['status' => 'down']), $now)['level']);
        assertSame('SSL vypršel', SiteStatus::of(array_merge($base, ['ssl_valid_to' => '2026-09-18 00:00:00']), $now)['label']);
        assertSame('Plugin neodpovídá', SiteStatus::of(array_merge($base, ['api_status' => 'error', 'api_failures' => 3]), $now)['label']);
        assertSame('PHP 7.4 EOL', SiteStatus::of(array_merge($base, ['snap_php_version' => '7.4.33']), $now)['label']);
        assertSame('Zastaralé WP', SiteStatus::of(array_merge($base, ['snap_php_version' => '8.3.1', 'snap_wp_update_version' => '7.0']), $now)['label']);
        assertSame('SSL brzy vyprší', SiteStatus::of(array_merge($base, ['ssl_valid_to' => '2026-10-01 00:00:00']), $now)['label']);
        assertSame('Neaktivní pluginy', SiteStatus::of(array_merge($base, ['snap_plugins_total' => 10, 'snap_plugins_active' => 8]), $now)['label']);
        assertSame('V pořádku', SiteStatus::of(array_merge($base, ['snap_plugins_total' => 10, 'snap_plugins_active' => 10]), $now)['label']);
        assertSame('Zatím nekontrolováno', SiteStatus::of(['status' => 'unknown', 'last_check_at' => null], $now)['label']);
        // Nedostupnost přebíjí i prošlé SSL.
        assertSame('Nedostupný', SiteStatus::of(array_merge($base, ['status' => 'down', 'ssl_valid_to' => '2026-09-18 00:00:00']), $now)['label']);
    },
];
