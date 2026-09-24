<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Konce bezpečnostní podpory PHP.
 *
 * Vestavěná tabulka (php.net/supported-versions) je záloha: jednou za měsíc
 * ji `SupportTables` nahradí aktuálními daty z endoflife.date (`useTable()`).
 */
final class PhpSupport
{
    /** @var array<string, string> minor verze => poslední den bezpečnostní podpory */
    public const TABLE = [
        '7.0' => '2019-01-10',
        '7.1' => '2019-12-01',
        '7.2' => '2020-11-30',
        '7.3' => '2021-12-06',
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    /** @var array<string, string>|null tabulka stažená `SupportTables`, null = vestavěná */
    private static ?array $table = null;

    /** @param array<string, string>|null $table null = zpět na vestavěnou tabulku */
    public static function useTable(?array $table): void
    {
        self::$table = $table;
    }

    /** @return array<string, string> tabulka, kterou správa právě používá */
    public static function table(): array
    {
        return self::$table ?? self::TABLE;
    }

    /** Doporučená verze do textů („Doporučen přechod na 8.4"). */
    public const RECOMMENDED = '8.4';

    /** `8.1.29` → `8.1`. */
    public static function minor(string $version): string
    {
        return preg_match('/^(\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }

    /** Poslední den podpory, nebo null pro neznámou (novější) verzi. */
    public static function endOfLife(string $version): ?string
    {
        return self::table()[self::minor($version)] ?? null;
    }

    public static function isEol(string $version, ?int $now = null): bool
    {
        $end = self::endOfLife($version);

        if ($end === null) {
            // Verze mimo tabulku: buď starší než 7.0 (dávno bez podpory),
            // nebo novější než tabulka zná (podporovaná).
            return version_compare(self::minor($version), '7.0', '<');
        }

        return strtotime($end . ' 23:59:59') < ($now ?? time());
    }

    /** Kolik dní podpory zbývá (záporné = po konci); null = neznámé. */
    public static function daysLeft(string $version, ?int $now = null): ?int
    {
        $end = self::endOfLife($version);

        if ($end === null) {
            return null;
        }

        return (int) floor((strtotime($end . ' 23:59:59') - ($now ?? time())) / 86400);
    }

    /** Kolik dní před koncem podpory se verze značí oranžově. */
    public const WARN_DAYS = 365;

    /** `error` bez podpory, `warning` do `WARN_DAYS` dní konec, jinak '' (i neznámá novější verze). */
    public static function toneFor(?int $daysLeft): string
    {
        return match (true) {
            $daysLeft === null => '',
            $daysLeft < 0 => 'error',
            $daysLeft <= self::WARN_DAYS => 'warning',
            default => '',
        };
    }

    public static function tone(string $version, ?int $now = null): string
    {
        if ($version === '') {
            return '';
        }

        return self::isEol($version, $now) ? 'error' : self::toneFor(self::daysLeft($version, $now));
    }

    /** Věta pro klienta do poznámky servisu, nebo null, když je verze v pořádku. */
    public static function advice(string $version, ?int $now = null): ?string
    {
        $minor = self::minor($version);

        return match (self::tone($version, $now)) {
            'error' => 'Web běží na PHP ' . $minor . ', které už nedostává bezpečnostní opravy — doporučujeme u hostingu přejít na PHP ' . self::RECOMMENDED . '.',
            'warning' => 'PHP ' . $minor . ' přestane ' . get_czech_date((string) self::endOfLife($version)) . ' dostávat bezpečnostní opravy (za ' . self::daysLeft($version, $now) . ' dní) — doporučujeme včas přejít na PHP ' . self::RECOMMENDED . '.',
            default => null,
        };
    }
}
