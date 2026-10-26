<?php

declare(strict_types=1);

/**
 * Servisní protokol v PDF — data pro šablonu, aktualizace z monitoringu,
 * název souboru a stažení z historie servisů.
 */

use App\Core\Events\EventLog;
use App\Core\Service\ServiceChecklists;
use App\Core\Service\ServiceProtocol;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

/** @param array<string, string> $detail */
function protocolEvent(string $kind, array $detail): array
{
    return ['kind' => $kind, 'detail' => json_encode($detail)];
}

return [
    'protokol: jen hotové úkoly a poznámka, bez času a technika; název souboru bez www' => function (): void {
        $site = ['name' => 'penam.cz', 'url' => 'https://www.penam.cz', 'client_name' => 'PENAM, a.s.'];
        $log = [
            'id' => 42, 'kind' => 'medium', 'status' => 'done', 'performed_on' => '2026-10-07', 'description' => ' Plugin formuláře vyměněn. ',
            'minutes' => 90, 'user_name' => 'Technik',
            'checklist' => ServiceChecklists::encode(ServiceChecklists::build(['Aktualizace WordPressu', 'Kontrola zálohy', 'Čištění databáze'], [0, 2])),
        ];
        $data = ServiceProtocol::data($site, $log, [], ['name' => 'MEDIAGRAFIK', 'email' => 'studio@mediagrafik.cz', 'phone' => ''], '/logo.svg', '2026-10-08');

        assertSame([['Aktualizace WordPressu', 'Čištění databáze']], $data['taskRows']);
        assertSame('Plugin formuláře vyměněn.', $data['note']);
        assertSame('Střední servis', $data['kindLabel']);
        assertSame('7. 10. 2026', $data['date']);
        assertSame('8. 10. 2026', $data['issued']);
        assertFalse(isset($data['studioContact']), 'Protokol nemá patičku s kontaktem');
        assertSame('penam.cz · PENAM, a.s.', $data['subline']);
        assertSame('2', $data['tiles'][0]['value']);
        assertSame('beze změny', $data['tiles'][2]['value'], 'Bez aktualizace WordPressu');
        assertFalse(str_contains(json_encode($data, JSON_UNESCAPED_UNICODE), 'Technik') || isset($data['minutes']), 'Čas ani technik do protokolu nejdou');
        assertSame('servisni-protokol-penam-cz-2026-10-07.pdf', ServiceProtocol::filename($site, $log));

        // Název webu shodný s doménou se neopakuje; lichý úkol dostane prázdnou buňku; přeskočený servis je vidět.
        $odd = ServiceChecklists::encode(ServiceChecklists::build(['A', 'B', 'C'], [0, 1, 2]));
        $skipped = ServiceProtocol::data(['name' => 'www.penam.cz', 'client_name' => ''] + $site, ['status' => 'skipped', 'checklist' => $odd] + $log, [], ['name' => 'X', 'email' => '', 'phone' => ''], '', '2026-10-08');
        assertSame('', $skipped['subline']);
        assertSame([['A', 'B'], ['C', '']], $skipped['taskRows']);
        assertSame('Přeskočeno', $skipped['status']['label']);

        // Web mimo WordPress: jen dlaždice s úkoly.
        assertSame(1, count(ServiceProtocol::data(['platform' => 'other'] + $site, $log, [], ['name' => 'X', 'email' => '', 'phone' => ''], '', '2026-10-08')['tiles']));
    },

    'aktualizace z monitoringu: sloučené verze, nové a odebrané pluginy, okno do dopoledne' => function (): void {
        $events = [
            protocolEvent(EventLog::KIND_CORE, ['action' => 'updated', 'from' => '6.8.2', 'to' => '6.8.3']),
            protocolEvent(EventLog::KIND_PLUGIN, ['action' => 'updated', 'plugin' => 'Elementor', 'from' => '3.31.2', 'to' => '3.32.0']),
            protocolEvent(EventLog::KIND_PLUGIN, ['action' => 'updated', 'plugin' => 'Elementor', 'from' => '3.32.0', 'to' => '3.32.1']),
            // Aktualizace a návrat na původní verzi = žádná změna.
            protocolEvent(EventLog::KIND_PLUGIN, ['action' => 'updated', 'plugin' => 'Akismet', 'from' => '5.3', 'to' => '5.4']),
            protocolEvent(EventLog::KIND_PLUGIN, ['action' => 'updated', 'plugin' => 'Akismet', 'from' => '5.4', 'to' => '5.3']),
            protocolEvent(EventLog::KIND_PLUGIN, ['action' => 'installed', 'plugin' => 'FileBird', 'to' => '6.4.9']),
            protocolEvent(EventLog::KIND_PLUGIN, ['action' => 'removed', 'plugin' => 'Media Folder']),
            protocolEvent(EventLog::KIND_PLUGIN, ['action' => 'deactivated', 'plugin' => 'Hello Dolly']),
            protocolEvent(EventLog::KIND_SYSTEM, ['action' => 'php', 'from' => '8.2.29', 'to' => '8.3.26']),
        ];
        $updates = ServiceProtocol::updates($events);

        assertSame(['from' => '6.8.2', 'to' => '6.8.3'], $updates['core']);
        assertSame(['Elementor' => ['from' => '3.31.2', 'to' => '3.32.1']], $updates['plugins']);
        assertSame(['FileBird'], $updates['installed']);
        assertSame(['Media Folder'], $updates['removed']);

        $data = ServiceProtocol::data(['name' => 'X', 'url' => 'https://x.cz'], ['id' => 1, 'kind' => 'small', 'status' => 'done', 'performed_on' => '2026-09-24', 'description' => '', 'checklist' => null], $events, ['name' => 'X', 'email' => '', 'phone' => ''], '', '2026-10-01');
        assertSame(['WordPress', 'PHP', 'Elementor'], array_column($data['updateRows'], 'name'));
        assertSame('3', $data['updateCount']);
        assertSame(['Nově nainstalováno', 'Odebráno'], array_column($data['otherChanges'], 'label'));
        assertSame([['FileBird'], ['Media Folder']], array_column($data['otherChanges'], 'plugins'));
        assertSame("1\u{00A0}plugin", $data['otherChanges'][0]['count']);
        assertSame('1', $data['tiles'][1]['value']);
        assertSame("+ 1\u{00A0}nový plugin", $data['tiles'][1]['note']);
        assertSame('6.8.3', $data['tiles'][2]['value']);

        // Aktualizace z wp-adminu zjistí monitor až při další kontrole — okno sahá do dopoledne dalšího dne.
        assertSame(['2026-09-24 00:00:00', '2026-09-25 12:00:00'], ServiceProtocol::eventWindow('2026-09-24'));
        assertSame(['2026-12-31 00:00:00', '2027-01-01 12:00:00'], ServiceProtocol::eventWindow('2026-12-31'));
    },

    'protokol: stáří webu ke dni servisu a příští servis z plánu' => function (): void {
        $site = ['name' => 'X', 'url' => 'https://x.cz', 'launched_on' => '2021-03-15'];
        $log = ['id' => 1, 'kind' => 'small', 'status' => 'done', 'performed_on' => '2026-10-07', 'description' => 'Hotovo.', 'checklist' => null];
        $studio = ['name' => 'X', 'email' => '', 'phone' => ''];
        $plan = ['is_active' => 1, 'kind' => 'medium', 'frequency' => 'quarterly', 'next_date' => '2027-01-07'];
        $data = ServiceProtocol::data($site, $log, [], $studio, '', '2026-10-08', $plan);

        assertSame(['age' => "5\u{00A0}let", 'since' => 'spuštěn 15. 3. 2021'], $data['launched']);
        assertSame(['kind' => 'Střední servis', 'date' => '7. 1. 2027', 'rhythm' => 'opakuje se čtvrtletně'], $data['nextService'], 'Popis druhu servisu klientovi nic neslibuje');
        assertSame('', $data['updateCount'], 'Bez aktualizací se počet nepíše');

        // V prvním roce měsíce, v prvním měsíci „pár dní"; bez data nebo před spuštěním nic.
        assertSame("7\u{00A0}měsíců", ServiceProtocol::data(['launched_on' => '2026-03-01'] + $site, $log, [], $studio, '', '2026-10-08')['launched']['age']);
        assertSame('pár dní', ServiceProtocol::data(['launched_on' => '2026-10-01'] + $site, $log, [], $studio, '', '2026-10-08')['launched']['age']);
        assertSame(null, ServiceProtocol::data(['launched_on' => null] + $site, $log, [], $studio, '', '2026-10-08')['launched']);
        assertSame(null, ServiceProtocol::data(['launched_on' => '2026-11-01'] + $site, $log, [], $studio, '', '2026-10-08')['launched']);

        // Bez plánu, vypnutý plán i termín, který tímhle servisem teprve nastal: příští servis se nepíše.
        assertSame(null, ServiceProtocol::data($site, $log, [], $studio, '', '2026-10-08')['nextService']);
        assertSame(null, ServiceProtocol::data($site, $log, [], $studio, '', '2026-10-08', ['is_active' => 0] + $plan)['nextService']);
        assertSame(null, ServiceProtocol::data($site, $log, [], $studio, '', '2026-10-08', ['next_date' => '2026-10-07'] + $plan)['nextService']);
        assertSame('jednorázový servis', ServiceProtocol::data($site, $log, [], $studio, '', '2026-10-08', ['frequency' => 'once'] + $plan)['nextService']['rhythm']);
    },

    'stažení protokolu: PDF ke stažení z historie, aktualizace z okna, cizí zápis 404' => function (): void {
        [$kernel] = loggedInKernel();
        $siteId = $kernel->sites()->create(['name' => 'Penam', 'url' => 'https://penam.cz']);
        $otherId = $kernel->sites()->create(['name' => 'Jiný', 'url' => 'https://jiny.cz']);
        $logId = $kernel->service()->addLog($siteId, [
            'performed_on' => '2026-10-07', 'kind' => 'small', 'description' => 'Ověřeno.', 'minutes' => 30, 'status' => 'done', 'user_name' => 'Technik',
            'checklist' => ServiceChecklists::build(['Aktualizace WordPressu'], [0]),
        ]);

        // Ráno po servisu ano, den předtím ne.
        $events = $kernel->events();
        $inside = $events->record($siteId, EventLog::KIND_PLUGIN, 'ok', 'Elementor aktualizován', ['action' => 'updated', 'plugin' => 'Elementor', 'from' => '3.31', 'to' => '3.32']);
        $before = $events->record($siteId, EventLog::KIND_PLUGIN, 'ok', 'Yoast aktualizován', ['action' => 'updated', 'plugin' => 'Yoast SEO', 'from' => '25.0', 'to' => '25.1']);
        $kernel->db()->execute("UPDATE events SET created_at = '2026-10-08 09:30:00' WHERE id = :id", ['id' => $inside]);
        $kernel->db()->execute("UPDATE events SET created_at = '2026-10-06 18:00:00' WHERE id = :id", ['id' => $before]);
        [$from, $to] = ServiceProtocol::eventWindow('2026-10-07');
        assertSame(['Elementor' => ['from' => '3.31', 'to' => '3.32']], ServiceProtocol::updates($events->betweenTimes($siteId, $from, $to, ServiceProtocol::EVENT_KINDS))['plugins']);

        $page = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis')->body();
        assertContainsString('/weby/' . $siteId . '/servis/' . $logId . '/protokol', $page);

        $response = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/' . $logId . '/protokol');
        assertSame(200, $response->status());
        assertSame('application/pdf', $response->headers()['Content-Type'] ?? null);
        assertContainsString('servisni-protokol-penam-cz-2026-10-07.pdf', $response->headers()['Content-Disposition'] ?? '');
        assertTrue(str_starts_with($response->body(), '%PDF-'), 'Tělo odpovědi je PDF');

        assertSame(404, kernelRequest($kernel, 'GET', '/weby/' . $otherId . '/servis/' . $logId . '/protokol')->status());

        Urls::reset();
    },
];
