<?php

declare(strict_types=1);

/**
 * Značka instance (Nastavení → Základní nastavení) — aplikace i plugin pod
 * jménem toho, kdo správu provozuje, a nikde natvrdo jméno jedné firmy.
 */

use App\Core\Brand\Brand;
use App\Core\Brand\BrandAssets;
use App\Core\Brand\IconSet;
use App\Core\Plugin\PluginDistribution;
use App\Core\Sites\SiteActions;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

/**
 * Neutrální balíček pluginu, jak ho dělá `build-plugin.ps1`: složka
 * `monitor/`, hlavní soubor s hlavičkou, `brand.php` a něco v podsložce.
 */
function brandBaseZip(string $dir, string $version): void
{
    @mkdir($dir, 0775, true);
    $zip = new ZipArchive();
    $zip->open($dir . '/monitor-' . $version . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('monitor/monitor.php', "<?php\n/**\n * Plugin Name: Správa webů – Monitor\n * Plugin URI:\n * Description: neutrální\n * Version: {$version}\n * Author: Správa webů\n * Author URI:\n * Text Domain: monitor\n */\nrequire_once __DIR__ . '/brand.php';\n");
    $zip->addFromString('monitor/brand.php', "<?php\ndefine('MG_MONITOR_NAME', 'Správa webů – Monitor');\n");
    $zip->addFromString('monitor/includes/class-mg-monitor.php', "<?php\n// kód\n");
    $zip->close();
    file_put_contents($dir . '/plugin-info.json', json_encode(['version' => $version, 'sections' => ['changelog' => '<pre>x</pre>']]));
}

/** Kernel s vlastním slugem pluginu a vyplněným Studiem. */
function brandKernel(): array
{
    [$kernel, $token] = loggedInKernel('Technik', ['plugin' => ['slug' => 'test-monitor']]);
    $kernel->brand()->save(['name' => 'Testovací studio', 'web' => 'https://studio.test', 'email' => 'ahoj@studio.test', 'phone' => '+420 123', 'plugin' => '']);

    return [$kernel, $token];
}

return [
    'bez Studia: neutrální název pluginu, výchozí slug, User-Agent bez webu' => function (): void {
        $brand = new Brand(null, [], new BrandAssets(sys_get_temp_dir() . '/brand-prazdna', WWW_ROOT . '/assets'));

        assertSame('', $brand->name());
        assertSame('Správa webů – Monitor', $brand->pluginName());
        assertSame(Brand::DEFAULT_PLUGIN_SLUG, $brand->pluginSlug());
        assertSame(Brand::DEFAULT_PLUGIN_SLUG . '/v1', $brand->pluginNamespace());
        assertSame('SpravaWebu/1.0', $brand->userAgent());
        assertSame('Správa webů', $brand->twoFactorIssuer());

        // Slug je složka na webu a kus adresy — nic jiného než a-z, 0-9 a pomlčky.
        assertSame(Brand::DEFAULT_PLUGIN_SLUG, (new Brand(null, ['slug' => '../hack'], $brand->assets()))->pluginSlug());
        assertSame('moje-sprava', (new Brand(null, ['slug' => 'moje-sprava'], $brand->assets()))->pluginSlug());
    },

    'Základní nastavení: uložení se propíše do textů aplikace, pluginu, 2FA i User-Agentu' => function (): void {
        [$kernel, $token] = loggedInKernel('Technik', ['plugin' => ['slug' => 'test-monitor']]);

        $response = kernelRequest($kernel, 'POST', '/nastaveni/zakladni', ['_token' => $token, 'name' => " Testovací\nstudio ", 'web' => 'https://studio.test/', 'email' => 'ahoj@studio.test', 'phone' => '', 'plugin' => '']);
        assertSame(302, $response->status());

        $brand = $kernel->brand();
        // Řádek pro hlavičku pluginu: zalomení pryč, lomítko na konci webu taky.
        assertSame('Testovací studio', $brand->name());
        assertSame('https://studio.test', $brand->web());
        assertSame('Testovací studio Monitor', $brand->pluginName());
        assertSame('SpravaWebu/1.0 (+https://studio.test)', $brand->userAgent());
        assertSame('Správa webů · Testovací studio', $brand->twoFactorIssuer());
        assertSame('Web zatím nemá napojený plugin Testovací studio Monitor.', SiteActions::blocked(['api_key' => ''], null, 'plugin-update'));

        $page = kernelRequest($kernel, 'GET', '/nastaveni/zakladni')->body();
        assertContainsString('value="Testovací studio"', $page);
        assertContainsString('placeholder="Testovací studio Monitor"', $page);
        assertContainsString('/wp-json/test-monitor/v1', $page);

        assertContainsString('Na webu nainstalujte plugin Testovací studio Monitor', kernelRequest($kernel, 'GET', '/weby/pridat')->body());

        // Vlastní název pluginu má přednost; firma ho neprázdní.
        kernelRequest($kernel, 'POST', '/nastaveni/zakladni/plugin', ['_token' => $token, 'plugin' => 'Hlídač', 'plugin_slug' => 'test-monitor']);
        assertSame('Hlídač', $kernel->brand()->pluginName());
        kernelRequest($kernel, 'POST', '/nastaveni/zakladni', ['_token' => $token, 'name' => 'Testovací studio', 'web' => '', 'email' => '', 'phone' => '']);
        assertSame('Hlídač', $kernel->brand()->pluginName());

        Brand::bind(null);
        Urls::reset();
    },

    'Základní nastavení: neplatný web ani e-mail se neuloží' => function (): void {
        [$kernel, $token] = loggedInKernel();

        kernelRequest($kernel, 'POST', '/nastaveni/zakladni', ['_token' => $token, 'name' => 'X', 'web' => 'studio.test', 'email' => '', 'phone' => '', 'plugin' => '']);
        assertSame('', $kernel->brand()->name());

        kernelRequest($kernel, 'POST', '/nastaveni/zakladni', ['_token' => $token, 'name' => 'X', 'web' => '', 'email' => 'neni-email', 'phone' => '', 'plugin' => '']);
        assertSame('', $kernel->brand()->name());

        Brand::bind(null);
        Urls::reset();
    },

    'složka pluginu: volně, dokud neodpovídá žádný web; pak jen s potvrzením' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $save = static fn (array $body) => kernelRequest($kernel, 'POST', '/nastaveni/zakladni/plugin', ['_token' => $token, 'plugin' => ''] + $body);

        assertSame(Brand::DEFAULT_PLUGIN_SLUG, $kernel->brand()->pluginSlug());
        assertFalse(str_contains(kernelRequest($kernel, 'GET', '/nastaveni/zakladni')->body(), 'readonly'), 'bez napojených webů jde měnit');

        $save(['plugin_slug' => 'Špatná složka']);
        assertSame(Brand::DEFAULT_PLUGIN_SLUG, $kernel->brand()->pluginSlug(), 'neplatná složka se neuloží');

        $save(['plugin_slug' => 'moje-sprava']);
        assertSame('moje-sprava', $kernel->brand()->pluginSlug());
        assertSame('moje-sprava/v1', $kernel->brand()->pluginNamespace());
        // Routy výdeje pluginu jdou podle nové složky (router se staví při požadavku).
        assertSame(404, kernelRequest($kernel, 'GET', '/plugin/' . Brand::DEFAULT_PLUGIN_SLUG . '/plugin-info.json')->status());

        // Web, na kterém plugin odpověděl — složka se zamkne.
        $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz', 'last_snapshot_at' => date('Y-m-d H:i:s')]);
        $page = kernelRequest($kernel, 'GET', '/nastaveni/zakladni')->body();
        assertContainsString('readonly', $page);
        assertContainsString('data-unlocks="plugin_slug"', $page);
        assertContainsString("1\u{00A0}napojený web", $page);

        $save(['plugin_slug' => 'jina-sprava']);
        assertSame('moje-sprava', $kernel->brand()->pluginSlug(), 'bez potvrzení se zamčená složka nemění');

        // Uložení bez změny složky (zamčené pole se odešle, jak je) projde.
        kernelRequest($kernel, 'POST', '/nastaveni/zakladni/plugin', ['_token' => $token, 'plugin' => 'Hlídač', 'plugin_slug' => 'moje-sprava']);
        assertSame('Hlídač', $kernel->brand()->pluginName());

        $save(['plugin_slug' => 'jina-sprava', 'plugin_slug_confirm' => '1']);
        assertSame('jina-sprava', $kernel->brand()->pluginSlug());

        Brand::bind(null);
        Urls::reset();
    },

    'loga: výchozí přes assets, nahrané přes /znacka/ s otiskem; SVG se sandboxem' => function (): void {
        [$kernel] = loggedInKernel();
        $assets = $kernel->brand()->assets();

        assertSame(['kind' => 'asset', 'path' => 'img/sprava-logo.svg'], $assets->logoTarget('app'));
        assertSame(null, $assets->logoTarget('studio'), 'logo studia výchozí nemá — píše se jméno');
        assertContainsString('/assets/img/sprava-logo.svg?v=', kernelRequest($kernel, 'GET', '/')->body());

        // „Nahrání" rovnou do úložiště — is_uploaded_file() v testu neprojde.
        @mkdir($kernel->storagePath('brand'), 0775, true);
        file_put_contents($kernel->storagePath('brand/app.svg'), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');

        $target = $assets->logoTarget('app');
        assertSame('route', $target['kind'] ?? '');
        assertTrue(str_starts_with($target['path'], 'znacka/logo-aplikace?v='));
        assertContainsString('src="/znacka/logo-aplikace?v=', kernelRequest($kernel, 'GET', '/')->body());

        $response = kernelRequest($kernel, 'GET', '/znacka/logo-aplikace', [], ['v' => 'abc']);
        assertSame(200, $response->status());
        assertSame('image/svg+xml', $response->headers()['Content-Type'] ?? '');
        assertContainsString('sandbox', $response->headers()['Content-Security-Policy'] ?? '');
        assertContainsString('immutable', $response->headers()['Cache-Control'] ?? '');

        // Bez nahrané ikony vydá routa výchozí — manifest a sw.js na ni spoléhají.
        $icon = kernelRequest($kernel, 'GET', '/znacka/icon-192.png');
        assertSame(200, $icon->status());
        assertSame('image/png', $icon->headers()['Content-Type'] ?? '');
        assertSame(404, kernelRequest($kernel, 'GET', '/znacka/logo-studia')->status());
        assertSame(404, kernelRequest($kernel, 'GET', '/znacka/..%2Fconfig')->status());

        Brand::bind(null);
        Urls::reset();
    },

    'nahrávání: typ podle obsahu, SVG se skriptem neprojde' => function (): void {
        assertSame('image/png', BrandAssets::detectMime("\x89PNG\r\n\x1a\nzbytek"));
        assertSame('image/jpeg', BrandAssets::detectMime("\xFF\xD8\xFFzbytek"));
        assertSame('image/svg+xml', BrandAssets::detectMime("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<svg viewBox=\"0 0 1 1\"></svg>"));
        assertSame(null, BrandAssets::detectMime('<html><body>ne</body></html>'));

        assertTrue(BrandAssets::svgHasActiveContent('<svg><script>alert(1)</script></svg>'));
        assertTrue(BrandAssets::svgHasActiveContent('<svg onload="x()"></svg>'));
        assertTrue(BrandAssets::svgHasActiveContent('<svg><a href="javascript:x()">a</a></svg>'));
        assertFalse(BrandAssets::svgHasActiveContent('<svg><path class="cls-1" d="M0 0"/></svg>'));
    },

    'ikona: celá sada ze znaku na průhledném pozadí, badge je bílá silueta' => function (): void {
        if (!function_exists('imagecreatetruecolor')) {
            skip('chybí GD');
        }

        $image = imagecreatetruecolor(800, 600);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 300, 200, 499, 399, (int) imagecolorallocate($image, 200, 30, 30));

        $dir = sys_get_temp_dir() . '/brand-ikony-' . bin2hex(random_bytes(4));
        mkdir($dir);
        (new IconSet($image))->write($dir);

        foreach (IconSet::FILES as $file) {
            assertTrue(is_file($dir . '/' . $file), 'chybí ' . $file);
        }

        [$w, $h] = (array) getimagesize($dir . '/icon-512.png');
        assertSame([512, 512], [$w, $h]);

        // Znak je ořezaný na obsah a vycentrovaný — střed ikony je jeho barva.
        $icon = imagecreatefrompng($dir . '/icon-512.png');
        $center = imagecolorsforindex($icon, imagecolorat($icon, 256, 256));
        assertSame([200, 30], [$center['red'], $center['green']]);
        // Roh má bílý podklad (iOS by průhlednost vyplnil černou).
        $corner = imagecolorsforindex($icon, imagecolorat($icon, 2, 2));
        assertSame([255, 255, 255], [$corner['red'], $corner['green'], $corner['blue']]);

        $badge = imagecreatefrompng($dir . '/badge-96.png');
        $pixel = imagecolorsforindex($badge, imagecolorat($badge, 48, 48));
        assertSame([255, 255, 255, 0], [$pixel['red'], $pixel['green'], $pixel['blue'], $pixel['alpha']]);

        assertSame("\x00\x00\x01\x00", substr((string) file_get_contents($dir . '/favicon.ico'), 0, 4), 'ICO hlavička');
    },

    'plugin: ZIP s razítkem instance — složka, hlavička, brand.php; stará cesta neexistuje' => function (): void {
        [$kernel] = brandKernel();
        brandBaseZip($kernel->storagePath('plugin'), '1.10.0');

        $info = $kernel->pluginDistribution()->info();
        assertSame('Testovací studio Monitor', $info['name']);
        assertSame('Testovací studio', $info['author']);
        assertSame('https://studio.test', $info['homepage']);
        assertSame('test-monitor', $info['slug']);
        assertSame('https://sprava.test/plugin/test-monitor/test-monitor-1.10.0.zip', $info['download_url']);
        assertContainsString('Testovací studio', $info['sections']['description']);
        assertSame('<pre>x</pre>', $info['sections']['changelog']);

        $info = kernelRequest($kernel, 'GET', '/plugin/test-monitor/plugin-info.json');
        assertSame(200, $info->status());
        assertSame(404, kernelRequest($kernel, 'GET', '/plugin/' . Brand::DEFAULT_PLUGIN_SLUG . '/plugin-info.json')->status());
        assertSame(200, kernelRequest($kernel, 'GET', '/plugin/test-monitor/test-monitor-1.10.0.zip')->status());

        $path = (string) $kernel->pluginDistribution()->zipPath('test-monitor-1.10.0.zip');
        $zip = new ZipArchive();
        assertTrue($zip->open($path) === true);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        sort($names);
        assertSame(['test-monitor/brand.php', 'test-monitor/includes/class-mg-monitor.php', 'test-monitor/test-monitor.php'], $names);

        $main = (string) $zip->getFromName('test-monitor/test-monitor.php');
        assertContainsString(' * Plugin Name: Testovací studio Monitor', $main);
        assertContainsString(' * Author: Testovací studio', $main);
        assertContainsString(' * Author URI: https://studio.test', $main);
        assertContainsString(' * Text Domain: test-monitor', $main);
        assertContainsString(' * Version: 1.10.0', $main);

        $brandFile = (string) $zip->getFromName('test-monitor/brand.php');
        assertContainsString("define('MG_MONITOR_SLUG', 'test-monitor');", $brandFile);
        assertContainsString("define('MG_MONITOR_HUB_URL', 'https://sprava.test');", $brandFile);
        assertContainsString("define('MG_MONITOR_NAME', 'Testovací studio Monitor');", $brandFile);
        $zip->close();

        // Druhé stažení jde z mezipaměti; nová značka = nové razítko.
        assertSame($path, $kernel->pluginDistribution()->zipPath('test-monitor-1.10.0.zip'));
        $kernel->brand()->save(['plugin' => "Jiný */ název"]);
        $restamped = (string) $kernel->pluginDistribution()->zipPath('test-monitor-1.10.0.zip');
        assertTrue($restamped !== $path && !is_file($path), 'staré razítko se má smazat');
        $zip->open($restamped);
        assertContainsString(' * Plugin Name: Jiný název', (string) $zip->getFromName('test-monitor/test-monitor.php'));
        $zip->close();

        // Jméno souboru jen podle vzoru — nic jiného z úložiště nejde ven.
        assertSame(null, $kernel->pluginDistribution()->zipPath('../plugin-info.json'));
        assertSame(null, $kernel->pluginDistribution()->zipPath('monitor-1.10.0.zip'));

        Brand::bind(null);
        Urls::reset();
    },

    'plugin: balíček z doby před razítkem se vydá jen instanci s výchozím slugem' => function (): void {
        $dir = sys_get_temp_dir() . '/brand-legacy-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/' . Brand::DEFAULT_PLUGIN_SLUG . '-1.9.1.zip', 'starý zip');
        $assets = new BrandAssets($dir, WWW_ROOT . '/assets');

        $default = new PluginDistribution($dir, 'https://a.test', new Brand(null, [], $assets));
        assertSame($dir . '/' . Brand::DEFAULT_PLUGIN_SLUG . '-1.9.1.zip', $default->zipPath(Brand::DEFAULT_PLUGIN_SLUG . '-1.9.1.zip'));

        $own = new PluginDistribution($dir, 'https://a.test', new Brand(null, ['slug' => 'moje-sprava'], $assets));
        assertSame(null, $own->zipPath('moje-sprava-1.9.1.zip'));
    },

    'nikde MEDIAGRAFIK: hlavní stránky instance s jiným studiem' => function (): void {
        [$kernel] = brandKernel();
        brandBaseZip($kernel->storagePath('plugin'), '1.10.0');
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz', 'api_key' => 'x', 'api_status' => 'no_plugin', 'api_failures' => 3]);

        $pages = [
            '/', '/weby', '/weby/pridat', '/knihovna', '/projekty', '/klienti', '/alerty', '/reporty',
            '/weby/' . $siteId, '/weby/' . $siteId . '/pluginy', '/weby/' . $siteId . '/obsah', '/weby/' . $siteId . '/zabezpeceni',
            '/weby/' . $siteId . '/servis', '/weby/' . $siteId . '/reporty', '/weby/' . $siteId . '/nastaveni', '/weby/' . $siteId . '/reporty/nahled',
            '/nastaveni/monitoring', '/nastaveni/alerty', '/nastaveni/moduly', '/nastaveni/servis', '/nastaveni/reporty',
            '/nastaveni/email', '/nastaveni/oznameni', '/nastaveni/uzivatele', '/nastaveni/zakladni',
        ];

        foreach ($pages as $path) {
            $response = kernelRequest($kernel, 'GET', $path);

            // Náhled reportu si nejdřív založí koncept a přesměruje na něj.
            if ($response->status() === 302) {
                $path = (string) parse_url($response->headers()['Location'] ?? '', PHP_URL_PATH);
                $response = kernelRequest($kernel, 'GET', $path);
            }
            assertSame(200, $response->status(), $path . ' vrátila ' . $response->status());
            assertFalse(stripos($response->body(), 'mediagrafik') !== false, 'Na stránce ' . $path . ' je MEDIAGRAFIK');
        }

        assertContainsString('test-monitor-1.10.0.zip', kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/nastaveni')->body());

        Brand::bind(null);
        Urls::reset();
    },
];
