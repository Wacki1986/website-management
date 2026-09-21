<?php

declare(strict_types=1);

use App\Core\Http\Csrf;
use App\Core\Http\Request;

/** Pomocník: request s daným tělem a hlavičkami. */
$makeRequest = static function (array $body = [], array $headers = []): Request {
    return new Request(method: 'POST', path: '/test', body: $body, headers: $headers);
};

return [
    'token je dostatečně náhodný' => function (): void {
        $_SESSION = [];
        $csrf = new Csrf();

        $first = $csrf->token();
        assertSame(64, strlen($first), 'Token má být 32 bajtů v hexu');

        $csrf->rotate();
        assertFalse($first === $csrf->token(), 'Rotace musí token změnit');
    },

    'platný token projde' => function () use ($makeRequest): void {
        $_SESSION = [];
        $csrf = new Csrf();
        $token = $csrf->token();

        assertTrue($csrf->validate($makeRequest(['_token' => $token])));
    },

    'token lze poslat i hlavičkou' => function () use ($makeRequest): void {
        $_SESSION = [];
        $csrf = new Csrf();
        $token = $csrf->token();

        assertTrue($csrf->validate($makeRequest([], ['x-csrf-token' => $token])));
    },

    // Stará aplikace odvozovala nonce z času a ID uživatele bez tajného klíče,
    // takže šel dopočítat. Tady musí být neuhodnutelný.
    'podvržený token neprojde' => function () use ($makeRequest): void {
        $_SESSION = [];
        $csrf = new Csrf();
        $csrf->token();

        assertFalse($csrf->validate($makeRequest(['_token' => str_repeat('a', 64)])));
    },

    'chybějící token neprojde' => function () use ($makeRequest): void {
        $_SESSION = [];
        $csrf = new Csrf();
        $csrf->token();

        assertFalse($csrf->validate($makeRequest()));
    },

    'bez session se nic neověří' => function () use ($makeRequest): void {
        $_SESSION = [];
        $csrf = new Csrf();

        assertFalse($csrf->validate($makeRequest(['_token' => 'cokoli'])));
    },
];
