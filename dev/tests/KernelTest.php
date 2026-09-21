<?php

declare(strict_types=1);

/**
 * Průchod requestu Kernelem — bez databáze.
 *
 * Přihlašovací stránka, přesměrování nepřihlášeného, CSRF a chybové stránky
 * musí fungovat i s nedostupnou databází (stejná zásada jako u jádra: líné
 * připojení). Testy proto Kernel staví s prázdnou konfigurací databáze.
 */

use App\Core\Http\Request;
use App\Core\Kernel;
use App\Core\View\Urls;

/** @return Kernel připravený Kernel bez databáze */
function makeTestKernel(): Kernel
{
    $kernel = new Kernel([
        'environment' => 'development',
        'timezone' => 'Europe/Prague',
        'database' => [],
        'storage_path' => sys_get_temp_dir() . '/sprava-webu-test-storage',
    ], WWW_ROOT);

    Urls::bind($kernel->url(...), $kernel->asset(...));

    return $kernel;
}

/** @param array<string, mixed> $overrides */
function makeRequest(string $method, string $path, array $overrides = []): Request
{
    return new Request(
        method: $method,
        path: $path,
        query: [],
        body: (array) ($overrides['body'] ?? []),
        files: [],
        cookies: [],
        headers: (array) ($overrides['headers'] ?? []),
        server: ['SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'],
    );
}

return [
    'nepřihlášeného pošle z přehledu na přihlášení' => function (): void {
        $_SESSION = [];
        $response = makeTestKernel()->handle(makeRequest('GET', '/'));

        assertSame(302, $response->status());
        assertContainsString('/prihlaseni', $response->headers()['Location'] ?? '');

        Urls::reset();
    },

    'přihlašovací stránka se vykreslí bez databáze' => function (): void {
        $_SESSION = [];
        $response = makeTestKernel()->handle(makeRequest('GET', '/prihlaseni'));

        assertSame(200, $response->status());
        assertContainsString('Přihlásit se', $response->body());
        // Štítek aplikace musí být vidět — správa nesmí jít splést s instancí.
        assertContainsString('Správa webů', $response->body());
        // Odkaz na zapomenuté heslo tam být musí — reset e-mailem je hlavní cesta.
        assertContainsString('zapomenute-heslo', $response->body());

        Urls::reset();
    },

    'import mapa verzuje JS moduly a vstupní bod vynechává' => function (): void {
        $kernel = makeTestKernel();
        $imports = $kernel->jsImportMap()['imports'];

        // Moduly importované z app.js musí mapa přesměrovat na otisknuté
        // adresy — jinak po nasazení zůstávají v prohlížeči staré z cache.
        assertTrue(isset($imports['/assets/js/modules/toast.js']), 'toast.js v mapě chybí');
        assertContainsString('/assets/js/modules/toast.js?v=', (string) $imports['/assets/js/modules/toast.js']);

        // app.js nese otisk přímo v src="", do mapy nepatří.
        assertFalse(isset($imports['/assets/js/app.js']), 'app.js do mapy nepatří');

        // A přihlašovací stránka mapu opravdu vydává před module skriptem.
        $_SESSION = [];
        $body = makeTestKernel()->handle(makeRequest('GET', '/prihlaseni'))->body();
        $mapAt = strpos($body, 'type="importmap"');
        $moduleAt = strpos($body, 'type="module"');
        assertTrue($mapAt !== false, 'Stránka nevydává import mapu');
        assertTrue($moduleAt !== false && $mapAt < $moduleAt, 'Import mapa musí předcházet module skriptu');
        // CSP drží script-src 'self' — inline import mapa projde jen s nonce.
        assertContainsString('type="importmap" nonce="', $body);

        Urls::reset();
    },

    'mutace bez CSRF tokenu vrátí 419' => function (): void {
        $_SESSION = [];
        $response = makeTestKernel()->handle(makeRequest('POST', '/prihlaseni', [
            'body' => ['username' => 'x', 'password' => 'y'],
        ]));

        assertSame(419, $response->status());
        assertContainsString('419', $response->body());

        Urls::reset();
    },

    'neznámá adresa vrátí 404 stránku' => function (): void {
        $_SESSION = [];
        $response = makeTestKernel()->handle(makeRequest('GET', '/tady-nic-neni'));

        assertSame(404, $response->status());
        assertContainsString('404', $response->body());

        Urls::reset();
    },

    'JSON požadavek nepřihlášeného dostane 401, ne redirect' => function (): void {
        $_SESSION = [];
        $response = makeTestKernel()->handle(makeRequest('GET', '/', [
            'headers' => ['accept' => 'application/json'],
        ]));

        assertSame(401, $response->status());
        assertContainsString('"status":"error"', $response->body());

        Urls::reset();
    },

    'přihlášení s nedostupnou databází skončí pětistovkou se značkou' => function (): void {
        $_SESSION = [];
        $kernel = makeTestKernel();

        // Platný CSRF token — selhat má až databáze, ne formulář.
        $token = $kernel->csrf()->token();

        $response = $kernel->handle(makeRequest('POST', '/prihlaseni', [
            'body' => ['username' => 'spravce', 'password' => 'tajne-heslo', '_token' => $token],
        ]));

        assertSame(500, $response->status());
        assertContainsString('err-', $response->body(), 'Pětistovka nenese značku chyby');

        Urls::reset();
    },

    'IP filtr: nepovolená adresa dostane 403, povolená a CIDR projdou' => function (): void {
        $_SESSION = [];
        $kernel = new Kernel([
            'environment' => 'development',
            'database' => [],
            'storage_path' => sys_get_temp_dir() . '/sprava-webu-test-storage',
            'allowed_ips' => ['89.103.12.44', '10.1.0.0/16'],
        ], WWW_ROOT);
        Urls::bind($kernel->url(...), $kernel->asset(...));

        $requestFrom = static fn (string $ip): Request => new Request(
            method: 'GET',
            path: '/prihlaseni',
            server: ['SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => $ip],
        );

        $blocked = $kernel->handle($requestFrom('203.0.113.9'));
        assertSame(403, $blocked->status());
        assertContainsString('203.0.113.9', $blocked->body(), '403 má říct, odkud se člověk připojuje');

        assertSame(200, $kernel->handle($requestFrom('89.103.12.44'))->status());
        assertSame(200, $kernel->handle($requestFrom('10.1.200.7'))->status());

        Urls::reset();
    },

    'prázdný seznam allowed_ips filtr vypíná' => function (): void {
        $_SESSION = [];
        $response = makeTestKernel()->handle(makeRequest('GET', '/prihlaseni'));

        assertSame(200, $response->status());

        Urls::reset();
    },

    'adresy respektují basePath' => function (): void {
        $kernel = makeTestKernel();
        // Bez requestu je basePath prázdný — adresa začíná lomítkem.
        assertSame('/prihlaseni', $kernel->url('prihlaseni'));
        assertSame('https://jinam.cz/x', $kernel->url('https://jinam.cz/x'));

        Urls::reset();
    },
];
