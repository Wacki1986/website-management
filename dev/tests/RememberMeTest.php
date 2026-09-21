<?php

declare(strict_types=1);

/**
 * Trvalé přihlášení — hlavně ochranná lhůta rotace.
 *
 * Nález z provozu: session správy dřív umírala GC hostingu a každé načtení
 * šlo přes remember cookie. Dva souběžné requesty (dvě taby, prefetch) pak
 * znamenaly rotaci v prvním a „krádež" ve druhém — smazaly se všechny
 * tokeny a uživatel byl odhlášený s navenek platnou cookie. Starý validator
 * v krátkém okně po rotaci je souběh; po lhůtě platí původní přísnost.
 */

use App\Core\Auth\PasswordPolicy;
use App\Core\Auth\RememberMe;
use App\Core\Auth\UserRepository;
use App\Core\Http\Request;

/** @param string $cookie hodnota app_remember */
function rememberRequest(string $cookie): Request
{
    return new Request(
        method: 'GET',
        path: '/',
        query: [],
        body: [],
        files: [],
        cookies: [RememberMe::COOKIE => $cookie],
        headers: [],
        server: ['SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'],
    );
}

return [
    'souběh rotace: starý validator v lhůtě projde, po lhůtě je to útok' => function (): void {
        $db = freshTestDb();
        $remember = new RememberMe($db);
        $userId = (new UserRepository($db))->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        $db->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $cookie = $selector . ':' . $validator;

        // První request: přihlásí a zrotuje (starý hash zůstane jako previous).
        assertSame($userId, $remember->attempt(rememberRequest($cookie)));

        $token = $db->selectOne('SELECT * FROM remember_tokens WHERE selector = :s', ['s' => $selector]);
        assertSame(hash('sha256', $validator), (string) $token['previous_hash']);
        assertFalse(hash_equals((string) $token['validator_hash'], hash('sha256', $validator)), 'Validator se zrotoval');

        // Souběžný request se STAROU cookie: projde, nerotuje, nemaže.
        assertSame($userId, $remember->attempt(rememberRequest($cookie)));

        $after = $db->selectOne('SELECT * FROM remember_tokens WHERE selector = :s', ['s' => $selector]);
        assertSame((string) $token['validator_hash'], (string) $after['validator_hash'], 'Souběh nerotuje podruhé');

        // Po lhůtě je starý validator zase cizí pokus — všechny tokeny pryč.
        $db->execute('UPDATE remember_tokens SET rotated_at = :old', ['old' => date('Y-m-d H:i:s', time() - 600)]);
        assertSame(null, $remember->attempt(rememberRequest($cookie)));
        assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM remember_tokens WHERE user_id = :u', ['u' => $userId]));
    },

    'úplně cizí validator smaže všechny tokeny uživatele' => function (): void {
        $db = freshTestDb();
        $remember = new RememberMe($db);
        $userId = (new UserRepository($db))->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        $selector = bin2hex(random_bytes(12));

        $db->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        assertSame(null, $remember->attempt(rememberRequest($selector . ':' . bin2hex(random_bytes(32)))));
        assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM remember_tokens WHERE user_id = :u', ['u' => $userId]));
    },
];
