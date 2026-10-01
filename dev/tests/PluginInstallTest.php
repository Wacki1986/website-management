<?php

declare(strict_types=1);

/**
 * Instalace pluginu z knihovny — pravidla (`SiteActions::installable()`),
 * okno „Přidat z knihovny" na záložce Pluginy, „Nainstalovat na weby"
 * v Knihovně a akce `SiteActionController::installPlugins()` proti
 * falešnému webu (podepsaný POST, čerstvá data, historie, audit).
 */

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Kernel;
use App\Core\Sites\SiteActions;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/FakeInstance.php';
require_once __DIR__ . '/fixtures/logged-in-kernel.php';

const PI_KEY = 'mg_live_TESTKEY0000000000000000000000';

/**
 * @param array<string, string> $env
 * @param callable(string, string): void $test dostane adresu webu a soubor se stavem
 */
function withInstallableWp(int $port, array $env, callable $test): void
{
    $state = sys_get_temp_dir() . '/fake-wp-install-' . $port . '-' . getmypid();
    @unlink($state . '.installed');

    if (!FakeInstance::start($port, $env + ['FAKE_WP_KEY' => PI_KEY, 'FAKE_WP_STATE' => $state], 'fake-wp-site.php')) {
        skip('falešný web nenastartoval: ' . FakeInstance::lastError());
    }

    try {
        $test('http://127.0.0.1:' . $port, $state);
    } finally {
        FakeInstance::stop();
        @unlink($state . '.installed');
        Urls::reset();
    }
}

/**
 * Kernel s webem napojeným na falešný WordPress a pluginy v knihovně
 * (jen řádky v databázi — falešný web ZIP nestahuje).
 *
 * @param array<int, array{0: string, 1: string, 2?: string}> $library [soubor, název, vyžaduje WP]
 * @return array{0: Kernel, 1: string, 2: int} kernel, CSRF token, id webu
 */
