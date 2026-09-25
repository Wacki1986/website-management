<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * Jak číst SEO skóre 0–100: hranice dobré / průměrné podle pluginu, který
 * ho spočítal — stejné, jaké plugin ukazuje barvou v editoru stránky.
 */
final class SeoScore
{
    /** @var array<string, array{good: int, ok: int}> */
    public const THRESHOLDS = [
        'rank-math' => ['good' => 80, 'ok' => 50],
        'yoast' => ['good' => 71, 'ok' => 41],
    ];

    public const PLUGIN_NAMES = ['rank-math' => 'Rank Math SEO', 'yoast' => 'Yoast SEO'];

    /** Skóre z pluginu jako číslo 0–100, nebo null. */
    public static function average(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int) $value)) : null;
    }

    /** @return array{good: int, ok: int} */
    public static function thresholds(string $plugin): array
    {
        return self::THRESHOLDS[$plugin] ?? self::THRESHOLDS['rank-math'];
    }

    /** Tón stavu (`ok` | `warning` | `error`) — tečka u skóre. */
    public static function tone(int $score, string $plugin): string
    {
        $limits = self::thresholds($plugin);

        return match (true) {
            $score >= $limits['good'] => 'ok',
            $score >= $limits['ok'] => 'warning',
            default => 'error',
        };
    }

    /** Slovní hodnocení ke skóre — stav se nikdy nesděluje jen barvou. */
    public static function word(int $score, string $plugin): string
    {
        return match (self::tone($score, $plugin)) {
            'ok' => 'dobré',
            'warning' => 'průměrné',
            default => 'slabé',
        };
    }
}
