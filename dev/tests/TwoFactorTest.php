<?php

declare(strict_types=1);

/**
 * Dvoufázové přihlášení: výpočet kódů podle RFC 6238, jednorázovost kódu,
 * záložní kódy a druhý krok v `Auth` (bez kódu se nikdo nepřihlásí).
 */

use App\Core\Auth\Auth;
use App\Core\Auth\LoginRateLimiter;
use App\Core\Auth\PasswordPolicy;
use App\Core\Auth\RememberMe;
use App\Core\Auth\Totp;
use App\Core\Auth\TwoFactor;
use App\Core\Auth\UserRepository;
use App\Core\Db\Connection;
use App\Core\Http\Csrf;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Security\RateLimiter;
use App\Core\Security\Secrets;

const TWO_FACTOR_APP_KEY = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

/**
 * Účet se zapnutým dvoufázovým přihlášením.
 *
 * @return array{auth: Auth, twoFactor: TwoFactor, users: UserRepository, secret: string, codes: array<int, string>, userId: int}
 */
function twoFactorAccount(Connection $db): array
{
    $_SESSION = [];
    $users = new UserRepository($db);
    $twoFactor = new TwoFactor($users, new Secrets(TWO_FACTOR_APP_KEY));
    $userId = $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));
    $secret = Totp::generateSecret();

    $codes = $twoFactor->enable($userId, $secret, Totp::code($secret, intdiv(time(), Totp::PERIOD)));
    assertTrue($codes !== null, 'Zapnutí s platným kódem selhalo');

    return [
        'auth' => new Auth($users, new RememberMe($db), new LoginRateLimiter(new RateLimiter($db)), new Csrf(), $twoFactor),
        'twoFactor' => $twoFactor,
        'users' => $users,
        'secret' => $secret,
        'codes' => $codes,
        'userId' => $userId,
    ];
}

function twoFactorRequest(): Request
{
    return new Request(method: 'POST', path: '/prihlaseni', server: ['REMOTE_ADDR' => '10.0.0.2']);
}

/**
 * Kód, který projde hned po zapnutí: kód aktuálního kroku už je použitý
 * (zapnutí si ho zapamatovalo), další krok je ještě v okně.
 */
function nextCode(string $secret): string
{
    return Totp::code($secret, intdiv(time(), Totp::PERIOD) + 1);
}

