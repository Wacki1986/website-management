<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PSR-4 autoloader bez Composeru.
 *
 * Jádro ani moduly nemají runtime závislosti, takže se aplikace obejde bez
 * vendor/autoload.php. Až přibude první balíček (F5 — web push), načte se
 * vendor/autoload.php vedle tohoto autoloaderu, ne místo něj.
 */
final class Autoloader
{
    /** @var array<string, string> prefix namespace => absolutní adresář */
    private static array $prefixes = [];

    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function addNamespace(string $prefix, string $baseDir): void
    {
        self::$prefixes[trim($prefix, '\\') . '\\'] = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
    }

    private static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}
