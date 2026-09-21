<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * CSRF token vázaný na session.
 *
 * Stará aplikace odvozovala nonce z času a ID uživatele bez tajného klíče,
 * takže šel dopočítat. Tady je to náhodných 32 bajtů uložených v session,
 * rotovaných při přihlášení, porovnávaných timing-safe. Ověřuje se centrálně
 * v Kernelu na každé mutaci — ne v jednotlivých handlerech.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf';
    public const FIELD = '_token';
    public const HEADER = 'x-csrf-token';

    public function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $this->rotate();
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }

    public function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    public function validate(Request $request): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $given = $request->input(self::FIELD);
        if (!is_string($given) || $given === '') {
            $given = $request->header(self::HEADER);
        }

        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }

    /** Hidden input do formuláře. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8'),
        );
    }
}
