<?php

declare(strict_types=1);

/**
 * Uživatelé: povinné dvoufázové přihlášení (Kernel pustí účet bez
 * spárovaného telefonu jen na párování), úprava účtů a výjimka
 * zakládajícího účtu, na který smí sahat jen on sám.
 */

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

use App\Core\Auth\Totp;
use App\Core\Kernel;

/** Další účet se spárovaným telefonem. */
function usersTestAccount(Kernel $kernel, string $username): int
{
    $now = date('Y-m-d H:i:s');

    return $kernel->db()->insert('users', [
        'username' => $username,
        'email' => $username . '@test.cz',
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'is_active' => 1,
        'theme' => 'auto',
        'role' => 'technician',
        'totp_secret' => $kernel->secrets()->encrypt(Totp::generateSecret()),
        'totp_enabled_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/** Přihlásit „nasucho" jiný účet (stejně jako fixture). */
function usersTestLoginAs(Kernel $kernel, int $userId): void
{
    $user = $kernel->users()->find($userId) ?? [];

    $_SESSION['user_id'] = $userId;
    $_SESSION['auth_hash'] = hash('sha256', $user['id'] . '|' . $user['password_hash']);
    $_SESSION['last_activity'] = time();
}

return [
    'účet bez spárovaného telefonu se dostane jen na párování' => function (): void {
        [$kernel] = loggedInKernel('Novak');
        $userId = (int) $kernel->db()->scalar('SELECT MIN(id) FROM users');
        $kernel->users()->update($userId, ['totp_secret' => null, 'totp_enabled_at' => null]);

        $response = kernelRequest($kernel, 'GET', '/weby');
        assertSame(302, $response->status());
        assertContainsString('/nastaveni/dvoufazove', $response->headers()['Location'] ?? '');

        $response = kernelRequest($kernel, 'GET', '/nastaveni/dvoufazove');
        assertSame(200, $response->status());
        assertContainsString('data-qr="otpauth://totp/', $response->body());
        assertContainsString('Odhlásit se', $response->body());
    },

    'se spárovaným telefonem aplikace funguje normálně' => function (): void {
        [$kernel] = loggedInKernel('Novak');

        assertSame(200, kernelRequest($kernel, 'GET', '/nastaveni/uzivatele')->status());
    },

    'vlastník upraví kolegu včetně přihlašovacího jména a role' => function (): void {
        [$kernel, $token] = loggedInKernel('Vlastnik');
        $colleague = usersTestAccount($kernel, 'kolega');

        assertSame(200, kernelRequest($kernel, 'GET', '/nastaveni/uzivatele/' . $colleague . '/upravit')->status());

        $response = kernelRequest($kernel, 'POST', '/nastaveni/uzivatele/' . $colleague . '/upravit', [
            '_token' => $token, 'name' => 'Petr Kolega', 'username' => 'petr', 'email' => 'petr@test.cz', 'role' => 'accounts',
        ]);

        assertSame(302, $response->status());
        $row = $kernel->users()->find($colleague) ?? [];
        assertSame('petr', $row['username']);
        assertSame('accounts', $row['role']);
        assertSame('Petr Kolega', $row['name']);
    },

    'kolega nesmí upravit, pozastavit ani odpárovat vlastníka' => function (): void {
        [$kernel, $token] = loggedInKernel('Vlastnik');
        $ownerId = (int) $kernel->users()->firstUserId();
        $colleague = usersTestAccount($kernel, 'kolega');
        usersTestLoginAs($kernel, $colleague);

        assertSame(403, kernelRequest($kernel, 'GET', '/nastaveni/uzivatele/' . $ownerId . '/upravit')->status());
        assertSame(403, kernelRequest($kernel, 'POST', '/nastaveni/uzivatele/' . $ownerId . '/upravit', [
            '_token' => $token, 'username' => 'prevzato', 'email' => 'utocnik@test.cz', 'role' => 'admin',
        ])->status());

        kernelRequest($kernel, 'POST', '/nastaveni/uzivatele/' . $ownerId . '/dvoufazove/vypnout', ['_token' => $token]);
        kernelRequest($kernel, 'POST', '/nastaveni/uzivatele/' . $ownerId . '/pozastavit', ['_token' => $token]);

        $owner = $kernel->users()->find($ownerId) ?? [];
        assertSame('vlastnik', $owner['username'], 'Jméno vlastníka se změnilo');
        assertTrue($owner['totp_enabled_at'] !== null, 'Vlastníkovi se zrušilo spárování');
        assertSame(1, (int) $owner['is_active'], 'Vlastník je pozastavený');
    },

    'kolega smí upravit jiného kolegu' => function (): void {
        [$kernel, $token] = loggedInKernel('Vlastnik');
        $colleague = usersTestAccount($kernel, 'kolega');
        $third = usersTestAccount($kernel, 'treti');
        usersTestLoginAs($kernel, $colleague);

        $response = kernelRequest($kernel, 'POST', '/nastaveni/uzivatele/' . $third . '/upravit', [
            '_token' => $token, 'username' => 'treti', 'email' => 'treti@test.cz', 'role' => 'admin',
        ]);

        assertSame(302, $response->status());
        assertSame('admin', $kernel->users()->find($third)['role'] ?? null);
    },

    'přihlašovací jméno ani e-mail nesmí patřit jinému účtu' => function (): void {
        [$kernel, $token] = loggedInKernel('Vlastnik');
        $colleague = usersTestAccount($kernel, 'kolega');

        // Jméno kolegy = cizí e-mail.
        $response = kernelRequest($kernel, 'POST', '/nastaveni/uzivatele/' . $colleague . '/upravit', [
            '_token' => $token, 'username' => 'kolega', 'email' => 'vlastnik@test.cz', 'role' => 'admin',
        ]);
        assertSame(422, $response->status());
        assertContainsString('patří jinému účtu', $response->body());

        $response = kernelRequest($kernel, 'POST', '/nastaveni/uzivatele/' . $colleague . '/upravit', [
            '_token' => $token, 'username' => 'Vlastnik', 'email' => 'kolega@test.cz', 'role' => 'admin',
        ]);
        assertSame(422, $response->status(), 'Jméno vlastníka (velkými písmeny) prošlo');
    },

    'vlastní účet: změna přihlašovacího jména a role' => function (): void {
        [$kernel, $token] = loggedInKernel('Vlastnik');
        $ownerId = (int) $kernel->users()->firstUserId();

        kernelRequest($kernel, 'POST', '/nastaveni/ucet', [
            '_token' => $token, 'name' => 'Jan Vašák', 'username' => 'janvasak', 'email' => 'jan@test.cz', 'role' => 'technician',
        ]);

        $row = $kernel->users()->find($ownerId) ?? [];
        assertSame('janvasak', $row['username']);
        assertSame('technician', $row['role']);
        // Změna jména neodhlásí — session drží ID a otisk hesla, ne jméno.
        assertSame(200, kernelRequest($kernel, 'GET', '/nastaveni/uzivatele')->status());
    },

    'Můj účet jedním tlačítkem: prázdné heslo nemění, chybné heslo neuloží nic' => function (): void {
        [$kernel, $token] = loggedInKernel('Vlastnik');
        $ownerId = (int) $kernel->users()->firstUserId();
        $hash = (string) ($kernel->users()->find($ownerId)['password_hash'] ?? '');
        $fields = ['_token' => $token, 'name' => '', 'username' => 'vlastnik', 'email' => 'vlastnik@test.cz', 'role' => 'admin'];

        // Heslo nevyplněné — údaje se uloží, heslo zůstane, nikdo se neodhlásí.
        $response = kernelRequest($kernel, 'POST', '/nastaveni/ucet', ['name' => 'Jan'] + $fields);
        assertContainsString('/nastaveni/uzivatele', $response->headers()['Location'] ?? '');
        assertSame('Jan', $kernel->users()->find($ownerId)['name'] ?? null);
        assertSame($hash, $kernel->users()->find($ownerId)['password_hash'] ?? null);

        // Hesla nesouhlasí — neuloží se ani jméno.
        kernelRequest($kernel, 'POST', '/nastaveni/ucet', ['name' => 'Jiné', 'password' => 'nove-heslo-12345', 'password_confirm' => 'jine-heslo-12345'] + $fields);
        assertSame('Jan', $kernel->users()->find($ownerId)['name'] ?? null, 'Uložila se půlka formuláře');

        // Platné heslo — změní se a vede na nové přihlášení.
        $response = kernelRequest($kernel, 'POST', '/nastaveni/ucet', ['password' => 'nove-heslo-12345', 'password_confirm' => 'nove-heslo-12345'] + $fields);
        assertContainsString('/prihlaseni', $response->headers()['Location'] ?? '');
        assertTrue(password_verify('nove-heslo-12345', (string) ($kernel->users()->find($ownerId)['password_hash'] ?? '')));
    },
];
