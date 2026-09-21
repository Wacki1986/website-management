<?php

declare(strict_types=1);

namespace App\Core\Sites;

/**
 * API klíč webu — pověření, kterým se hub prokazuje pluginu MEDIAGRAFIK
 * Monitor na straně WordPressu.
 *
 * Tvar `mg_live_` + 32 znaků [A-Za-z0-9]: předpona říká na první pohled,
 * co to je (v logu, v e-mailu, ve schránce), a 32 náhodných znaků je
 * ~190 bitů — hádat nejde. Hub klíč ukládá šifrovaně (`Secrets`), plugin
 * jen jeho SHA-256; v UI se ukazují poslední 4 znaky.
 */
final class ApiKey
{
    public const PREFIX = 'mg_live_';
    public const LENGTH = 32;

    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $key = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return self::PREFIX . $key;
    }

    public static function isValid(string $key): bool
    {
        return preg_match('/^' . preg_quote(self::PREFIX, '/') . '[A-Za-z0-9]{' . self::LENGTH . '}$/', $key) === 1;
    }

    /** Poslední 4 znaky pro zobrazení (`mg_live_••••4f7a`). */
    public static function hint(string $key): string
    {
        return substr($key, -4);
    }

    public static function masked(string $hint): string
    {
        return self::PREFIX . '••••••••••••••••••••' . $hint;
    }
}
