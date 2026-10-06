<?php

declare(strict_types=1);

/**
 * Web jiný než WordPress: přidání bez API klíče, detail bez záložek
 * z pluginu, přepnutí systému v Nastavení (úklid dat z pluginu a jejich
 * alertů, nebo nový klíč), seznam a report.
 */

use App\Core\Sites\SiteRepository;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'jiný systém: přidá se bez klíče a modulů, detail nemá záložky pluginu ani wp-admin' => function (): void {
        [$kernel, $token] = loggedInKernel();

        $response = kernelRequest($kernel, 'POST', '/weby/pridat', ['_token' => $token, 'url' => 'obchod-novak.cz', 'name' => 'Obchod', 'platform' => 'other', 'platform_name' => 'Shoptet']);
        $site = $kernel->sites()->findByUrl('https://obchod-novak.cz');
        assertSame('/weby/' . $site['id'], (string) ($response->headers()['Location'] ?? ''));
        assertSame(SiteRepository::PLATFORM_OTHER, $site['platform']);
        assertSame('Shoptet', $site['platform_name']);
        assertSame(null, $kernel->sites()->apiKey($site), 'Jiný systém nemá API klíč — monitor plugin nevolá');
        assertSame(0, (int) $kernel->db()->scalar('SELECT COUNT(*) FROM site_modules WHERE site_id = :id', ['id' => $site['id']]));

        $detail = kernelRequest($kernel, 'GET', '/weby/' . $site['id'])->body();
        assertFalse(str_contains($detail, '/pluginy"'), 'Záložka Pluginy nemá u jiného systému co ukázat');
        assertFalse(str_contains($detail, '>wp-admin<'));
        assertContainsString('Shoptet', $detail);
        assertContainsString('Shoptet', kernelRequest($kernel, 'GET', '/weby')->body(), 'Seznam ukazuje systém ve sloupci WP');

        // S vyplněnou administrací se tlačítko ukáže pod obecným názvem.
        $kernel->sites()->update((int) $site['id'], ['admin_url' => 'https://admin.shoptet.cz']);
        assertContainsString('href="https://admin.shoptet.cz"', kernelRequest($kernel, 'GET', '/weby/' . $site['id'])->body());

        // WordPress dál dostane klíč a jde do Nastavení.
        kernelRequest($kernel, 'POST', '/weby/pridat', ['_token' => $token, 'url' => 'kavarnadobra.cz', 'platform' => 'wordpress', 'platform_name' => 'nesmysl']);
        $wp = $kernel->sites()->findByUrl('https://kavarnadobra.cz');
        assertTrue($kernel->sites()->apiKey($wp) !== null);
        assertSame('', $wp['platform_name'], 'U WordPressu se název systému neukládá');

        Urls::reset();
    },

    'přepnutí na jiný systém smaže klíč, data z pluginu a jejich alerty; zpět na WordPress vygeneruje klíč' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $sites = $kernel->sites();
        $id = $sites->create(['name' => 'Penam', 'url' => 'https://penam.cz']);
        $sites->setApiKey($id, 'mg_live_TESTKEY0000000000000000000000');
        $kernel->db()->insert('site_snapshots', ['site_id' => $id, 'fetched_at' => '2026-10-01 10:00:00', 'payload' => '{}', 'wp_version' => '6.8']);
        $kernel->alerts()->open($id, 'updates', 'warning', 'Čekající aktualizace', '', '');
        $kernel->alerts()->open($id, 'down', 'error', 'Web nedostupný', '', '');
        $save = static fn (array $extra): mixed => kernelRequest($kernel, 'POST', '/weby/' . $id . '/nastaveni', ['_token' => $token, 'url' => 'https://penam.cz', 'name' => 'Penam'] + $extra);

        // Starší odeslání bez volby systému nic nepřepíná.
        $save([]);
        assertSame(SiteRepository::PLATFORM_WORDPRESS, $sites->find($id)['platform']);

        $save(['platform' => 'other', 'platform_name' => 'Webnode']);
        $site = $sites->find($id);
        assertSame('Webnode', $site['platform_name']);
        assertSame(null, $sites->apiKey($site));
        assertSame(null, $kernel->snapshots()->snapshot($id));
        assertSame(null, $kernel->alerts()->openOf($id, 'updates'), 'Alert z dat pluginu se zavře');
        assertTrue($kernel->alerts()->openOf($id, 'down') !== null, 'Výpadek se hlídá dál');
        assertFalse(str_contains(kernelRequest($kernel, 'GET', '/weby/' . $id . '/nastaveni')->body(), 'Vygenerovat nový'), 'Karta API klíče u jiného systému není');

        assertSame(302, $save(['platform' => 'wordpress'])->status());
        $site = $sites->find($id);
        assertSame('', $site['platform_name']);
        assertTrue($sites->apiKey($site) !== null);
        assertContainsString('Nový klíč se ukazuje jen teď', kernelRequest($kernel, 'GET', '/weby/' . $id . '/nastaveni')->body());

        Urls::reset();
    },

    'report jiného systému: místo aktualizací servisní zásahy, v příloze systém' => function (): void {
        [$kernel] = loggedInKernel();
        $id = $kernel->sites()->create(['name' => 'Obchod', 'url' => 'https://obchod-novak.cz', 'platform' => 'other', 'platform_name' => 'Shoptet']);

        $summary = $kernel->reportBuilder()->build($kernel->sites()->findWithSnapshot($id), '2026-09-01', '2026-09-30', 'Září 2026', '2026-10-01');
        assertFalse($summary['updates']['applicable']);
        assertSame('Shoptet', $summary['technical']['platform']);

        $text = $kernel->reportRenderer()->text($summary);
        assertContainsString('Servisní zásahy: 0', $text);
        assertFalse(str_contains($text, 'Provedené aktualizace'));
        assertFalse(str_contains($summary['summaryLine'], 'aktualiz'));

        Urls::reset();
    },
];
