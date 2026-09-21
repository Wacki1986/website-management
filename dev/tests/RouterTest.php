<?php

declare(strict_types=1);

use App\Core\Http\HttpException;
use App\Core\Http\Router;

return [
    'najde jednoduchou routu' => function (): void {
        $router = new Router();
        $router->add('GET', '/uzivatele', ['Ctrl', 'index'], 'core.users.view');

        $match = $router->match('GET', '/uzivatele');

        assertTrue($match !== null, 'Routa se nenašla');
        assertSame('core.users.view', $match['capability']);
        assertSame([], $match['params']);
    },

    'vytáhne parametr z adresy' => function (): void {
        $router = new Router();
        $router->add('GET', '/uzivatele/{id}', ['Ctrl', 'edit'], 'core.users.manage');

        $match = $router->match('GET', '/uzivatele/42');

        assertTrue($match !== null);
        assertSame(['id' => '42'], $match['params']);
    },

    'neznámá adresa vrátí null' => function (): void {
        $router = new Router();
        $router->add('GET', '/uzivatele', ['Ctrl', 'index'], 'core.users.view');

        assertSame(null, $router->match('GET', '/neexistuje'));
    },

    'shoda cesty s jinou metodou vrací 405' => function (): void {
        $router = new Router();
        $router->add('GET', '/uzivatele', ['Ctrl', 'index'], 'core.users.view');

        $e = assertThrows(HttpException::class, static fn () => $router->match('POST', '/uzivatele'));
        assertSame(405, $e->status());
    },

    // Klíčové poučení z auditu: mazání záznamů bylo chráněné jen skrytým
    // tlačítkem v šabloně. Router proto mutační routu bez oprávnění nepřijme.
    'mutační routa musí deklarovat oprávnění' => function (): void {
        $router = new Router();

        assertThrows(
            LogicException::class,
            static fn () => $router->add('POST', '/uzivatele', ['Ctrl', 'store']),
            'POST bez capability měl skončit výjimkou',
        );
    },

    'mutační routa smí být výslovně veřejná' => function (): void {
        $router = new Router();
        $router->add('POST', '/prihlaseni', ['Ctrl', 'login'], Router::PUBLIC_ACCESS);

        $match = $router->match('POST', '/prihlaseni');

        assertTrue($match !== null);
        assertSame(Router::PUBLIC_ACCESS, $match['capability']);
    },

    'GET routa bez deklarace vyžaduje aspoň přihlášení' => function (): void {
        $router = new Router();
        $router->add('GET', '/profil', ['Ctrl', 'show']);

        $match = $router->match('GET', '/profil');

        assertTrue($match !== null);
        assertSame(Router::AUTH_ONLY, $match['capability'], 'Výchozí stav musí být „jen přihlášený"');
    },

    'lomítka na konci nerozhodují' => function (): void {
        $router = new Router();
        $router->add('GET', '/uzivatele', ['Ctrl', 'index'], 'core.users.view');

        assertTrue($router->match('GET', '/uzivatele/') !== null);
    },
];
