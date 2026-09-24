<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Core\Monitor\PluginClient;
use App\Core\Plugin\PluginDistribution;

/**
 * Co jde na webu ze správy udělat — čistá logika bez sítě a databáze,
 * sdílená záložkami (co nabídnout) i akcemi (co opravdu poslat).
 *
 * Akce jsou tři: aktualizace pluginů, smazání neaktivního pluginu,
 * aktualizace WordPressu. Každou web zná až od určité verze pluginu
 * MEDIAGRAFIK Monitor (`PluginClient::ACTIONS_SINCE`).
 */
final class SiteActions
{
    /** Klíč v `settings`: výchozí účet studia pro přihlášení do webů. */
    public const LOGIN_USER_SETTING = 'wp_login_user';

    /**
     * Do jakého účtu se na webu přihlásit: vlastní u webu, jinak výchozí
     * z Nastavení → Monitoring. Prázdné = přihlášení jedním klikem nejde.
     *
     * @param array<string, mixed> $site
     */
    public static function loginUser(array $site, string $default): string
    {
        $own = trim((string) ($site['wp_login_user'] ?? ''));

        return $own !== '' ? $own : trim($default);
    }

    /**
     * Proč teď akce na webu nejde (null = jde). Stejný text je v titulku
     * neaktivního tlačítka i ve zprávě po odeslání.
     *
     * @param array<string, mixed>      $site
     * @param array<string, mixed>|null $snapshot
     */
    public static function blocked(array $site, ?array $snapshot, string $action): ?string
    {
        if ((string) ($site['api_key'] ?? '') === '' || $snapshot === null) {
            return 'Web zatím nemá napojený plugin MEDIAGRAFIK Monitor.';
        }

        $since = PluginClient::ACTIONS_SINCE[$action];
        $installed = (string) $snapshot['plugin_version'];

        if (version_compare($installed, $since, '<')) {
            return sprintf('Tahle akce ze správy potřebuje MEDIAGRAFIK Monitor %s (web má %s) — aktualizujte ho na záložce Pluginy nebo ve wp-admin.',
                $since, $installed !== '' ? $installed : 'neznámou verzi');
        }

        return null;
    }

    /**
     * Pluginy, které jde ze správy aktualizovat: ty, u kterých web hlásí
     * novou verzi, a MEDIAGRAFIK Monitor, když správa rozdává novější, než
     * má web — web sám by se o ní dozvěděl až po 12hodinové cache.
     *
     * Stejně se nabízí plugin z knihovny pluginů, když je v ní novější verze
     * (web se to dozví až po své 12hodinové cache).
     *
     * @param array<int, array<string, mixed>> $plugins  řádky `site_plugins`
     * @param string|null                     $released verze pluginu, kterou rozdává správa
     * @param array<string, string>           $library  soubor => verze v knihovně (`libraryFor()`)
     * @return array<string, array{name: string, new_version: string}> podle souboru pluginu
     */
    public static function updatable(array $plugins, ?string $released, array $library = []): array
    {
        $updatable = [];

        foreach ($plugins as $plugin) {
            $file = (string) $plugin['file'];

            // Nesledovaný plugin (bez licence…) se aktualizovat nenabízí.
            if ((int) ($plugin['updates_ignored'] ?? 0) === 1) {
                continue;
            }

            // Web může hlásit „aktualizaci" na verzi, kterou už má (zbytek
            // mezipaměti WordPressu po aktualizaci pluginem starší 1.3.1) —
            // nabízí se jen skutečně novější verze.
            if ((int) $plugin['has_update'] === 1 && version_compare((string) $plugin['new_version'], (string) $plugin['version'], '>')) {
                $updatable[$file] = ['name' => (string) $plugin['name'], 'new_version' => (string) $plugin['new_version']];
            } elseif ($released !== null && self::isMonitor($file) && version_compare((string) $plugin['version'], $released, '<')) {
                $updatable[$file] = ['name' => (string) $plugin['name'], 'new_version' => $released];
            } elseif (isset($library[$file]) && version_compare((string) $plugin['version'], $library[$file], '<')) {
                $updatable[$file] = ['name' => (string) $plugin['name'], 'new_version' => $library[$file]];
            }
        }

        return $updatable;
    }

    /**
     * Smazat jde jen neaktivní plugin — aktivní by nejdřív musel někdo
     * vypnout a zkontrolovat, že web bez něj funguje. MEDIAGRAFIK Monitor
     * nikdy: správa by o web přišla.
     *
     * @param array<string, mixed> $plugin řádek `site_plugins`
     */
    public static function isDeletable(array $plugin): bool
    {
        return (int) $plugin['is_active'] !== 1 && !self::isMonitor((string) $plugin['file']);
    }

    /**
     * Verze z knihovny pluginů, které web umí použít — jen s MEDIAGRAFIK
     * Monitorem 1.4.0+, starší plugin by si ZIP neuměl stáhnout.
     *
     * @param array<string, mixed>|null $snapshot
     * @param array<string, string>     $versions `PluginLibrary::versions()`
     * @return array<string, string>
     */
    public static function libraryFor(?array $snapshot, array $versions): array
    {
        return $snapshot !== null && version_compare((string) $snapshot['plugin_version'], PluginClient::LIBRARY_SINCE, '>=') ? $versions : [];
    }

    /**
     * Jde u pluginu přepnout sledování aktualizací? Jen u toho, který
     * aktualizaci hlásí (nebo už je nesledovaný), a nikdy u MEDIAGRAFIK
     * Monitoru — správa by pak nenabídla jeho vlastní aktualizace.
     *
     * @param array<string, mixed> $plugin řádek `site_plugins`
     */
    public static function canToggleWatch(array $plugin): bool
    {
        return !self::isMonitor((string) $plugin['file'])
            && ((int) $plugin['has_update'] === 1 || (int) ($plugin['updates_ignored'] ?? 0) === 1);
    }

    /**
     * Jde plugin ze správy zapnout / vypnout? Všechny kromě MEDIAGRAFIK
     * Monitoru — po jeho vypnutí by správa k webu ztratila přístup.
     *
     * @param array<string, mixed> $plugin řádek `site_plugins`
     */
    public static function canToggleActive(array $plugin): bool
    {
        return !self::isMonitor((string) $plugin['file']);
    }

    /** Je to náš plugin? Podle souboru, složka může mít jiné jméno. */
    public static function isMonitor(string $file): bool
    {
        return basename($file) === PluginDistribution::SLUG . '.php';
    }
}