function piKernel(string $siteUrl, array $library): array
{
    [$kernel, $token] = loggedInKernel('Správce', ['allow_insecure_sites' => true]);

    foreach ($library as $entry) {
        $kernel->db()->insert('plugin_library', [
            'slug' => dirname($entry[0]),
            'file' => $entry[0],
            'name' => $entry[1],
            'version' => '2.1.0',
            'author' => 'MEDIAGRAFIK',
            'requires_wp' => $entry[2] ?? '',
            'requires_php' => '',
            'size' => 1000,
            'uploaded_by' => 'Správce',
            'uploaded_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => $siteUrl]);
    $kernel->sites()->setApiKey($siteId, PI_KEY);
    $kernel->monitor()->checkOne($kernel->sites()->find($siteId));

    return [$kernel, $token, $siteId];
}

/** Požadavek ze skriptu (`plugin-install.js`) — čeká JSON. @param array<string, mixed> $body */
function piJson(Kernel $kernel, string $token, int $siteId, array $body): Response
{
    return $kernel->handle(new Request(
        method: 'POST',
        path: '/weby/' . $siteId . '/pluginy/instalovat',
        query: [],
        body: $body + ['_token' => $token],
        files: [],
        cookies: [],
        headers: ['accept' => 'application/json', 'x-requested-with' => 'XMLHttpRequest'],
        server: ['SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'],
    ));
}

/** @return array<string, mixed>|null */
function piPlugin(Kernel $kernel, int $siteId, string $file): ?array
{
    return $kernel->db()->selectOne('SELECT * FROM site_plugins WHERE site_id = :id AND file = :file', ['id' => $siteId, 'file' => $file]);
}

const PI_FLIPBOOK = 'flipbook-pro/flipbook-pro.php';

return [
    'pravidla: nabízí jen chybějící pluginy, u nesplnitelných důvod' => function (): void {
        $library = [
            ['file' => PI_FLIPBOOK, 'requires_wp' => '6.0', 'requires_php' => '7.4'],
            ['file' => 'elementor/elementor.php', 'requires_wp' => '', 'requires_php' => ''],
            ['file' => 'new-blocks/new-blocks.php', 'requires_wp' => '7.0', 'requires_php' => ''],
            ['file' => 'modern-php/modern-php.php', 'requires_wp' => '', 'requires_php' => '8.2'],
            ['file' => 'forms/forms-pro.php', 'requires_wp' => '', 'requires_php' => ''],
        ];
        $plugins = [
            ['file' => 'elementor/elementor.php', 'name' => 'Elementor'],
            ['file' => 'forms/forms.php', 'name' => 'Forms Lite'],
            ['file' => 'hello.php', 'name' => 'Hello Dolly'],
        ];

        $installable = SiteActions::installable($library, $plugins, ['wp_version' => '6.8.2', 'php_version' => '8.1.4']);

        assertSame([PI_FLIPBOOK, 'new-blocks/new-blocks.php', 'modern-php/modern-php.php', 'forms/forms-pro.php'], array_keys($installable), 'Elementor na webu je');
        assertSame(null, $installable[PI_FLIPBOOK]);
        assertContainsString('WordPress 7.0, web má 6.8.2', (string) $installable['new-blocks/new-blocks.php']);
        assertContainsString('PHP 8.2, web má 8.1.4', (string) $installable['modern-php/modern-php.php']);
        assertContainsString('složka forms (plugin Forms Lite)', (string) $installable['forms/forms-pro.php']);

        // Bez dat z webu se požadavky nehodnotí (plugin na webu to zkontroluje sám).
        assertSame(null, SiteActions::installable($library, [], null)['new-blocks/new-blocks.php']);
    },

    'záložka Pluginy: Monitor starší než 1.8.0 — tlačítko vypnuté s důvodem' => function (): void {
        withInstallableWp(8251, ['FAKE_WP_RELEASE' => '1.7.0'], function (string $url): void {
            [$kernel, , $siteId] = piKernel($url, [[PI_FLIPBOOK, 'FlipBook Pro']]);
            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();

            assertContainsString('MEDIAGRAFIK Monitor 1.8.0 (web má 1.7.0)', $html);
            assertFalse(str_contains($html, 'data-confirm="plugin-install"'), 'Okno se otevřít nesmí');
            assertFalse(str_contains($html, 'data-plugin-install'));
        });
    },

    'záložka Pluginy: prázdná knihovna — tlačítko vypnuté s důvodem' => function (): void {
        withInstallableWp(8252, ['FAKE_WP_RELEASE' => '1.8.0'], function (string $url): void {
            [$kernel, , $siteId] = piKernel($url, []);
            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();

            assertContainsString('Knihovna pluginů je prázdná', $html);
            assertFalse(str_contains($html, 'data-confirm="plugin-install"'));
        });
    },

    'záložka Pluginy: okno nabídne chybějící plugin, nainstalovaný vynechá, nesplnitelný šedě' => function (): void {
        withInstallableWp(8253, ['FAKE_WP_RELEASE' => '1.8.0'], function (string $url): void {
            [$kernel, , $siteId] = piKernel($url, [
                [PI_FLIPBOOK, 'FlipBook Pro'],
                ['elementor/elementor.php', 'Elementor'],
                ['new-blocks/new-blocks.php', 'New Blocks', '7.0'],
            ]);
            // Hledání zúží tabulku, okno ale počítá se všemi pluginy webu.
            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy', [], ['q' => 'woo'])->body();

            assertContainsString('data-confirm="plugin-install"', $html);
            assertContainsString('action="/weby/' . $siteId . '/pluginy/instalovat" data-plugin-install', $html);
            assertContainsString('data-file="' . PI_FLIPBOOK . '"', $html);
            assertFalse(str_contains($html, 'data-file="elementor/elementor.php"'), 'Elementor na webu je');
            assertFalse(str_contains($html, 'data-file="new-blocks/new-blocks.php"'), 'Nesplnitelný plugin nejde zaškrtnout');
            assertContainsString('WordPress 7.0, web má 6.8.2', $html);
        });
    },

    'akce: nainstaluje a aktivuje, pak čerstvá data, historie a audit' => function (): void {
        withInstallableWp(8254, ['FAKE_WP_RELEASE' => '1.8.0'], function (string $url, string $state): void {
            [$kernel, $token, $siteId] = piKernel($url, [[PI_FLIPBOOK, 'FlipBook Pro'], ['elementor/elementor.php', 'Elementor']]);

            // Elementor na webu je — na web se vůbec nepošle.
            $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/pluginy/instalovat', [
                '_token' => $token,
                'plugins' => [PI_FLIPBOOK, 'elementor/elementor.php'],
                'activate' => '1',
            ]);

            assertSame(302, $response->status());
            $installed = (array) json_decode((string) @file_get_contents($state . '.installed'), true);
            assertSame([PI_FLIPBOOK], array_keys($installed));

            $plugin = piPlugin($kernel, $siteId, PI_FLIPBOOK);
            assertTrue($plugin !== null, 'Plugin po instalaci chybí v tabulce');
            assertSame(1, (int) $plugin['is_active']);

            $event = $kernel->db()->selectOne("SELECT * FROM events WHERE site_id = :id AND message LIKE 'FlipBook Pro nainstalován%'", ['id' => $siteId]);
            assertTrue($event !== null, 'Historie nemá záznam o instalaci');

            $audit = $kernel->db()->selectOne("SELECT * FROM audit_log WHERE action = 'plugin-instalace'");
            assertTrue($audit !== null);
            assertSame(1, (int) $audit['success']);
            assertContainsString('FlipBook Pro 2.1.0 (aktivní)', (string) $audit['description']);
            assertFalse(str_contains((string) $audit['description'], 'Elementor'));

            // Nainstalovaný plugin se v okně už nenabízí.
            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
            assertFalse(str_contains($html, 'data-file="' . PI_FLIPBOOK . '"'));
            assertContainsString('Všechny pluginy z knihovny už na webu jsou', $html);
        });
    },

    'akce ze skriptu: JSON po jednom, data z webu až po posledním, selhání do historie' => function (): void {
        withInstallableWp(8255, ['FAKE_WP_RELEASE' => '1.8.0'], function (string $url): void {
            [$kernel, $token, $siteId] = piKernel($url, [[PI_FLIPBOOK, 'FlipBook Pro'], ['broken-pro/broken-pro.php', 'Broken Pro']]);

            $broken = json_decode(piJson($kernel, $token, $siteId, ['plugin' => 'broken-pro/broken-pro.php', 'refresh' => '0'])->body(), true);
            assertTrue($broken['ok'] === true);
            assertSame('failed', $broken['items'][0]['status']);
            assertContainsString('Stažení balíčku selhalo', (string) $broken['items'][0]['message']);
            assertTrue($kernel->db()->selectOne("SELECT * FROM events WHERE site_id = :id AND message LIKE 'Broken Pro se nepodařilo nainstalovat%'", ['id' => $siteId]) !== null);

            // Bez aktivace a bez refresh: nainstalovaný, vypnutý, data z webu se ještě nečtou.
            $first = json_decode(piJson($kernel, $token, $siteId, ['plugin' => PI_FLIPBOOK, 'activate' => '0', 'refresh' => '0'])->body(), true);
            assertSame('installed', $first['items'][0]['status']);
            assertFalse($first['items'][0]['active']);
            assertSame(null, piPlugin($kernel, $siteId, PI_FLIPBOOK), 'Bez refresh se data z webu nenačítají');

            // Poslední položka (refresh=1): čerstvá data z webu.
            $last = json_decode(piJson($kernel, $token, $siteId, ['plugin' => PI_FLIPBOOK, 'refresh' => '1'])->body(), true);
            assertSame('skipped', $last['items'][0]['status']);
            assertSame(0, (int) piPlugin($kernel, $siteId, PI_FLIPBOOK)['is_active']);

            // Plugin na webu už je / v knihovně není — JSON chyba, na web nic neodejde.
            $again = piJson($kernel, $token, $siteId, ['plugin' => PI_FLIPBOOK]);
            assertSame(422, $again->status());
            assertContainsString('který na webu ještě není', (string) json_decode($again->body(), true)['error']);
            assertSame(422, piJson($kernel, $token, $siteId, ['plugin' => 'akismet/akismet.php'])->status());
        });
    },

    'akce: Monitor starší než 1.8.0 — požadavek na web neodejde' => function (): void {
        withInstallableWp(8256, ['FAKE_WP_RELEASE' => '1.7.0'], function (string $url, string $state): void {
            [$kernel, $token, $siteId] = piKernel($url, [[PI_FLIPBOOK, 'FlipBook Pro']]);

            $response = piJson($kernel, $token, $siteId, ['plugin' => PI_FLIPBOOK]);

            assertSame(422, $response->status());
            assertContainsString('1.8.0', (string) json_decode($response->body(), true)['error']);
            assertFalse(is_file($state . '.installed'), 'Falešný web instalaci dostat nesměl');
        });
    },

    'Knihovna: ikona u pluginu, okno s weby, kde chybí, nenapojený web s důvodem' => function (): void {
        withInstallableWp(8257, ['FAKE_WP_RELEASE' => '1.8.0'], function (string $url): void {
            [$kernel, , $siteId] = piKernel($url, [[PI_FLIPBOOK, 'FlipBook Pro'], ['elementor/elementor.php', 'Elementor']]);
            $kernel->sites()->create(['name' => 'Pekárna', 'url' => 'https://pekarna.test']);
            $html = kernelRequest($kernel, 'GET', '/knihovna')->body();

            assertContainsString('data-confirm="library-install-flipbook-pro"', $html);
            assertContainsString('id="library-install-flipbook-pro"', $html);
            assertContainsString('data-action="/weby/' . $siteId . '/pluginy/instalovat" data-file="' . PI_FLIPBOOK . '" data-name="Kavárna"', $html);
            assertContainsString('Web zatím nemá napojený plugin MEDIAGRAFIK Monitor.', $html);

            // Elementor chybí jen na nenapojeném webu — okno jen s důvodem, bez tlačítka.
            assertContainsString('id="library-install-elementor"', $html);
            assertFalse(str_contains($html, 'data-file="elementor/elementor.php"'));
        });
    },
];
