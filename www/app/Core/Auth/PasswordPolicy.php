<?php

declare(strict_types=1);

namespace App\Core\Auth;

/**
 * Jednotná pravidla pro hesla.
 *
 * Stará aplikace měla tři různé limity (registrace 8, reset 6, admin žádný) —
 * tady je pravidlo na jednom místě a používá ho každá cesta, kudy heslo vzniká.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    /**
     * Vrací chybovou hlášku, nebo null když je heslo v pořádku.
     *
     * `?string` musí být zapsané explicitně — implicitní nullable (`string $x = null`)
     * je od PHP 8.4 deprecated.
     */
    public static function validate(string $password, ?string $confirmation = null): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return sprintf('Heslo musí mít alespoň %d znaků.', self::MIN_LENGTH);
        }

        if (mb_strlen($password) > 200) {
            return 'Heslo je příliš dlouhé (maximálně 200 znaků).';
        }

        if (trim($password) === '') {
            return 'Heslo nesmí být tvořeno jen mezerami.';
        }

        if ($confirmation !== null && !hash_equals($password, $confirmation)) {
            return 'Hesla se neshodují.';
        }

        return null;
    }

    public static function hash(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }

        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID);
        }

        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }
}
