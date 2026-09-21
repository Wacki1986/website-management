<?php

declare(strict_types=1);

/**
 * Přihlašování správce — proti testovací databázi (bez ní se přeskočí).
 */

use App\Core\Auth\Auth;
use App\Core\Auth\LoginRateLimiter;
use App\Core\Auth\PasswordPolicy;
use App\Core\Auth\RememberMe;
use App\Core\Auth\UserRepository;
use App\Core\Db\Connection;
use App\Core\Http\Csrf;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Security\RateLimiter;

/** @return array{0: Auth, 1: UserRepository} */
function makeAuth(Connection $db): array
{
    $users = new UserRepository($db);

    return [
        new Auth($users, new RememberMe($db), new LoginRateLimiter(new RateLimiter($db)), new Csrf()),
        $users,
    ];
}

/** @param array<string, string> $cookies */
function authRequest(array $cookies = []): Request
{
    return new Request(
        method: 'POST',
        path: '/prihlaseni',
        cookies: $cookies,
        server: ['REMOTE_ADDR' => '10.0.0.1'],
    );
}

return [
    'migrace založí schéma a jsou idempotentní' => function (): void {
        $db = freshTestDb();

        $tables = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $db->select('SHOW TABLES'),
        );

        assertTrue(in_array('users', $tables, true), 'Chybí tabulka users');
        assertTrue(in_array('rate_limits', $tables, true), 'Chybí tabulka rate_limits');

        // Druhé spuštění nesmí nic aplikovat ani spadnout.
        $migrator = new App\Core\Db\Migrator($db);
        $migrator->addPath('core', WWW_ROOT . '/database/migrations');
        $report = $migrator->run();

        assertSame([], $report['applied'], 'Migrace se aplikovaly podruhé');
        assertSame(null, $report['failed']);
    },

    'správce se přihlásí správným heslem' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        $user = $auth->login(authRequest(), 'spravce', 'spravne-heslo-123');

        assertSame('spravce', $user['username']);
        assertSame((int) $user['id'], $_SESSION['user_id'] ?? null, 'Session nenese ID uživatele');
        assertTrue($auth->current() !== null, 'current() nevrací přihlášeného');
    },

    'špatné heslo neprojde a neprozradí, co neselo' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        $e = assertThrows(HttpException::class, fn () => $auth->login(authRequest(), 'spravce', 'spatne'));

        assertSame(422, $e->status());
        assertContainsString('Nesprávné přihlašovací jméno nebo heslo', $e->getMessage());
    },

    'neexistující účet dostane stejnou hlášku jako špatné heslo' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth] = makeAuth($db);

        $e = assertThrows(HttpException::class, fn () => $auth->login(authRequest(), 'neexistuje', 'cokoli'));

        assertSame(422, $e->status());
        assertContainsString('Nesprávné přihlašovací jméno nebo heslo', $e->getMessage());
    },

    'po pěti neúspěších se přihlášení zablokuje' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        for ($i = 0; $i < 5; $i++) {
            assertThrows(HttpException::class, fn () => $auth->login(authRequest(), 'spravce', 'spatne'));
        }

        // Šestý pokus narazí na limit — i se správným heslem.
        $e = assertThrows(
            HttpException::class,
            fn () => $auth->login(authRequest(), 'spravce', 'spravne-heslo-123'),
        );

        assertSame(429, $e->status());
    },

    'úspěch počítadlo vynuluje' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        for ($i = 0; $i < 4; $i++) {
            assertThrows(HttpException::class, fn () => $auth->login(authRequest(), 'spravce', 'spatne'));
        }

        $auth->login(authRequest(), 'spravce', 'spravne-heslo-123');
        $auth->logout(authRequest());

        // Po úspěchu je počítadlo prázdné — čtyři nové neúspěchy ještě neblokují.
        $_SESSION = [];
        [$auth2] = makeAuth($db);

        for ($i = 0; $i < 4; $i++) {
            $e = assertThrows(HttpException::class, fn () => $auth2->login(authRequest(), 'spravce', 'spatne'));
            assertSame(422, $e->status(), 'Limit blokuje dřív, než má');
        }
    },

    'neaktivní účet se nepřihlásí' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $id = $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));
        $users->update($id, ['is_active' => 0]);

        $e = assertThrows(
            HttpException::class,
            fn () => $auth->login(authRequest(), 'spravce', 'spravne-heslo-123'),
        );

        assertSame(422, $e->status());
    },

    '„zůstat přihlášen" vydá token a cookie přihlásí' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));
        $auth->login(authRequest(), 'spravce', 'spravne-heslo-123', remember: true);

        $token = $db->selectOne('SELECT * FROM remember_tokens');
        assertTrue($token !== null, 'Token se nevydal');

        // Cookie se v CLI nedá přečíst ze setcookie — poskládá se z databáze.
        // Validator v DB není (jen hash), takže se tady testuje mechanika
        // attempt() přes RememberMe přímo: špatný validator = krádež.
        $remember = new RememberMe($db);
        $theft = $remember->attempt(authRequest([
            RememberMe::COOKIE => $token['selector'] . ':' . str_repeat('ab', 32),
        ]));

        assertSame(null, $theft, 'Špatný validator nesmí projít');
        assertSame(
            0,
            (int) $db->scalar('SELECT COUNT(*) FROM remember_tokens WHERE user_id = :u', ['u' => $token['user_id']]),
            'Po pokusu o zneužití se mají zrušit všechny tokeny uživatele',
        );
    },

    'platná remember cookie přihlásí a zrotuje validator' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        $users = new UserRepository($db);
        $id = $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        // issue() jde obejít: token se vloží ručně, aby byl validator známý.
        $validator = bin2hex(random_bytes(32));
        $db->insert('remember_tokens', [
            'user_id' => $id,
            'selector' => 'testselector0001',
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        [$auth] = makeAuth($db);
        $user = $auth->user(authRequest([RememberMe::COOKIE => 'testselector0001:' . $validator]));

        assertTrue($user !== null, 'Cookie nepřihlásila');
        assertSame('spravce', $user['username']);

        // Rotace: starý validator smí projít jen v ochranné lhůtě souběhu
        // (RememberMe::ROTATION_GRACE, viz RememberMeTest) — po ní už ne.
        $db->execute('UPDATE remember_tokens SET rotated_at = :old', ['old' => date('Y-m-d H:i:s', time() - 600)]);
        $again = (new RememberMe($db))->attempt(authRequest([
            RememberMe::COOKIE => 'testselector0001:' . $validator,
        ]));
        assertSame(null, $again, 'Použitý validator prošel i po ochranné lhůtě');
    },

    'změna hesla zneplatní ostatní relace' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));
        $user = $auth->login(authRequest(), 'spravce', 'spravne-heslo-123');

        // Jiná relace změní heslo — auth hash v téhle session přestane sedět.
        $users->update((int) $user['id'], ['password_hash' => PasswordPolicy::hash('uplne-jine-heslo-456')]);

        $_SESSION['last_activity'] = time();
        [$freshAuth] = makeAuth($db);

        assertSame(null, $freshAuth->current(), 'Relace přežila změnu hesla');
    },

    'přihlásit se jde i e-mailem místo přihlašovacího jména' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'), 'spravce@example.cz');

        $user = $auth->login(authRequest(), 'spravce@example.cz', 'spravne-heslo-123');

        assertSame('spravce', $user['username']);
        assertSame((int) $user['id'], $_SESSION['user_id'] ?? null, 'Session nenese ID uživatele');
    },

    'e-mail napsaný velkými písmeny projde' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'), 'spravce@example.cz');

        $user = $auth->login(authRequest(), 'Spravce@Example.CZ', 'spravne-heslo-123');

        assertSame('spravce', $user['username']);
    },

    'účet bez e-mailu se e-mailem nepřihlásí' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        $e = assertThrows(
            HttpException::class,
            fn () => $auth->login(authRequest(), 'spravce@example.cz', 'spravne-heslo-123'),
        );

        assertSame(422, $e->status());
    },

    'počítadlo pokusů drží účet, ne to, co kdo napsal' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'), 'spravce@example.cz');

        // Tři pokusy jménem a dva e-mailem je pět pokusů na jeden účet —
        // střídáním údaje nesmí jít limit obejít.
        for ($i = 0; $i < 3; $i++) {
            assertThrows(HttpException::class, fn () => $auth->login(authRequest(), 'spravce', 'spatne'));
        }

        for ($i = 0; $i < 2; $i++) {
            assertThrows(HttpException::class, fn () => $auth->login(authRequest(), 'spravce@example.cz', 'spatne'));
        }

        $e = assertThrows(
            HttpException::class,
            fn () => $auth->login(authRequest(), 'spravce', 'spravne-heslo-123'),
        );

        assertSame(429, $e->status(), 'Limit nedržel účet, ale napsaný údaj');
    },

    'úspěch e-mailem vynuluje počítadlo vedené na jménu' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];
        [$auth, $users] = makeAuth($db);

        $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'), 'spravce@example.cz');

        for ($i = 0; $i < 4; $i++) {
            assertThrows(HttpException::class, fn () => $auth->login(authRequest(), 'spravce', 'spatne'));
        }

        $auth->login(authRequest(), 'spravce@example.cz', 'spravne-heslo-123');

        assertSame(
            0,
            (int) $db->scalar('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b AND success = 0', [
                'b' => \App\Core\Auth\LoginRateLimiter::BUCKET_USERNAME,
            ]),
            'Úspěšné přihlášení e-mailem nechalo počítadlo plné',
        );
    },

    'findByLogin dává přednost přihlašovacímu jménu před cizím e-mailem' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);

        // Krajní případ: jméno jednoho účtu se shoduje s e-mailem druhého.
        // Rozhodovat musí jméno — je to primární identifikátor účtu.
        $jmeno = $users->create('spravce@example.cz', PasswordPolicy::hash('spravne-heslo-123'));
        $users->create('kolega', PasswordPolicy::hash('spravne-heslo-123'), 'spravce@example.cz');

        assertSame($jmeno, (int) $users->findByLogin('spravce@example.cz')['id']);
    },
];
