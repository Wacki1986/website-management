<?php

declare(strict_types=1);

namespace App\Core\Sites;

use Collator;

/**
 * Řazení výpisu Weby podle sloupce (klik na hlavičku: vzestupně →
 * sestupně → vypnuto). Bez řazení zůstává výchozí pořadí podle stavu.
 *
 * Každý sloupec se převede na seznam částí (číslo nebo text), které se
 * porovnávají postupně — verze se tak řadí jako čísla (8.4.27 před
 * 8.10.0) a databáze nejdřív podle typu, pak podle verze. Web bez
 * hodnoty (zatím bez dat, vypnutý plán, jiný systém než WordPress) je
 * vždy na konci, ať je směr jakýkoli; shoda se dořadí podle názvu.
 */
final class SiteSort
{
    /** Parametr `razeni` v adrese => popisek hlavičky. */
    public const COLUMNS = [
        'web' => 'Web',
        'klient' => 'Klient',
        'stav' => 'Stav',
        'uptime' => 'Uptime',
        'wp' => 'WP',
        'php' => 'PHP',
        'databaze' => 'Databáze',
        'aktualizace' => 'Aktualizace',
        'seo' => 'SEO',
        'servis' => 'Servis',
        'report' => 'Report',
    ];

    public const ASC = 'asc';
    public const DESC = 'desc';

    /** Pořadí stavů od nejhoršího — stejné jako výchozí řazení výpisu. */
    private const LEVELS = ['problem' => 0, 'attention' => 1, 'unknown' => 2, 'ok' => 3];

    /**
     * Další krok po kliknutí na hlavičku: jiný sloupec začíná vzestupně,
     * stejný sloupec přejde na sestupně a pak řazení vypne.
     *
     * @return array{0: string, 1: string} [sloupec, směr]; ['', ''] = bez řazení
     */
    public static function next(string $column, string $current, string $direction): array
    {
        if ($column !== $current) {
            return [$column, self::ASC];
        }

        return $direction === self::ASC ? [$column, self::DESC] : ['', ''];
    }

    /**
     * @param array<int, array<string, mixed>> $rows řádky výpisu (viz `value()`)
     * @return array<int, array<string, mixed>>
     */
    public static function sort(array $rows, string $column, string $direction): array
    {
        if (!isset(self::COLUMNS[$column])) {
            return $rows;
        }

        $sign = $direction === self::DESC ? -1 : 1;
        $collator = class_exists(Collator::class) ? new Collator('cs_CZ') : null;

        usort($rows, static function (array $a, array $b) use ($column, $sign, $collator): int {
            $x = self::value($a, $column);
            $y = self::value($b, $column);
            $result = ($x === null || $y === null)
                ? ($x === null) <=> ($y === null)
                : $sign * self::compare($x, $y, $collator);

            return $result !== 0 ? $result : self::compare([(string) $a['name']], [(string) $b['name']], $collator);
        });

        return $rows;
    }

    /**
     * Hodnota řádku pro řazení; null = bez hodnoty (patří na konec).
     *
     * Řádek nese kromě sloupců webu i to, co pro výpis spočítal controller:
     * `state`, `cells`, `uptime_percent`, `service_next` (datum příštího
     * servisu u aktivního plánu), `report_next` (aktivní report: termín
     * nebo '') a `seo_sort` (průměrné skóre, -1 = skrytý web).
     *
     * @param array<string, mixed> $row
     * @return array<int, int|float|string>|null
     */
    public static function value(array $row, string $column): ?array
    {
        $wordpress = SiteRepository::isWordpress($row);

        return match ($column) {
            'web' => [(string) $row['name']],
            'klient' => self::text($row['client_name'] ?? null),
            'stav' => [self::LEVELS[$row['state']['level']] ?? 9, (string) $row['state']['label']],
            'uptime' => $row['uptime_percent'] !== null ? [(float) $row['uptime_percent']] : null,
            'wp' => $wordpress ? self::version($row['snap_wp_version'] ?? null) : null,
            'php' => $wordpress ? self::version($row['snap_php_version'] ?? null) : null,
            'databaze' => $wordpress && ($version = self::version($row['snap_db_version'] ?? null)) !== null
                ? [strtolower((string) ($row['snap_db_type'] ?? '')), ...$version]
                : null,
            'aktualizace' => [(int) $row['cells']['updates']['count']],
            'seo' => $row['seo_sort'] !== null ? [(int) $row['seo_sort']] : null,
            'servis' => self::text($row['service_next']),
            'report' => $row['report_next'] !== null ? [(string) $row['report_next']] : null,
            default => null,
        };
    }

    /** @return array<int, string>|null */
    private static function text(mixed $value): ?array
    {
        return $value !== null && (string) $value !== '' ? [(string) $value] : null;
    }

    /**
     * „10.11.6-MariaDB" → [10, 11, 6, 0] — doplněno na čtyři čísla, ať
     * 8.4 a 8.4.0 stojí vedle sebe.
     *
     * @return array<int, int>|null
     */
    private static function version(mixed $value): ?array
    {
        if ($value === null || preg_match('/^\d+(?:\.\d+)*/', (string) $value, $match) !== 1) {
            return null;
        }

        return array_pad(array_map('intval', explode('.', $match[0])), 4, 0);
    }

    /**
     * @param array<int, int|float|string> $a
     * @param array<int, int|float|string> $b
     */
    private static function compare(array $a, array $b, ?Collator $collator): int
    {
        foreach ($a as $i => $left) {
            $right = $b[$i] ?? null;

            if ($right === null) {
                return 1;
            }

            $result = is_string($left) && is_string($right)
                ? ($collator !== null ? (int) $collator->compare($left, $right) : strnatcasecmp($left, $right))
                : $left <=> $right;

            if ($result !== 0) {
                return $result <=> 0;
            }
        }

        return count($a) <=> count($b);
    }
}
