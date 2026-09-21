<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Http\Csrf;
use App\Core\Http\HttpException;
use App\Core\Http\Request;

/**
 * Přihlašování a session správy.
 *
 * Převzato z jádra klientské aplikace, bez EventBusu (zásahy loguje auditní
 * log správy, přihlášení stačí v logu). „Zůstat přihlášen" zůstává — správce
 * má správu otevřenou celý den a přihlašuje se z jednoho dvou zařízení;
 * rotující selector+validator cookie je bezpečnější než dlouhá session.
 * V produkci navíc před aplikací stojí HTTP autentizace nebo IP filtr.
 *
 * V session je kromě ID uživatele i „auth hash" odvozený z hashe hesla —
 * změna hesla odhlásí všechny ostatní relace.
 *
 * Přihlásit se jde jménem i e-mailem účtu (`UserRepository::findByLogin()`).
 */
final class Auth
{
    private const SESSION_USER = 'user_id';
    private const SESSION_HASH = 'auth_hash';
    private const SESSION_ACTIVITY = 'last_activity';

    /**
     * Nečinnost, po které session vyprší.
     *
     * Na přání uživatele měsíc — přihlašování z domácích PC
     * dvěma lidmi, riziko odloženého odhlášení je přijatelné. Stejná
     * hodnota jako `lifetime` cookie session v `Kernel::startSession()`
     * (ta jinak zanikne se zavřením prohlížeče, ať by IDLE_TIMEOUT říkal
     * cokoli) a jako `RememberMe::LIFETIME_DAYS` — všechny tři drží
     * přihlášení stejně dlouho, ať se použije kterýkoli mechanismus.
     */
    private const IDLE_TIMEOUT = 2592000;

    /** @var array<string, mixed>|null */
    private ?array $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly RememberMe $rememberMe,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function user(Request $request): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $this->user = $this->resolveFromSession() ?? $this->resolveFromCookie($request);

        return $this->user;
    }

    /**
     * Už vyřešený uživatel — pro šablony, které nemají request po ruce.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        return $this->resolved ? $this->user : $this->resolveAndRemember();
    }

    public function check(Request $request): bool
    {
        return $this->user($request) !== null;
    }

    /**
     * @param string $login přihlašovací jméno, nebo e-mail účtu
     *
     * @return array<string, mixed> přihlášený uživatel
     * @throws HttpException při neplatných údajích nebo překročení limitu
     */
    public function login(Request $request, string $login, string $password, bool $remember = false): array
    {
        $ip = $request->ip();
        $genericError = 'Nesprávné přihlašovací jméno nebo heslo.';

        /**
         * Účet se hledá dřív, než se sáhne na počítadlo pokusů — schválně:
         * počítadlo pak běží na přihlašovacím jménu účtu, ne na tom, co kdo
         * napsal. Jinak by týž účet měl pětici pokusů na jméno a další pětici
         * na e-mail. Neexistující účet se počítá pod tím, co přišlo.
         */
        $user = $this->users->findByLogin($login);
        $limiterKey = $user !== null ? (string) $user['username'] : $login;

        if ($this->rateLimiter->tooManyAttempts($limiterKey, $ip)) {
            throw HttpException::tooManyRequests(
                'Po několika neúspěšných pokusech se přihlášení dočasně zablokovalo. Zkuste to později.'
            );
        }

        $verified = $user !== null && password_verify($password, (string) $user['password_hash']);

        // Konstantní práce i pro neexistující účet — jinak by šlo z doby
        // odpovědi poznat, které přihlašovací jméno existuje.
        if ($user === null) {
            password_verify($password, '$2y$12$usesomesillystringfooooooooooooooooooooooooooooooooooooooo');
        }

        if (!$verified || (int) $user['is_active'] !== 1) {
            $this->rateLimiter->record($limiterKey, $ip, false);

            throw HttpException::validation($genericError, ['password' => $genericError]);
        }

        if (PasswordPolicy::needsRehash((string) $user['password_hash'])) {
            $newHash = PasswordPolicy::hash($password);
            $this->users->update((int) $user['id'], ['password_hash' => $newHash]);
            $user['password_hash'] = $newHash;
        }

        $this->startSessionForUser($user);
        $this->rateLimiter->clear($limiterKey);
        $this->rateLimiter->record($limiterKey, $ip, true);
        $this->users->touchLastLogin((int) $user['id']);

        // Správa nemá cron — úklid starých pokusů a prošlých remember tokenů
        // se veze s úspěšným přihlášením (pár řádků denně, jeden DELETE).
        $this->rateLimiter->purge();
        $this->rememberMe->purgeExpired();

        if ($remember) {
            $this->rememberMe->issue((int) $user['id'], $request->isSecure());
        }

        $this->user = $user;
        $this->resolved = true;

        return $user;
    }

    public function logout(Request $request): void
    {
        $this->rememberMe->forget($request);

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->user = null;
        $this->resolved = true;
    }

    /** @param array<string, mixed> $user */
    private function startSessionForUser(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_USER] = (int) $user['id'];
        $_SESSION[self::SESSION_HASH] = $this->authHash($user);
        $_SESSION[self::SESSION_ACTIVITY] = time();

        $this->csrf->rotate();
    }

    /** @return array<string, mixed>|null */
    private function resolveAndRemember(): ?array
    {
        $this->resolved = true;

        return $this->user = $this->resolveFromSession();
    }

    /** @return array<string, mixed>|null */
    private function resolveFromSession(): ?array
    {
        $userId = $_SESSION[self::SESSION_USER] ?? null;
        if (!is_int($userId) && !is_numeric($userId)) {
            return null;
        }

        $lastActivity = (int) ($_SESSION[self::SESSION_ACTIVITY] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > self::IDLE_TIMEOUT) {
            $_SESSION = [];

            return null;
        }

        $user = $this->users->find((int) $userId);
        if ($user === null || (int) $user['is_active'] !== 1) {
            $_SESSION = [];

            return null;
        }

        // Změna hesla (kdekoli) zneplatní ostatní relace.
        $expected = (string) ($_SESSION[self::SESSION_HASH] ?? '');
        if (!hash_equals($this->authHash($user), $expected)) {
            $_SESSION = [];

            return null;
        }

        $_SESSION[self::SESSION_ACTIVITY] = time();

        return $user;
    }

    /** @return array<string, mixed>|null */
    private function resolveFromCookie(Request $request): ?array
    {
        $userId = $this->rememberMe->attempt($request);
        if ($userId === null) {
            return null;
        }

        $user = $this->users->find($userId);
        if ($user === null || (int) $user['is_active'] !== 1) {
            $this->rememberMe->forgetAll($userId);

            return null;
        }

        $this->startSessionForUser($user);

        return $user;
    }

    /** @param array<string, mixed> $user */
    private function authHash(array $user): string
    {
        return hash('sha256', $user['id'] . '|' . $user['password_hash']);
    }
}
