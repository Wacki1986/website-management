<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Konce podpory databází MySQL a MariaDB — obdoba `PhpSupport`.
 *
 * Vestavěná tabulka je záloha — jednou za měsíc ji `SupportTables` nahradí
 * úplnými daty z endoflife.date (`useTables()`). Ručně jsou v ní jen
 * dlouhodobé (LTS) verze: MySQL
 * dev.mysql.com/doc/refman/en/mysql-releases.html, MariaDB
 * mariadb.org/about/#maintenance-policy. Krátkodobé verze mezi nimi
 * (MySQL 8.1–8.3 „innovation“, MariaDB 10.7–10.10, 11.0–11.3, 11.5–11.7)
 * v tabulce nejsou: vydávají se na pár měsíců a všechny starší než
 * nejnovější LTS už podporu nemají. Verze novější než tabulka zná se
 * bere jako podporovaná.
 */
final class DbSupport
{
    /** @var array<string, array<string, string>> typ => minor verze => poslední den podpory */
    public const TABLE = [
        'MySQL' => [
            '5.5' => '2018-12-31',
            '5.6' => '2021-02-28',
            '5.7' => '2023-10-31',
            '8.0' => '2026-04-30',
            '8.4' => '2032-04-30',
        ],
        'MariaDB' => [
            '10.3' => '2023-05-25',
            '10.4' => '2024-06-18',
            '10.5' => '2025-06-24',
            '10.6' => '2026-07-06',
            '10.11' => '2028-02-16',
            '11.4' => '2029-05-29',
            '11.8' => '2028-06-04',
        ],
    ];

    /** @var array<string, array<string, string>>|null tabulky stažené `SupportTables`, null = vestavěné */
    private static ?array $tables = null;

    /** @param array<string, array<string, string>>|null $tables null = zpět na vestavěné */
    public static function useTables(?array $tables): void
    {
        self::$tables = $tables;
    }

    /** @return array<string, array<string, string>> tabulky, které správa právě používá */
    public static function tables(): array
    {
        return (self::$tables ?? []) + self::TABLE;
    }

    /** Doporučené verze do textů. */
    public const RECOMMENDED = ['MySQL' => '8.4', 'MariaDB' => '11.4'];

    /** „MariaDB 10.11“ — typ a minor verze pro výpisy. */
    public static function label(string $type, string $version): string
    {
        return trim($type . ' ' . PhpSupport::minor($version));
    }

    /**
     * Poslední den podpory. U krátkodobé verze starší než nejnovější LTS
     * `'0000-00-00'` (dávno bez podpory), u neznámého typu nebo novější
     * verze null.
     */
    public static function endOfLife(string $type, string $version): ?string
    {
        $table = self::tables()[$type] ?? null;
        $minor = PhpSupport::minor($version);

        if ($table === null || $minor === '') {
            return null;
        }

        if (isset($table[$minor])) {
            return $table[$minor];
        }

        $newest = (string) array_key_last($table);

        return version_compare($minor, $newest, '<') ? '0000-00-00' : null;
    }

    /** Kolik dní podpory zbývá (záporné = po konci); null = neznámé nebo podporované. */
    public static function daysLeft(string $type, string $version, ?int $now = null): ?int
    {
        $end = self::endOfLife($type, $version);

        if ($end === null) {
            return null;
        }

        if ($end === '0000-00-00') {
            return -1;
        }

        return (int) floor((strtotime($end . ' 23:59:59') - ($now ?? time())) / 86400);
    }

    /** Stejné prahy jako PHP: `error` bez podpory, `warning` do roka, jinak ''. */
    public static function tone(string $type, string $version, ?int $now = null): string
    {
        return PhpSupport::toneFor(self::daysLeft($type, $version, $now));
    }

    /** Věta pro klienta do poznámky servisu, nebo null, když je verze v pořádku. */
    public static function advice(string $type, string $version, ?int $now = null): ?string
    {
        $days = self::daysLeft($type, $version, $now);
        $end = self::endOfLife($type, $version);
        $label = self::label($type, $version);
        $recommended = $type . ' ' . (self::RECOMMENDED[$type] ?? '');

        return match (self::tone($type, $version, $now)) {
            'error' => 'Databáze ' . $label . ' už nedostává bezpečnostní opravy — doporučujeme u hostingu přejít na ' . $recommended . ' nebo novější.',
            'warning' => 'Databáze ' . $label . ' přestane ' . get_czech_date((string) $end) . ' dostávat bezpečnostní opravy (za ' . $days . ' dní) — doporučujeme včas přejít na ' . $recommended . '.',
            default => null,
        };
    }
}
