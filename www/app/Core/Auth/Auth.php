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
 *
 * Účet se zapnutým dvoufázovým přihlášením (`TwoFactor`) má po hesle ještě
 * druhý krok — kód z aplikace v telefonu (`completeSecondFactor()`).
 */
final class Auth
{
    private const SESSION_USER = 'user_id';
    private const SESSION_HASH = 'auth_hash';
    private const SESSION_ACTIVITY = 'last_activity';

    /** Heslo sedělo, čeká se na kód z telefonu (viz `login()`). */
    private const SESSION_PENDING = 'two_factor_pending';

    /** Jak dlouho po zadání hesla se dá opsat kód z telefonu (sekundy). */
    private const PENDING_TIMEOUT = 600;

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
        private readonly TwoFactor $twoFactor,
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
     * @return array<string, mixed> uživatel se správným heslem — u účtu
     *         s dvoufázovým přihlášením ještě NEpřihlášený, viz `awaitsSecondFactor()`
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

        $this->rateLimiter->clear($limiterKey);
        $this->rateLimiter->record($limiterKey, $ip, true);

        /**
         * Účet s dvoufázovým přihlášením po hesle ještě přihlášený není —
         * session si jen poznamená, kdo heslo zadal správně, a stránka
         * `prihlaseni/overeni` chce kód z telefonu. „Zůstat přihlášen" se
         * odloží taky: cookie se vydá až po kódu, jinak by druhý krok šel
         * obejít zavřením prohlížeče.
         */
        if ($this->twoFactor->isEnabled($user)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            $_SESSION[self::SESSION_PENDING] = [
                'user_id' => (int) $user['id'],
                'hash' => $this->authHash($user),
                'remember' => $remember,
                'at' => time(),
            ];

            return $user;
        }

        return $this->finishLogin($request, $user, $remember);
    }

    /**
     * Čeká se po správném hesle na kód z telefonu?
     *
     * Rozhoduje controller přihlášení — podle toho pošle na stránku
     * s kódem, nebo rovnou do aplikace.
     */
    public function awaitsSecondFactor(): bool
    {
        return $this->pendingUser() !== null;
    }

    /**
     * Druhý krok přihlášení: kód z aplikace, nebo záložní kód.
     *
     * @return array<string, mixed> přihlášený uživatel
     * @throws HttpException 422 špatný kód, 429 moc pokusů (pak se musí znovu
     *                       zadat heslo), 410 vypršelo / nic nečeká
     */
    public function completeSecondFactor(Request $request, string $code): array
    {
        $user = $this->pendingUser();

        if ($user === null) {
            unset($_SESSION[self::SESSION_PENDING]);

            throw new HttpException(410, 'Přihlášení vypršelo. Zadejte znovu jméno a heslo.');
        }

        $username = (string) $user['username'];

        if ($this->rateLimiter->tooManyCodeAttempts($username)) {
            // Po pětici špatných kódů se začíná od hesla — ať se kód nedá
            // hádat donekonečna s jedním správně zadaným heslem.
            unset($_SESSION[self::SESSION_PENDING]);

            throw HttpException::tooManyRequests(
                'Po několika špatných kódech se přihlášení dočasně zablokovalo. Zkuste to za čtvrt hodiny znovu od hesla.'
            );
        }

        if (!$this->twoFactor->verify($user, $code)) {
            $this->rateLimiter->recordCode($username, false);

            throw HttpException::validation('Kód nesouhlasí. Opište aktuální kód z aplikace.', ['code' => 'Kód nesouhlasí.']);
        }

        $this->rateLimiter->recordCode($username, true);
        $remember = (bool) ($_SESSION[self::SESSION_PENDING]['remember'] ?? false);
        unset($_SESSION[self::SESSION_PENDING]);

        // Záložní kód se mohl právě spotřebovat — do session jde čerstvý řádek.
        return $this->finishLogin($request, $this->users->find((int) $user['id']) ?? $user, $remember);
    }

    /**
     * Konec přihlášení — po hesle, nebo po kódu z telefonu.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function finishLogin(Request $request, array $user, bool $remember): array
    {
        $this->startSessionForUser($user);
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

    /**
     * Účet, který zadal správné heslo a čeká na kód — nebo null.
     *
     * Čekání platí deset minut a jen dokud se nezměnilo heslo účtu
     * (stejný otisk jako u přihlášené session).
     *
     * @return array<string, mixed>|null
     */
    private function pendingUser(): ?array
    {
        $pending = $_SESSION[self::SESSION_PENDING] ?? null;

        if (!is_array($pending) || time() - (int) ($pending['at'] ?? 0) > self::PENDING_TIMEOUT) {
            return null;
        }

        $user = $this->users->find((int) ($pending['user_id'] ?? 0));

        if ($user === null || (int) $user['is_active'] !== 1
            || !hash_equals($this->authHash($user), (string) ($pending['hash'] ?? ''))) {
            return null;
        }

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
