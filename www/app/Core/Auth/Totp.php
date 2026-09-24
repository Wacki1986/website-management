<?php

declare(strict_types=1);

namespace App\Core\Auth;

use SensitiveParameter;

/**
 * Jednorázové kódy z aplikace v telefonu (TOTP, RFC 6238).
 *
 * Tentýž výpočet, který dělá Google Authenticator, Microsoft Authenticator
 * nebo 1Password: ze sdíleného tajemství a aktuálního času vznikne šestimístný
 * kód, platný 30 sekund. Parametry jsou ty výchozí (SHA-1, 6 číslic,
 * 30 s) — jiné některé aplikace tiše ignorují a kódy by pak nesouhlasily.
 *
 * Třída nic neukládá; kam tajemství patří a jak se hlídá opakované použití
 * kódu, řeší `TwoFactor`.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;

    /**
     * Kolik 30s kroků vedle aktuálního se ještě přijme.
     *
     * Jeden krok na každou stranu: hodiny telefonu se rozcházejí a kód
     * opsaný v 29. sekundě dorazí až v dalším kroku. Víc by zbytečně
     * rozšiřovalo okno pro hádání.
     */
    private const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nové tajemství — 20 náhodných bajtů (160 bitů, jako u SHA-1) v base32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Kód pro daný 30s krok (číslo kroku = unixový čas / 30). */
    public static function code(#[SensitiveParameter] string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);

        // „Dynamické zkrácení" z RFC 4226: poslední čtyři bity určí, odkud
        // se z hashe vezmou čtyři bajty.
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Ověří kód a vrátí krok, ke kterému patří (null = nesedí).
     *
     * Krok se vrací proto, aby si ho volající mohl zapamatovat: kód se smí
     * použít jen jednou, i když by v okně platil ještě minutu. Kroky
     * do `$lastStep` včetně se proto odmítají.
     */
    public static function verify(#[SensitiveParameter] string $secret, string $code, int $time, ?int $lastStep = null): ?int
    {
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $current = intdiv($time, self::PERIOD);

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }

            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Adresa `otpauth://`, kterou nese QR kód při párování.
     *
     * `issuer` se píše do adresy i jako parametr — starší aplikace čtou jen
     * jedno, nebo druhé; obojí zároveň je doporučení Google.
     */
    public static function provisioningUri(#[SensitiveParameter] string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** Tajemství po čtveřicích („ABCD EFGH …") — na ruční opsání do aplikace. */
    public static function formatSecret(#[SensitiveParameter] string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** Opsané tajemství může mít mezery, malá písmena nebo `=` na konci. */
    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[\s=-]/', '', $text));
        $bits = '';

        foreach (str_split($text) as $char) {
            $index = strpos(self::BASE32, $char);

            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
