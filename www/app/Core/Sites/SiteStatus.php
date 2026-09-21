<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Core\Monitor\PhpSupport;

/**
 * Souhrnný stav webu pro seznamy a dashboard (návrh `weby.html`):
 * jedna tečka + popisek — „Nedostupný", „SSL vypršel", „Zastaralé WP",
 * „PHP 7.4 EOL", „Neaktivní pluginy", „V pořádku".
 *
 * Pořadí pravidel je pořadí závažnosti: první, které platí, vyhrává.
 * Úroveň `level` (problem | attention | ok | unknown) slouží filtrům
 * Problém / Pozornost / V pořádku.
 */
final class SiteStatus
{
    /**
     * @param array<string, mixed> $site řádek z `SiteRepository::all()` (se sloupci `snap_*`)
     * @return array{level: string, tone: string, label: string}
     */
    public static function of(array $site, ?int $now = null): array
    {
        $now ??= time();

        if (($site['status'] ?? 'unknown') === 'down') {
            return self::make('problem', 'error', 'Nedostupný');
        }

        // `ssl_valid_to` je datetime; kdyby přišlo holé datum, platí do konce dne.
        $sslTo = $site['ssl_valid_to'] ?? null;
        $sslTs = $sslTo !== null && $sslTo !== '' ? strtotime((string) $sslTo) : false;

        if ($sslTs !== false && strlen((string) $sslTo) <= 10) {
            $sslTs += 86399;
        }

        if ($sslTs !== false && $sslTs < $now) {
            return self::make('problem', 'error', 'SSL vypršel');
        }

        // Plugin neodpovídá po třech pokusech: data jsou zastaralá, ale web
        // sám běží — pozornost, ne problém.
        if (($site['api_status'] ?? 'unknown') !== 'ok' && (int) ($site['api_failures'] ?? 0) >= 3) {
            return self::make('attention', 'warning', 'Plugin neodpovídá');
        }

        $php = (string) ($site['snap_php_version'] ?? '');

        if ($php !== '' && PhpSupport::isEol($php, $now)) {
            return self::make('attention', 'warning', 'PHP ' . PhpSupport::minor($php) . ' EOL');
        }

        if (($site['snap_wp_update_version'] ?? null) !== null && (string) $site['snap_wp_update_version'] !== '') {
            return self::make('attention', 'warning', 'Zastaralé WP');
        }

        if ($sslTs !== false && $sslTs - $now < 30 * 86400) {
            return self::make('attention', 'warning', 'SSL brzy vyprší');
        }

        $total = (int) ($site['snap_plugins_total'] ?? 0);
        $active = (int) ($site['snap_plugins_active'] ?? 0);

        if ($total > 0 && $total - $active > 0) {
            return self::make('attention', 'warning', 'Neaktivní pluginy');
        }

        if (($site['status'] ?? 'unknown') === 'ok') {
            return self::make('ok', 'ok', 'V pořádku');
        }

        // Ani jedna kontrola neproběhla (čerstvě přidaný web, plugin bez klíče).
        if (($site['last_check_at'] ?? null) === null && ($site['snap_fetched_at'] ?? null) === null) {
            return self::make('unknown', 'muted', 'Zatím nekontrolováno');
        }

        return self::make('ok', 'ok', 'V pořádku');
    }

    /** @return array{level: string, tone: string, label: string} */
    private static function make(string $level, string $tone, string $label): array
    {
        return ['level' => $level, 'tone' => $tone, 'label' => $label];
    }
}
