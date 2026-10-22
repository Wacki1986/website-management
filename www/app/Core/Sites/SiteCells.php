<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Core\Monitor\DbSupport;
use App\Core\Monitor\PhpSupport;

/**
 * Společné buňky řádku webu — Uptime, WP, PHP, Databáze a Aktualizace.
 * Stejné sloupce má výpis Weby i tabulka „Vyžaduje řešení" na dashboardu;
 * počítají se na jednom místě, aby oba výpisy ukazovaly totéž (dřív
 * dashboard zkracoval PHP na 7.4 a nebarvil databázi bez podpory).
 * Vykresluje je `partials/site-cells.php`.
 */
final class SiteCells
{
    /**
     * @param array<string, mixed> $site řádek výpisu se sloupci `snap_*`
     * @return array{uptime: array{value: string, tone: string}, versions: array<int, array{value: string, tone: string, title: string}>, updates: array{count: int, title: string}}
     */
    public static function of(array $site, ?float $uptimePercent, ?int $now = null): array
    {
        return [
            'uptime' => self::uptime($uptimePercent),
            'versions' => self::versions($site, $now),
            'updates' => self::updates($site),
        ];
    }

    /**
     * Uptime za 30 dní. `tone` faint = zatím bez měření.
     *
     * @return array{value: string, tone: string}
     */
    public static function uptime(?float $percent): array
    {
        if ($percent === null) {
            return ['value' => '—', 'tone' => 'faint'];
        }

        return [
            'value' => number_format($percent, $percent >= 99.95 ? 0 : 1, ',', ' ') . ' %',
            'tone' => $percent < 99 ? 'error' : ($percent < 99.9 ? 'warning' : ''),
        ];
    }

    /**
     * Sloupce WP, PHP a Databáze: text, barva (`warning` = čeká aktualizace
     * / podpora končí do roka, `error` = bez podpory) a vysvětlení do bubliny.
     *
     * @param array<string, mixed> $site
     * @return array<int, array{value: string, tone: string, title: string}>
     */
    public static function versions(array $site, ?int $now = null): array
    {
        $wpUpdate = $site['snap_wp_update_version'] ?? null;
        $php = (string) ($site['snap_php_version'] ?? '');
        $dbType = (string) ($site['snap_db_type'] ?? '');
        $db = (string) ($site['snap_db_version'] ?? '');

        // Jiný systém: verze nikdo nehlásí, ve sloupci WP aspoň na čem běží.
        if (!SiteRepository::isWordpress($site)) {
            return [
                ['value' => SiteRepository::platformLabel($site), 'tone' => 'subtle', 'title' => 'Web neběží na WordPressu — hlídá se jen dostupnost, SSL a doména'],
                ['value' => '', 'tone' => '', 'title' => ''],
                ['value' => '', 'tone' => '', 'title' => ''],
            ];
        }

        return [
            ['value' => (string) ($site['snap_wp_version'] ?? ''), 'tone' => $wpUpdate !== null ? 'warning' : '', 'title' => $wpUpdate !== null ? 'Čeká aktualizace na WordPress ' . $wpUpdate : ''],
            // Celá verze (7.4.33), ne jen řada — po bezpečnostní záplatě PHP
            // se podle ní pozná, který web už ji má.
            ['value' => $php, 'tone' => PhpSupport::tone($php, $now), 'title' => PhpSupport::advice($php, $now) ?? ''],
            ['value' => $db !== '' ? DbSupport::label($dbType, $db) : '', 'tone' => DbSupport::tone($dbType, $db, $now), 'title' => DbSupport::advice($dbType, $db, $now) ?? ''],
        ];
    }

    /**
     * Počet čekajících aktualizací — sčítá pluginy i WordPress, bublina
     * říká, z čeho je.
     *
     * @param array<string, mixed> $site
     * @return array{count: int, title: string}
     */
    public static function updates(array $site): array
    {
        $plugins = (int) ($site['snap_plugins_updates'] ?? 0);
        $wordpress = $site['snap_wp_update_version'] ?? null;
        $parts = $plugins > 0 ? [get_count($plugins, 'plugin', 'pluginy', 'pluginů')] : [];

        if ($wordpress !== null) {
            $parts[] = 'WordPress ' . $wordpress;
        }

        return [
            'count' => $plugins + ($wordpress !== null ? 1 : 0),
            'title' => $parts !== [] ? 'Čeká na aktualizaci: ' . implode(' + ', $parts) : '',
        ];
    }
}
