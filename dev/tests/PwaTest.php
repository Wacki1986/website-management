<?php

declare(strict_types=1);

/**
 * PWA — manifest, service worker a to, co je musí pustit ven.
 *
 * Všechno tady jsou statické soubory, takže se testují jako soubory. Není to
 * puntičkářství: každý z těch asertů odpovídá chybě, která se **nijak
 * neohlásí** a pozná se jen tím, že se instalace na plochu prostě nenabídne
 * nebo že upozornění nikdy nedorazí.
 *
 *  - odkaz na neexistující ikonu → Chrome vyhodnotí web jako neinstalovatelný,
 *    bez hlášky v konzoli,
 *  - chybějící `assets/offline.html` → `cache.add()` v `install` se odmítne,
 *    **service worker se nenainstaluje** a nefunguje ani push,
 *  - `sw.js` mimo whitelist v `.htaccess` → 404 a registrace tiše selže,
 *  - `sw.js` bez `no-cache` → prohlížeč si roční `immutable` cache podrží
 *    a nová verze workeru se k lidem nikdy nedostane.
 */

/** @return array<string, mixed> */
function pwaManifest(): array
{
    $raw = (string) file_get_contents(WWW_ROOT . '/assets/manifest.webmanifest');
    $decoded = json_decode($raw, true);

    assertTrue(is_array($decoded), 'manifest není platný JSON');

    return $decoded;
}

