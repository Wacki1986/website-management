<?php

declare(strict_types=1);

/**
 * Dashboard: hero, metriky a tabulka „Vyžaduje řešení" nad testovací DB;
 * stav monitoru (běží / selhal / neběží / nikdy).
 */

use App\Core\Dashboard\DashboardData;
use App\Core\Service\ServiceRepository;

require_once __DIR__ . '/fixtures/monitor-fixture.php';

return [
    'stav monitoru: ok, selhal, zastaralý, nikdy' => function (): void {
        $now = strtotime('2026-09-19 10:00:00');
        assertSame('never', DashboardData::monitorState(null, 15, $now)['state']);
        assertSame('ok', DashboardData::monitorState(['at' => '2026-09-19 09:50:00', 'ok' => true], 15, $now)['state']);
        assertSame('failed', DashboardData::monitorState(['at' => '2026-09-19 09:50:00', 'ok' => false, 'error' => 'ssl: x'], 15, $now)['state']);
        // 3× interval (min. 20 min) bez běhu = cron neběží.
        assertSame('stale', DashboardData::monitorState(['at' => '2026-09-19 08:00:00', 'ok' => true], 15, $now)['state']);
        assertSame('ok', DashboardData::monitorState(['at' => '2026-09-19 09:20:00', 'ok' => true], 15, $now)['state']);
        assertSame('ok', DashboardData::monitorState(['at' => '2026-09-19 08:00:00', 'ok' => true], 60, $now)['state']);
    },

    'prázdný, klidný a problémový dashboard; filtry segmentů a klienta' => function (): void {
        $f = monitorFixture();
        $service = new ServiceRepository($f['db']);
        $dashboard = new DashboardData($f['sites'], $f['uptime'], $f['alerts'], $service, $f['monitorSettings']);
        $now = strtotime('2026-09-19 10:00:00');

        $empty = $dashboard->build([], null, $now);
        assertSame(0, $empty['counts']['all']);
        assertTrue($empty['hero']['calm']);
        assertFalse($empty['loading']);

        $clientId = $f['db']->insert('clients', ['name' => 'Kavárna Dobrá s.r.o.', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00']);
        $ok = $f['sites']->create(['name' => 'Pekařství', 'url' => 'https://pekarstvi.cz']);
        $down = $f['sites']->create(['name' => 'Kavárna Dobrá', 'url' => 'https://kavarnadobra.cz', 'client_id' => $clientId]);
        $eol = $f['sites']->create(['name' => 'Ordinace', 'url' => 'https://ordinace.cz']);

        // Weby bez jediné kontroly → načítání.
        $loading = $dashboard->build([], null, $now);
        assertTrue($loading['loading']);
        assertSame(3, $loading['counts']['unknown']);

        $f['sites']->update($ok, ['status' => 'ok', 'last_check_at' => '2026-09-19 09:55:00']);
        $f['sites']->update($down, ['status' => 'down', 'consecutive_failures' => 3, 'last_status_code' => 503, 'last_check_at' => '2026-09-19 09:55:00', 'last_ok_at' => '2026-09-19 09:10:00']);
        $f['alerts']->open($down, 'down', 'error', 'Web nedostupný – HTTP 503', '3 kontroly', 'pravidlo', [], '2026-09-19 09:37:00');
        $f['sites']->update($eol, ['status' => 'ok', 'last_check_at' => '2026-09-19 09:55:00']);
        $f['db']->execute("INSERT INTO site_snapshots (site_id, fetched_at, payload, php_version, wp_version, db_type, db_version, plugins_total, plugins_active, plugins_updates) VALUES (:id, '2026-09-19 08:00:00', '{}', '7.4.33', '6.9', 'MariaDB', '10.6.12', 20, 20, 6)", ['id' => $eol]);
        $service->savePlan($eol, ['is_active' => true, 'kind' => 'small', 'frequency' => 'monthly', 'first_date' => '2026-09-10'], '2026-09-19');

        $data = $dashboard->build([], ['at' => '2026-09-19 09:55:00', 'ok' => true], $now);
        assertFalse($data['loading']);
        assertSame(['all' => 3, 'problem' => 1, 'attention' => 1, 'ok' => 1, 'unknown' => 0], $data['counts']);
        assertSame(2, $data['needs']);
        assertFalse($data['hero']['calm']);
        assertSame(1, count($data['hero']['items']));
        assertSame('Kavárna Dobrá', $data['hero']['items'][0]['name']);
        assertContainsString('HTTP 503', $data['hero']['items'][0]['note']);
        assertSame('mimo provoz', $data['hero']['items'][0]['kind']);
        assertSame('23 min', $data['hero']['items'][0]['duration']);
        assertSame(6, $data['metrics']['updates']['value']);
        assertSame(1, $data['metrics']['alerts']['value']);
        assertSame('ok', $data['monitor']['state']);

        // Tabulka: problém nahoře, pak pozornost; v pořádku chybí.
        assertSame(['Kavárna Dobrá', 'Ordinace'], array_column($data['rows'], 'name'));
        assertSame('PHP 7.4 EOL', $data['rows'][1]['state']['label']);
        assertSame('error', $data['rows'][1]['phpTone']);
        assertSame('MariaDB 10.6', $data['rows'][1]['db']);
        assertSame(6, $data['rows'][1]['updates']);
        assertSame('za 21 dní', str_replace("\u{a0}", ' ', $data['rows'][1]['service']));

        // Segmenty a klient.
        assertSame(['Kavárna Dobrá'], array_column($dashboard->build(['level' => 'problem'], null, $now)['rows'], 'name'));
        assertSame(['Ordinace'], array_column($dashboard->build(['level' => 'attention'], null, $now)['rows'], 'name'));
        assertSame(['Kavárna Dobrá'], array_column($dashboard->build(['client' => $clientId], null, $now)['rows'], 'name'));
        assertSame([], $dashboard->build(['q' => 'neexistuje'], null, $now)['rows']);
        assertSame(['Ordinace'], array_column($dashboard->build(['q' => 'ordinace.cz'], null, $now)['rows'], 'name'));

        // Klid: výpadek vyřešen → hero „Vše pod kontrolou" s posledním incidentem.
        $open = $f['alerts']->openOf($down, 'down');
        $f['alerts']->resolve((int) $open['id'], 'monitor', 'zpět', '2026-09-19 09:55:00');
        $f['sites']->update($down, ['status' => 'ok', 'consecutive_failures' => 0]);
        $f['db']->execute('UPDATE site_snapshots SET php_version = :php', ['php' => '8.3.1']);
        $f['db']->execute('UPDATE site_snapshots SET wp_update_version = NULL');
        $calm = $dashboard->build([], ['at' => '2026-09-19 09:55:00', 'ok' => true], $now);
        assertTrue($calm['hero']['calm']);
        assertContainsString('18minutový výpadek u webu Kavárna Dobrá', $calm['hero']['calmText']);
        assertSame(0, $calm['counts']['problem']);
    },
];
