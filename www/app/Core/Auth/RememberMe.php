<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Db\Connection;
use App\Core\Http\Request;

/**
 * Trvalé přihlášení (selector + validator).
 *
 * Jediná část staré aplikace, kterou přebíráme prakticky beze změny — byla
 * udělaná správně: v databázi leží jen hash validatoru, porovnává se
 * timing-safe, validator se při každém použití mění a při pokusu o zneužití
 * se zruší všechny tokeny uživatele.
 */
final class RememberMe
{
    public const COOKIE = 'app_remember';
    private const LIFETIME_DAYS = 30;

    /**
     * Jak dlouho po rotaci se přijme i PŘEDCHOZÍ validator (sekundy).
     *
     * Dva souběžné requesty z téhož prohlížeče: první zrotuje a pošle novou
     * cookie, druhý byl vyslán ještě se starou. Bez lhůty se ten druhý
     * vyhodnotil jako krádež a smazal všechny tokeny — uživatel byl
     * odhlášený s navenek platnou cookie. Starý validator v krátkém okně
     * po rotaci je souběh, ne útok; po lhůtě platí původní přísnost.
     */
    private const ROTATION_GRACE = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly string $cookiePath = '/',
    ) {
    }

    public function issue(int $userId, bool $secure): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + (self::LIFETIME_DAYS * 86400);

        $this->db->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->setCookie($selector . ':' . $validator, $expires, $secure);
    }

    /** Vrátí ID uživatele, pokud je cookie platná (a rovnou ji zrotuje). */
    public function attempt(Request $request): ?int
    {
        $cookie = $request->cookie(self::COOKIE);
        if ($cookie === null || !str_contains($cookie, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        $token = $this->db->selectOne(
            'SELECT * FROM remember_tokens WHERE selector = :selector',
            ['selector' => $selector],
        );

        if ($token === null) {
            $this->clearCookie($request->isSecure());

            return null;
        }

        if (strtotime((string) $token['expires_at']) < time()) {
            $this->db->delete('remember_tokens', ['id' => (int) $token['id']]);
            $this->clearCookie($request->isSecure());

            return null;
        }

        $incoming = hash('sha256', $validator);

        if (!hash_equals((string) $token['validator_hash'], $incoming)) {
            // Souběh rotace: request vyslaný se starou cookie těsně po tom,
            // co jiný request zrotoval. Přijmout BEZ rotace a bez cookie —
            // novou už prohlížeč drží z první odpovědi.
            $rotatedAt = strtotime((string) ($token['rotated_at'] ?? ''));
            $previous = (string) ($token['previous_hash'] ?? '');

            if ($previous !== '' && $rotatedAt !== false
                && (time() - $rotatedAt) <= self::ROTATION_GRACE
                && hash_equals($previous, $incoming)) {
                return (int) $token['user_id'];
            }

            // Platný selector se špatným validatorem = cizí pokus. Zneplatníme
            // všechny tokeny uživatele, ne jen tenhle.
            $this->forgetAll((int) $token['user_id']);
            $this->clearCookie($request->isSecure());

            return null;
        }

        // Rotace: použitý validator se už nikdy nesmí hodit. Předchozí hash
        // se chvíli drží kvůli souběhu (viz ROTATION_GRACE).
        $newValidator = bin2hex(random_bytes(32));
        $expires = time() + (self::LIFETIME_DAYS * 86400);

        $this->db->update('remember_tokens', [
            'validator_hash' => hash('sha256', $newValidator),
            'previous_hash' => (string) $token['validator_hash'],
            'rotated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'last_used_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $token['id']]);

        $this->setCookie($selector . ':' . $newValidator, $expires, $request->isSecure());

        return (int) $token['user_id'];
    }

    public function forget(Request $request): void
    {
        $cookie = $request->cookie(self::COOKIE);

        if ($cookie !== null && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            $this->db->delete('remember_tokens', ['selector' => $selector]);
        }

        $this->clearCookie($request->isSecure());
    }

    public function forgetAll(int $userId): void
    {
        $this->db->delete('remember_tokens', ['user_id' => $userId]);
    }

    public function purgeExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM remember_tokens WHERE expires_at < :now',
            ['now' => date('Y-m-d H:i:s')],
        );
    }

    private function setCookie(string $value, int $expires, bool $secure): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => $this->cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(bool $secure): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $this->cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
