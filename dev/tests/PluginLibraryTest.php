<?php

declare(strict_types=1);

/**
 * Knihovna pluginů: rozbor ZIPu, jen nejnovější verze na disku, seznam pro
 * web ověřený otiskem API klíče, ZIP jen s podpisem pro daný web a nabídka
 * aktualizace na záložce Pluginy.
 */

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Kernel;
use App\Core\Plugin\PluginLibrary;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

const LIB_KEY = 'mg_live_LIBRARYKEY00000000000000000000';

/**
 * ZIP pluginu tak, jak ho vydává autor: jedna složka, v ní hlavní soubor
 * s hlavičkou. `$extraRoot` přidá druhou složku v kořeni (chybný ZIP).
 */
function libZip(string $version, string $name = 'FlipBook Pro', bool $withHeader = true, string $extraRoot = ''): string
{
    $path = sys_get_temp_dir() . '/lib-' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('flipbook-pro/flipbook-pro.php', $withHeader
        ? "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n * Author: <a href=\"https://tnc.cz\">ThemeNcode</a>\n * Requires PHP: 7.4\n */\n"
        : "<?php\n// bez hlavičky\n");
    $zip->addFromString('flipbook-pro/includes/helper.php', "<?php\n/* Plugin Name: Tohle není hlavní soubor */\n");

    if ($extraRoot !== '') {
        $zip->addFromString($extraRoot . '/soubor.txt', 'x');
    }

    $zip->close();

    return $path;
}

/** Požadavek pluginu z webu — s otiskem klíče v hlavičce. */
function libRequest(Kernel $kernel, string $path, array $query = [], array $headers = []): App\Core\Http\Response
{
    return $kernel->handle(new Request(
        method: 'GET',
        path: $path,
        query: $query,
        body: [],
        files: [],
        cookies: [],
        headers: $headers + ['accept' => 'application/json'],
        server: ['SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'],
    ));
}