return [
    'manifest je platný a instalovatelný' => function (): void {
        $manifest = pwaManifest();

        assertSame('standalone', $manifest['display'] ?? null, 'bez standalone se aplikace neotevře bez lišty prohlížeče');
        assertTrue(($manifest['name'] ?? '') !== '');
        assertTrue(mb_strlen((string) ($manifest['short_name'] ?? '')) <= 12, 'short_name se nevejde pod ikonu na ploše');
        assertTrue(($manifest['theme_color'] ?? '') !== '');
        assertTrue(($manifest['background_color'] ?? '') !== '');
    },

    'start_url i scope míří na kořen instalace, ne do assets/' => function (): void {
        $manifest = pwaManifest();

        // Adresy v manifestu se počítají od jeho umístění (`/assets/…`).
        // Zapsané `../` je tedy kořen instalace — a funguje i v podadresáři,
        // kvůli kterému existuje `Kernel::detectBasePath()`.
        assertSame('../', $manifest['start_url'] ?? null);
        assertSame('../', $manifest['scope'] ?? null);
    },

    'každá ikona z manifestu na disku opravdu leží' => function (): void {
        $manifest = pwaManifest();
        $icons = $manifest['icons'] ?? [];

        assertTrue(is_array($icons) && $icons !== [], 'manifest bez ikon je neinstalovatelný');

        $purposes = [];

        foreach ($icons as $icon) {
            $file = WWW_ROOT . '/assets/' . $icon['src'];
            assertTrue(is_file($file), 'ikona z manifestu chybí: ' . $icon['src']);

            [$width, $height] = (array) getimagesize($file);
            assertSame($icon['sizes'], $width . 'x' . $height, 'ikona ' . $icon['src'] . ' má jiné rozměry, než manifest slibuje');

            $purposes[] = (string) ($icon['purpose'] ?? 'any');
        }

        assertTrue(in_array('any', $purposes, true), 'chybí běžná ikona');
        assertTrue(in_array('maskable', $purposes, true), 'bez maskable si Android ikonu ořízne po svém');
    },

    'service worker existuje a umí push i offline stránku' => function (): void {
        $sw = (string) file_get_contents(WWW_ROOT . '/sw.js');

        assertContainsString('addEventListener("push"', $sw);
        assertContainsString('addEventListener("notificationclick"', $sw);
        assertContainsString('assets/offline.html', $sw);

        // Bez offline stránky se `install` odmítne a worker se nenainstaluje.
        assertTrue(is_file(WWW_ROOT . '/assets/offline.html'), 'chybí assets/offline.html');
    },

    '.htaccess pustí sw.js ven a nezamkne ho na rok v cache' => function (): void {
        $htaccess = (string) file_get_contents(WWW_ROOT . '/.htaccess');

        assertContainsString('sw\.js', $htaccess);
        assertContainsString('AddType application/manifest+json .webmanifest', $htaccess);

        // Pořadí rozhoduje: `<Files "sw.js">` musí stát AŽ ZA pravidlem
        // s `immutable`, jinak ho `immutable` přebije.
        $immutable = strpos($htaccess, 'immutable');
        $noCache = strpos($htaccess, 'no-cache');
        assertTrue($immutable !== false && $noCache !== false && $noCache > $immutable,
            'no-cache pro sw.js musí být až za pravidlem immutable');
    },

    'hlavička stránky odkazuje manifest tak, aby prošel i za Basic auth' => function (): void {
        $head = (string) file_get_contents(WWW_ROOT . '/app/templates/partials/head-icons.php');

        assertContainsString('rel="manifest"', $head);
        // Bez tohohle atributu se manifest stahuje bez přihlašovacích údajů
        // a za Basic auth vrátí 401 — instalace se pak tiše nenabídne.
        assertContainsString('crossorigin="use-credentials"', $head);
        assertContainsString('name="app-sw"', $head);
        assertContainsString('name="theme-color"', $head);
    },

    /**
     * Badge = drobná ikonka ve stavovém řádku Androidu. Systém z obrázku
     * bere jen průhlednost, zbytek přebarví — bez alfy z něj vyjde plný
     * čtvereček (přesně tak vypadala, dokud se posílala barevná
     * `icon-192.png`). Chyba se nijak neohlásí, pozná se jen okem
     * na telefonu, proto se hlídá tady.
     */
    'badge upozornění je průhledný, jinak z něj Android udělá čtvereček' => function (): void {
        $file = WWW_ROOT . '/assets/favicons/badge-96.png';

        assertTrue(is_file($file), 'badge-96.png chybí');
        assertContainsString('badge-96.png', (string) file_get_contents(WWW_ROOT . '/sw.js'));

        [$width, $height] = (array) getimagesize($file);
        assertSame('96x96', $width . 'x' . $height);

        $image = imagecreatefrompng($file);
        $opaque = 0;
        $transparent = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // V PNG je alfa 0 = plné krytí, 127 = úplná průhlednost.
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;

                if ($alpha < 40) {
                    $opaque++;
                } elseif ($alpha > 100) {
                    $transparent++;
                }
            }
        }

        $plocha = $width * $height;
        assertTrue($transparent > $plocha * 0.4, 'Badge nemá průhledné pozadí — bude z něj čtvereček');
        assertTrue($opaque > $plocha * 0.15, 'Badge je skoro prázdný — ve stavovém řádku nebude vidět nic');
        assertTrue($opaque < $plocha * 0.75, 'Kresba badge je přes celou plochu — Android si ji ořízne');
    },

    'CSP počítá se service workerem, manifestem i Google Fonts' => function (): void {
        $index = (string) file_get_contents(WWW_ROOT . '/index.php');

        assertContainsString("worker-src 'self'", $index);
        assertContainsString("manifest-src 'self'", $index);
        assertContainsString('https://fonts.gstatic.com', $index);
    },

    /**
     * Šablona Basic auth (varianta B v .htaccess) je v souboru zakomentovaná,
     * takže ji nic nespustí — a přesně proto se hlídá tady. Až ji někdo na
     * serveru odkomentuje, musí být úplná: chybějící výjimka se totiž
     * neprojeví chybou, ale tím, že přestane běžet cron nebo se
     * nenabídne instalace na plochu. Postup a důvody: dev/docs/00-provoz.md.
     */
    'šablona Basic auth pouští ven vše, co heslo nemá kde vzít' => function (): void {
        $htaccess = (string) file_get_contents(WWW_ROOT . '/.htaccess');

        $needed = [
            // Prohlížeč a service worker si k těmhle souborům přihlašovací
            // údaje přiložit nemusí.
            'sw\.js' => 'service worker',
            'assets/manifest\.webmanifest' => 'manifest',
            'assets/offline\.html' => 'offline stránka (bez ní selže instalace SW)',
            'assets/favicons/' => 'ikony',
            // Server serveru nebo cizí klient — žádné heslo.
            'system/monitor-cron' => 'hostingový cron přes wget',
            'r/[a-f0-9]{32}\.gif' => 'sledovací obrázek v klientských reportech',
            'plugin/mediagrafik-monitor/' => 'aktualizace pluginu na webech klientů',
        ];

        foreach ($needed as $pattern => $why) {
            assertContainsString(
                'SetEnvIf Request_URI "(^|/)' . $pattern,
                $htaccess,
                'V šabloně Basic auth chybí výjimka: ' . $why,
            );
        }

        // Bez tohohle nemá `SetEnvIf` co povolit — `Require valid-user`
        // samotné by výjimky přebilo.
        assertContainsString('Require env SPRAVA_BEZ_HESLA', $htaccess);
    },
];
