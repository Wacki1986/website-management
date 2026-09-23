<?php

declare(strict_types=1);

/**
 * Změna adresy webu v Nastavení webu — web zůstává týž záznam (historie,
 * servis, API klíč), údaje vázané na doménu se vynulují k novému ověření.
 */

use App\Core\Events\EventLog;
use App\Core\Service\ServiceChecklists;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'změna adresy: stejný web, historie i klíč zůstanou, doména a certifikát se ověří znovu' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $sites = $kernel->sites();
        $id = $sites->create(['name' => 'Penam', 'url' => 'https://penam.cz']);
        $sites->setApiKey($id, 'mg_live_TESTKEY0000000000000000000000');
        $sites->update($id, ['ssl_valid_to' => '2026-12-01', 'ssl_issuer' => "Let's Encrypt", 'domain_expires_on' => '2027-03-01', 'domain_checked_at' => '2026-09-20 10:00:00']);
        $kernel->service()->addLog($id, ['performed_on' => '2026-09-01', 'kind' => 'small', 'description' => 'Aktualizace', 'minutes' => 30, 'status' => 'done', 'user_name' => 'Technik', 'checklist' => ServiceChecklists::build(['A'], [0])]);

        $response = kernelRequest($kernel, 'POST', '/weby/' . $id . '/nastaveni', [
            '_token' => $token,
            'url' => 'www.penam.cz/',
            'name' => 'Penam',
            'check_interval_min' => '15',
        ]);
        assertSame(302, $response->status());

        $site = $sites->find($id);
        assertSame('https://www.penam.cz', $site['url']);
        assertSame(null, $site['ssl_valid_to']);
        assertSame(null, $site['domain_checked_at']);
        assertSame('mg_live_TESTKEY0000000000000000000000', $sites->apiKey($site), 'API klíč se změnou adresy nemění');
        assertSame(1, count($kernel->service()->logs($id, 12, '2026-09-22')), 'Servis zůstává u webu');

        $event = $kernel->db()->selectOne('SELECT * FROM events WHERE site_id = :id AND kind = :kind ORDER BY id DESC', ['id' => $id, 'kind' => EventLog::KIND_SETTINGS]);
        assertContainsString('https://penam.cz → https://www.penam.cz', (string) $event['message']);
        assertContainsString('https://penam.cz → https://www.penam.cz', (string) $kernel->db()->selectOne("SELECT description FROM audit_log WHERE site_id = :id ORDER BY id DESC", ['id' => $id])['description']);

        Urls::reset();
    },

    'změna adresy: obsazená, http a prázdná adresa se odmítnou; beze změny adresy se nic nenuluje' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $sites = $kernel->sites();
        $id = $sites->create(['name' => 'Penam', 'url' => 'https://penam.cz']);
        $sites->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $sites->update($id, ['ssl_valid_to' => '2026-12-01']);

        foreach (['kavarnadobra.cz', 'http://penam.cz', ''] as $url) {
            kernelRequest($kernel, 'POST', '/weby/' . $id . '/nastaveni', ['_token' => $token, 'url' => $url, 'name' => 'Penam']);
            assertSame('https://penam.cz', $sites->find($id)['url'], 'Adresa „' . $url . '" se nesměla uložit');
        }

        // Uložení bez změny adresy (jen název) certifikát nenuluje.
        kernelRequest($kernel, 'POST', '/weby/' . $id . '/nastaveni', ['_token' => $token, 'url' => 'https://penam.cz/', 'name' => 'Penam s.r.o.']);
        $site = $sites->find($id);
        assertSame('Penam s.r.o.', $site['name']);
        assertSame('2026-12-01', $site['ssl_valid_to']);

        assertContainsString('name="url"', kernelRequest($kernel, 'GET', '/weby/' . $id . '/nastaveni')->body());

        Urls::reset();
    },
];