return [
    'kódy odpovídají testovacím vektorům RFC 6238 (SHA-1)' => function (): void {
        // Tajemství z RFC je ASCII „12345678901234567890"; RFC uvádí 8 číslic,
        // aplikace používají posledních 6.
        $secret = Totp::base32Encode('12345678901234567890');

        assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        assertSame('005924', Totp::code($secret, intdiv(1234567890, 30)));
        assertSame('279037', Totp::code($secret, intdiv(2000000000, 30)));
    },

    'base32: tajemství přežije cestu tam a zpátky i s mezerami a malými písmeny' => function (): void {
        $bytes = random_bytes(20);
        $secret = Totp::base32Encode($bytes);

        assertSame(32, strlen($secret));
        assertSame($bytes, Totp::base32Decode($secret));
        assertSame($bytes, Totp::base32Decode(strtolower(Totp::formatSecret($secret))));
    },

    'ověření: okno ±1 krok, jinak ne; použitý krok podruhé neprojde' => function (): void {
        $secret = Totp::generateSecret();
        $time = 1_800_000_000;
        $step = intdiv($time, 30);

        assertSame($step, Totp::verify($secret, Totp::code($secret, $step), $time));
        assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $time));
        assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $time));
        assertSame(null, Totp::verify($secret, Totp::code($secret, $step + 2), $time));
        assertSame(null, Totp::verify($secret, Totp::code($secret, $step), $time, $step), 'Stejný kód dvakrát');
        assertSame(null, Totp::verify($secret, 'abcdef', $time));
    },

    'adresa pro QR kód nese tajemství a jméno aplikace' => function (): void {
        $uri = Totp::provisioningUri('ABCDEFGH', 'petra@mediagrafik.cz', TwoFactor::ISSUER);

        assertContainsString('otpauth://totp/', $uri);
        assertContainsString('secret=ABCDEFGH', $uri);
        assertContainsString('petra%40mediagrafik.cz', $uri);
        assertContainsString('issuer=Spr%C3%A1va%20web%C5%AF%20MEDIAGRAFIK', $uri);
    },

    'zapnutí se špatným kódem nic neuloží' => function (): void {
        $db = freshTestDb();
        $users = new UserRepository($db);
        $twoFactor = new TwoFactor($users, new Secrets(TWO_FACTOR_APP_KEY));
        $userId = $users->create('spravce', PasswordPolicy::hash('spravne-heslo-123'));

        assertSame(null, $twoFactor->enable($userId, Totp::generateSecret(), '000000x'));
        assertFalse($twoFactor->isEnabled($users->find($userId) ?? []));
    },

    'tajemství je v databázi šifrované, záložní kódy jen jako hash' => function (): void {
        $account = twoFactorAccount(freshTestDb());
        $row = $account['users']->find($account['userId']) ?? [];

        assertTrue(Secrets::isEncrypted((string) $row['totp_secret']), 'Tajemství není šifrované');
        assertFalse(str_contains((string) $row['totp_secret'], $account['secret']));
        assertSame(TwoFactor::RECOVERY_COUNT, count($account['codes']));
        assertFalse(str_contains((string) $row['recovery_codes'], str_replace('-', '', $account['codes'][0])));
    },

    'heslo samo nestačí: přihlášení čeká na kód z aplikace' => function (): void {
        $account = twoFactorAccount(freshTestDb());
        $auth = $account['auth'];

        $auth->login(twoFactorRequest(), 'spravce', 'spravne-heslo-123');

        assertTrue($auth->awaitsSecondFactor(), 'Nečeká se na kód');
        assertFalse(isset($_SESSION['user_id']), 'Session nese přihlášeného před zadáním kódu');

        $user = $auth->completeSecondFactor(twoFactorRequest(), nextCode($account['secret']));

        assertSame('spravce', $user['username']);
        assertSame($account['userId'], $_SESSION['user_id'] ?? null);
        assertFalse($auth->awaitsSecondFactor());
    },

    'špatný kód neprojde; stejný kód podruhé taky ne' => function (): void {
        $account = twoFactorAccount(freshTestDb());
        $auth = $account['auth'];
        $auth->login(twoFactorRequest(), 'spravce', 'spravne-heslo-123');

        $e = assertThrows(HttpException::class, fn () => $auth->completeSecondFactor(twoFactorRequest(), '123456x'));
        assertSame(422, $e->status());
        assertFalse(isset($_SESSION['user_id']));

        // Kód, který spotřebovalo už zapnutí (jeho krok je uložený).
        $used = Totp::code($account['secret'], (int) ($account['users']->find($account['userId'])['totp_last_step'] ?? 0));
        $e = assertThrows(HttpException::class, fn () => $auth->completeSecondFactor(twoFactorRequest(), $used));
        assertSame(422, $e->status(), 'Použitý kód prošel znovu');
    },

    'záložní kód projde jednou, pak už ne' => function (): void {
        $account = twoFactorAccount(freshTestDb());
        $auth = $account['auth'];
        $code = strtoupper($account['codes'][3]);

        $auth->login(twoFactorRequest(), 'spravce', 'spravne-heslo-123');
        $user = $auth->completeSecondFactor(twoFactorRequest(), $code);

        assertSame(TwoFactor::RECOVERY_COUNT - 1, $account['twoFactor']->remainingRecoveryCodes($user));

        $_SESSION = [];
        $auth->login(twoFactorRequest(), 'spravce', 'spravne-heslo-123');
        assertThrows(HttpException::class, fn () => $auth->completeSecondFactor(twoFactorRequest(), $code));
    },

    'pět špatných kódů: zablokuje se a začíná se znovu od hesla' => function (): void {
        $account = twoFactorAccount(freshTestDb());
        $auth = $account['auth'];
        $auth->login(twoFactorRequest(), 'spravce', 'spravne-heslo-123');

        for ($i = 0; $i < 5; $i++) {
            assertThrows(HttpException::class, fn () => $auth->completeSecondFactor(twoFactorRequest(), '000000'));
        }

        $e = assertThrows(HttpException::class, fn () => $auth->completeSecondFactor(twoFactorRequest(), nextCode($account['secret'])));
        assertSame(429, $e->status(), 'Správný kód po limitu nemá projít');
        assertFalse($auth->awaitsSecondFactor(), 'Čekání na kód mělo skončit');
    },

    'bez hesla není co ověřovat' => function (): void {
        $account = twoFactorAccount(freshTestDb());

        $e = assertThrows(HttpException::class, fn () => $account['auth']->completeSecondFactor(twoFactorRequest(), nextCode($account['secret'])));
        assertSame(410, $e->status());
    },

    '„zůstat přihlášen" se vydá až po kódu' => function (): void {
        $db = freshTestDb();
        $account = twoFactorAccount($db);
        $auth = $account['auth'];

        $auth->login(twoFactorRequest(), 'spravce', 'spravne-heslo-123', true);
        assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM remember_tokens'), 'Cookie vydaná před kódem');

        $auth->completeSecondFactor(twoFactorRequest(), nextCode($account['secret']));
        assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM remember_tokens'));
    },

    'vypnutí smaže tajemství i záložní kódy; přihlášení pak stačí heslem' => function (): void {
        $account = twoFactorAccount(freshTestDb());
        $account['twoFactor']->disable($account['userId']);
        $row = $account['users']->find($account['userId']) ?? [];

        assertFalse($account['twoFactor']->isEnabled($row));
        assertSame(null, $row['recovery_codes']);

        $account['auth']->login(twoFactorRequest(), 'spravce', 'spravne-heslo-123');
        assertSame($account['userId'], $_SESSION['user_id'] ?? null);
    },
];
