<?php

declare(strict_types=1);

/**
 * Zapomenuté heslo — tokeny (`PasswordReset`, mirror `RememberMe`) proti
 * testovací databázi. Odesílání e-mailu je pokryté zvlášť v MailerTest.
 */

use App\Core\Auth\PasswordPolicy;
use App\Core\Auth\PasswordReset;
use App\Core\Auth\UserRepository;

return [
    'migrace přidá e-mail k účtu a tabulku password_resets' => function (): void {
        $db = freshTestDb();

        $tables = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $db->select('SHOW TABLES'),
        );
        assertTrue(in_array('password_resets', $tables, true), 'Chybí tabulka password_resets');

        $columns = array_map(
            static fn (array $row): string => (string) $row['Field'],
            $db->select('SHOW COLUMNS FROM users'),
        );
        assertTrue(in_array('email', $columns, true), 'Chybí sloupec users.email');
        assertTrue(in_array('name', $columns, true), 'Chybí sloupec users.name');

        $auditColumns = array_map(
            static fn (array $row): string => (string) $row['Field'],
            $db->select('SHOW COLUMNS FROM audit_log'),
        );
        assertTrue(in_array('user_id', $auditColumns, true), 'Chybí sloupec audit_log.user_id');
        assertTrue(in_array('user_name', $auditColumns, true), 'Chybí sloupec audit_log.user_name');
    },

    'findByEmail najde účet podle e-mailu' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);
        $users->create('spravce', PasswordPolicy::hash('puvodni-heslo-123'), 'spravce@example.cz');

        $found = $users->findByEmail('spravce@example.cz');
        assertTrue($found !== null, 'Účet se podle e-mailu nenašel');
        assertSame('spravce', (string) $found['username']);

        assertSame(null, $users->findByEmail('neexistuje@example.cz'));
    },

    'firstUserId ukazuje na zakládající účet, ne na abecedně první' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);

        $first = $users->create('zzz-pozdejsi-jmeno', PasswordPolicy::hash('puvodni-heslo-123'));
        $users->create('aaa-druhy-ucet', PasswordPolicy::hash('puvodni-heslo-123'));

        // all() řadí podle jména (pro výpis), firstUserId musí jít podle
        // založení (id), ne podle abecedy — jinak by "vlastník" mohl vyjít
        // na účet, který založili druhý.
        assertSame($first, $users->firstUserId());
    },

    'displayName vrací jméno, jinak přihlašovací jméno' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);

        $id = $users->create('janvasak', PasswordPolicy::hash('puvodni-heslo-123'));
        assertSame('janvasak', UserRepository::displayName($users->find($id)));

        $users->update($id, ['name' => 'Jan Vašák']);
        assertSame('Jan Vašák', UserRepository::displayName($users->find($id)));

        // Jméno jen z mezer se počítá jako nevyplněné.
        $users->update($id, ['name' => '   ']);
        assertSame('janvasak', UserRepository::displayName($users->find($id)));
    },

    'audit_log ukládá jako autora zásahu jméno, ne přihlašovací jméno' => function (): void {
        $db = freshTestDb();
        $_SESSION = [];

        $users = new UserRepository($db);
        $id = $users->create('janvasak', PasswordPolicy::hash('puvodni-heslo-123'));
        // Jméno se nastaví PŘED přihlášením — Auth::current() po loginu drží
        // uživatele v paměti, pozdější změna v DB by se do session nepromítla.
        $users->update($id, ['name' => 'Jan Vašák']);

        $auth = new \App\Core\Auth\Auth(
            $users,
            new \App\Core\Auth\RememberMe($db),
            new \App\Core\Auth\LoginRateLimiter(new \App\Core\Security\RateLimiter($db)),
            new \App\Core\Http\Csrf(),
        );
        $auth->login(
            new \App\Core\Http\Request(method: 'POST', path: '/prihlaseni', server: ['REMOTE_ADDR' => '10.0.0.9']),
            'janvasak',
            'puvodni-heslo-123',
        );

        $audit = new \App\Core\Audit\AuditLog($db, $auth);
        $audit->record(null, 'Testovací web', \App\Core\Audit\AuditLog::ACTION_USER, true, 'test');

        $record = $audit->latest()[0];
        assertSame('Jan Vašák', (string) $record['user_name']);
    },

    'token se založí, ověří a jde použít jen jednou' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);
        $reset = new PasswordReset($db);

        $id = $users->create('spravce', PasswordPolicy::hash('puvodni-heslo-123'), 'spravce@example.cz');
        $token = $reset->issue($id);

        assertSame($id, $reset->verify($token));
        assertSame($id, $reset->consume($token));

        // Druhé použití stejného tokenu už neprojde — je jednorázový.
        assertSame(null, $reset->verify($token));
        assertSame(null, $reset->consume($token));
    },

    'prošlý token neprojde' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);
        $reset = new PasswordReset($db);

        $id = $users->create('spravce', PasswordPolicy::hash('puvodni-heslo-123'));
        $token = $reset->issue($id);
        [$selector] = explode(':', $token, 2);

        $db->update(
            'password_resets',
            ['expires_at' => date('Y-m-d H:i:s', time() - 3600)],
            ['selector' => $selector],
        );

        assertSame(null, $reset->verify($token));
        assertSame(null, $reset->consume($token));
    },

    'špatný validator neprojde (platný selector, cizí token)' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);
        $reset = new PasswordReset($db);

        $id = $users->create('spravce', PasswordPolicy::hash('puvodni-heslo-123'));
        $token = $reset->issue($id);
        [$selector] = explode(':', $token, 2);

        assertSame(null, $reset->verify($selector . ':' . str_repeat('ab', 32)));
    },

    'invalidateFor zruší všechny čekající tokeny uživatele' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);
        $reset = new PasswordReset($db);

        $id = $users->create('spravce', PasswordPolicy::hash('puvodni-heslo-123'));
        $reset->issue($id);
        $tokenB = $reset->issue($id);

        $reset->invalidateFor($id);

        assertSame(null, $reset->verify($tokenB));
        assertSame(
            0,
            (int) $db->scalar('SELECT COUNT(*) FROM password_resets WHERE user_id = :u', ['u' => $id]),
        );
    },

    'purgeExpired smaže jen prošlé tokeny' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);
        $reset = new PasswordReset($db);

        $id = $users->create('spravce', PasswordPolicy::hash('puvodni-heslo-123'));
        $fresh = $reset->issue($id);
        $stale = $reset->issue($id);

        [$staleSelector] = explode(':', $stale, 2);
        $db->update(
            'password_resets',
            ['expires_at' => date('Y-m-d H:i:s', time() - 3600)],
            ['selector' => $staleSelector],
        );

        $reset->purgeExpired();

        assertSame($id, $reset->verify($fresh));
        assertSame(
            1,
            (int) $db->scalar('SELECT COUNT(*) FROM password_resets WHERE user_id = :u', ['u' => $id]),
        );
    },
];