return [
    'rozbor ZIPu: složka, hlavní soubor a hlavička jako ve WordPressu' => function (): void {
        $info = PluginLibrary::inspect(libZip('12.6.3'));
        assertSame('flipbook-pro', $info['slug']);
        assertSame('flipbook-pro/flipbook-pro.php', $info['file'], 'Hlavní soubor je přímo ve složce, ne v podsložce');
        assertSame('FlipBook Pro', $info['name']);
        assertSame('12.6.3', $info['version']);
        assertSame('ThemeNcode', $info['author']);
        assertSame('7.4', $info['requires_php']);

        assertContainsString('jednu složku', assertThrows(HttpException::class, static fn () => PluginLibrary::inspect(libZip('1.0', extraRoot: 'jina-slozka')))->getMessage());
        assertContainsString('Plugin Name', assertThrows(HttpException::class, static fn () => PluginLibrary::inspect(libZip('1.0', withHeader: false)))->getMessage());
    },

    'nahrání: novější verze nahradí starší, nižší se odmítne, na disku jen jeden ZIP' => function (): void {
        [$kernel] = loggedInKernel();
        $library = $kernel->pluginLibrary();
        $directory = $kernel->storagePath(PluginLibrary::DIRECTORY);

        foreach (glob($directory . '/*') ?: [] as $old) {
            @unlink($old);
        }

        $first = $library->import(libZip('12.5.0'), 'Technik');
        assertSame(null, $first['previous']);
        assertSame('12.5.0', $library->find('flipbook-pro')['version']);

        $second = $library->import(libZip('12.6.3'), 'Technik');
        assertSame('12.5.0', $second['previous']);
        assertSame('12.6.3', $library->find('flipbook-pro')['version']);
        assertSame(['flipbook-pro.zip'], array_map('basename', glob($directory . '/*') ?: []), 'Starší verze na serveru nezůstává');

        assertContainsString('novější verze 12.6.3', assertThrows(HttpException::class, static fn () => $library->import(libZip('11.0.0'), 'Technik'))->getMessage());
        assertSame(['flipbook-pro/flipbook-pro.php' => '12.6.3'], $library->versions());

        Urls::reset();
    },

    'seznam pro web jen s otiskem klíče; ZIP jen s podpisem pro daný web' => function (): void {
        [$kernel] = loggedInKernel();
        $siteId = $kernel->sites()->create(['name' => 'Penam', 'url' => 'https://penam.cz']);
        $kernel->sites()->setApiKey($siteId, LIB_KEY);
        $kernel->pluginLibrary()->import(libZip('12.6.3'), 'Technik');

        $denied = libRequest($kernel, '/plugin/mediagrafik-monitor/knihovna.json', [], ['x-mg-key-hash' => hash('sha256', 'mg_live_JINY')]);
        assertSame(403, $denied->status());

        $response = libRequest($kernel, '/plugin/mediagrafik-monitor/knihovna.json', [], ['x-mg-key-hash' => hash('sha256', LIB_KEY)]);
        assertSame(200, $response->status());
        $entry = json_decode($response->body(), true)['plugins']['flipbook-pro/flipbook-pro.php'] ?? null;
        assertTrue(is_array($entry), 'Plugin v seznamu chybí');
        assertSame('12.6.3', $entry['version']);
        assertContainsString('/plugin/mediagrafik-monitor/knihovna/flipbook-pro.zip?web=' . $siteId . '&podpis=', $entry['package']);

        parse_str((string) parse_url($entry['package'], PHP_URL_QUERY), $query);
        $zip = libRequest($kernel, '/plugin/mediagrafik-monitor/knihovna/flipbook-pro.zip', $query);
        assertSame(200, $zip->status());
        assertSame('application/zip', $zip->headers()['Content-Type'] ?? '');

        // Podpis cizího webu, nebo žádný — jako by ZIP neexistoval.
        assertSame(404, libRequest($kernel, '/plugin/mediagrafik-monitor/knihovna/flipbook-pro.zip', ['web' => $siteId + 1, 'podpis' => $query['podpis']])->status());
        assertSame(404, libRequest($kernel, '/plugin/mediagrafik-monitor/knihovna/flipbook-pro.zip', ['web' => $siteId])->status());

        Urls::reset();
    },

    'stránka knihovny a záložka Pluginy: kde plugin je a kde se nabídne aktualizace' => function (): void {
        [$kernel] = loggedInKernel();
        $kernel->pluginLibrary()->import(libZip('12.6.3'), 'Technik');
        $now = date('Y-m-d H:i:s');
        $sites = [];

        // Dva weby se starší verzí: jeden s Monitorem 1.4.0 (umí knihovnu), druhý s 1.3.1.
        foreach (['1.4.0' => 'Penam', '1.3.1' => 'Kavárna'] as $monitor => $name) {
            $id = $kernel->sites()->create(['name' => $name, 'url' => 'https://' . strtolower($name) . '.cz']);
            $kernel->sites()->setApiKey($id, LIB_KEY . $id);
            $kernel->db()->insert('site_snapshots', ['site_id' => $id, 'fetched_at' => $now, 'plugin_version' => $monitor, 'payload' => '{}']);
            $kernel->db()->insert('site_plugins', ['site_id' => $id, 'file' => 'flipbook-pro/flipbook-pro.php', 'name' => 'FlipBook Pro', 'version' => '11.20.0', 'is_active' => 1, 'has_update' => 0, 'first_seen_at' => $now, 'last_seen_at' => $now]);
            $sites[$monitor] = $id;
        }

        $page = kernelRequest($kernel, 'GET', '/knihovna')->body();
        assertContainsString('FlipBook Pro', $page);
        assertContainsString("2\u{00A0}weby čekají na aktualizaci", $page);
        assertContainsString('/knihovna/flipbook-pro/stahnout', $page);

        // „Aktualizovat všude": okno zařadí jen web, který si ZIP umí stáhnout,
        // druhý přeskočí s důvodem.
        assertContainsString('data-confirm="library-update-flipbook-pro"', $page);
        assertContainsString('data-library-target data-action="/weby/' . $sites['1.4.0'] . '/pluginy/aktualizovat" data-file="flipbook-pro/flipbook-pro.php" data-name="Penam"', $page);
        assertFalse(str_contains($page, '/weby/' . $sites['1.3.1'] . '/pluginy/aktualizovat'), 'Web s Monitorem < 1.4.0 se do „všude" zařadit nemá');
        assertContainsString('<span class="progress-list__note">Pluginy z knihovny umí web stáhnout až s MEDIAGRAFIK Monitorem 1.4.0 (web má 1.3.1)', $page);
        assertContainsString('11.20.0 → 12.6.3', $page);
        assertContainsString("Aktualizovat na 1\u{00A0}webu", $page);

        $current = kernelRequest($kernel, 'GET', '/weby/' . $sites['1.4.0'] . '/pluginy')->body();
        assertContainsString('name="plugin" value="flipbook-pro/flipbook-pro.php" class="btn btn--secondary btn--icon"', $current);
        assertContainsString('12.6.3', $current);

        $older = kernelRequest($kernel, 'GET', '/weby/' . $sites['1.3.1'] . '/pluginy')->body();
        assertFalse(str_contains($older, 'value="flipbook-pro/flipbook-pro.php" class="btn btn--secondary btn--icon"'), 'Web s Monitorem < 1.4.0 si ZIP z knihovny stáhnout neumí');

        // Uložený počet (metrika, výpis webů, dashboard) počítá totéž co
        // tabulka — i verzi z knihovny, o které WordPress bez licence neví.
        $kernel->snapshots()->recountAll();
        $count = static fn (int $siteId): int => (int) $kernel->db()->scalar('SELECT plugins_updates FROM site_snapshots WHERE site_id = :id', ['id' => $siteId]);
        assertSame(1, $count($sites['1.4.0']), 'Aktualizace z knihovny chybí v počtu');
        assertSame(0, $count($sites['1.3.1']), 'Web, který si z knihovny stáhnout neumí, ji nemá mít v počtu');

        Urls::reset();
    },

    'knihovna u víc webů: aktuální schované, nesledovaný přeskočený, bez cílů tlačítko vypnuté' => function (): void {
        [$kernel] = loggedInKernel();
        $kernel->pluginLibrary()->import(libZip('12.6.3'), 'Technik');
        $now = date('Y-m-d H:i:s');

        // Šest webů s aktuální verzí a jeden se starší, na kterém je plugin nesledovaný.
        foreach (['Alfa', 'Beta', 'Gama', 'Delta', 'Epsilon', 'Zeta', 'Omega'] as $name) {
            $id = $kernel->sites()->create(['name' => $name, 'url' => 'https://' . strtolower($name) . '.cz']);
            $kernel->sites()->setApiKey($id, LIB_KEY . $id);
            $kernel->db()->insert('site_snapshots', ['site_id' => $id, 'fetched_at' => $now, 'plugin_version' => '1.5.5', 'payload' => '{}']);
            $kernel->db()->insert('site_plugins', ['site_id' => $id, 'file' => 'flipbook-pro/flipbook-pro.php', 'name' => 'FlipBook Pro',
                'version' => $name === 'Omega' ? '12.0.0' : '12.6.3', 'is_active' => 1, 'has_update' => 0, 'updates_ignored' => $name === 'Omega' ? 1 : 0,
                'first_seen_at' => $now, 'last_seen_at' => $now]);
        }

        $page = kernelRequest($kernel, 'GET', '/knihovna')->body();
        assertContainsString("data-library-more aria-label=\"Ukázat + 6\u{00A0}aktuálních\"", $page);
        assertSame(6, substr_count($page, 'data-library-hidden hidden'), 'Aktuální weby mají být schované');
        assertContainsString('Aktualizovat všude web přeskočí: Plugin je na webu nesledovaný', $page);
        assertFalse(str_contains($page, 'data-confirm="library-update-flipbook-pro"'), 'Bez webu k aktualizaci se okno nenabízí');
        assertContainsString('aria-label="Aktualizovat všude teď nejde"', $page);

        Urls::reset();
    },

    'MEDIAGRAFIK Monitor ve vlastní kartě: „všude" i pro složku s jiným názvem, bez koše' => function (): void {
        [$kernel] = loggedInKernel();
        $dir = $kernel->storagePath('plugin');
        @mkdir($dir, 0775, true);
        $now = date('Y-m-d H:i:s');
        $sites = [];

        try {
            // Bez vydané verze se karta neukazuje.
            @unlink($dir . '/plugin-info.json');
            assertFalse(str_contains(kernelRequest($kernel, 'GET', '/knihovna')->body(), 'library-update-mediagrafik-monitor'));

            file_put_contents($dir . '/plugin-info.json', json_encode(['name' => 'MEDIAGRAFIK Monitor', 'version' => '1.6.0', 'last_updated' => $now]));

            // Penam má Monitor ve standardní složce, Kavárna ze ZIPu s verzí v názvu, Alfa už aktuální.
            foreach (['Penam' => ['mediagrafik-monitor', '1.5.5'], 'Kavárna' => ['mediagrafik-monitor-1.5.0', '1.5.0'], 'Alfa' => ['mediagrafik-monitor', '1.6.0']] as $name => [$folder, $version]) {
                $id = $kernel->sites()->create(['name' => $name, 'url' => 'https://' . strtolower(str_replace('á', 'a', $name)) . '.cz']);
                $kernel->sites()->setApiKey($id, LIB_KEY . $id);
                $kernel->db()->insert('site_snapshots', ['site_id' => $id, 'fetched_at' => $now, 'plugin_version' => $version, 'payload' => '{}']);
                $kernel->db()->insert('site_plugins', ['site_id' => $id, 'file' => $folder . '/mediagrafik-monitor.php', 'name' => 'MEDIAGRAFIK Monitor',
                    'version' => $version, 'is_active' => 1, 'has_update' => 0, 'first_seen_at' => $now, 'last_seen_at' => $now]);
                $sites[$name] = $id;
            }

            $page = kernelRequest($kernel, 'GET', '/knihovna')->body();
            assertContainsString('data-confirm="library-update-mediagrafik-monitor"', $page);
            assertContainsString("2\u{00A0}weby čekají na aktualizaci", $page);
            assertContainsString('data-action="/weby/' . $sites['Penam'] . '/pluginy/aktualizovat" data-file="mediagrafik-monitor/mediagrafik-monitor.php"', $page);
            assertContainsString('data-action="/weby/' . $sites['Kavárna'] . '/pluginy/aktualizovat" data-file="mediagrafik-monitor-1.5.0/mediagrafik-monitor.php"', $page);
            assertContainsString('1.5.5 → 1.6.0', $page);
            assertFalse(str_contains($page, '/knihovna/mediagrafik-monitor/smazat'), 'Monitor z knihovny odebrat nejde');
        } finally {
            @unlink($dir . '/plugin-info.json');
        }

        Urls::reset();
    },
];
