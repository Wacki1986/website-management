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
    public static function updatable(array $plugins, ?string $released, array $library = [], array $outside = []): array
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
            if ((int) $plugin['has_update'] === 1 && version_compare((string) $plugin['new_version'], (string) $plugin['version'], '>') && self::unoffered($plugin, $outside) === null) {
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
     * Proč se hlášená aktualizace nenabízí (null = nabízí se). WordPress
     * novou verzi zná, ale stáhnout ji nemá odkud:
     * - k aktualizaci není balíček (placený plugin bez licence), nebo
     * - plugin je mimo adresář wordpress.org a vypnutý — jeho vlastní
     *   updater (licence, stahování) běží jen u aktivního pluginu.
     *
     * @param array<string, mixed> $plugin  řádek `site_plugins`
     * @param array<string, true>  $outside cesty pluginů mimo adresář (`PluginDirectory::outside()`)
     */
    public static function unoffered(array $plugin, array $outside = []): ?string
    {
        if (($plugin['update_package'] ?? null) !== null && (int) $plugin['update_package'] === 0) {
            return 'Nová verze je ohlášená, ale WordPress k ní nemá balíček ke stažení (placený plugin bez licence) — aktualizujte ručně nahráním ZIPu.';
        }

        if (isset($outside[(string) $plugin['file']]) && (int) ($plugin['is_active'] ?? 1) !== 1) {
            return 'Plugin je mimo adresář wordpress.org a vypnutý — jeho vlastní aktualizace (licence, stahování) běží jen u aktivního pluginu. Aktivujte ho, nebo ho smažte.';
        }

        return null;
    }

    /**
     * Výsledek aktualizace z webu, přezkoumaný proti tomu, co správa ví.
     *
     * Web hlásí „už aktuální", když WordPress v tu chvíli žádnou novou
     * verzi nezná — u placených pluginů bez licence (nabídka jen ve
     * wp-admin) i tehdy, když verze zůstala stará. Pro správu je to
     * selhání s vysvětlením, ne fajfka. Platí i pro weby s pluginem do
     * 1.5.1, které to samy nerozliší.
     *
     * @param array<int, array<string, mixed>> $items    výsledky po pluginech z `PluginClient::updatePlugins()`
     * @param array<string, array{name: string, new_version: string}> $updatable z `updatable()`
     * @return array<int, array<string, mixed>>
     */
    public static function reviewUpdateResults(array $items, array $updatable): array
    {
        foreach ($items as $i => $item) {
            $expected = $updatable[(string) ($item['file'] ?? '')]['new_version'] ?? '';
            $to = (string) ($item['to'] ?? $item['from'] ?? '');

            if (($item['status'] ?? '') === 'up_to_date' && $expected !== '' && version_compare($to, $expected, '<')) {
                $items[$i]['status'] = 'failed';
                $items[$i]['message'] = 'Web aktualizaci na ' . $expected . ' teď nenabídl a plugin zůstal ve verzi ' . $to
                    . ' — placený plugin nejspíš nemá platnou licenci nebo aktualizuje jen ve wp-admin. Zkontrolujte licenci a aktualizujte ve wp-admin → Pluginy (případně plugin přestaňte sledovat ikonou oka).';
            }
        }

        return $items;
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
     * Proč web novou verzi z knihovny nedostane, ačkoli má starší (pro
     * „Aktualizovat všude" v Knihovně pluginů). Volá se jen tehdy, když ji
     * `updatable()` nenabídl a `blocked()` nic nenašel.
     *
     * @param array<string, mixed>      $plugin   řádek `site_plugins`
     * @param array<string, mixed>|null $snapshot
     */
    public static function libraryUnoffered(array $plugin, ?array $snapshot): string
    {
        if ((int) ($plugin['updates_ignored'] ?? 0) === 1) {
            return 'Plugin je na webu nesledovaný — sledování zapnete ikonou oka na záložce Pluginy.';
        }

        $installed = (string) ($snapshot['plugin_version'] ?? '');

        if (version_compare($installed, PluginClient::LIBRARY_SINCE, '<')) {
            return sprintf('Pluginy z knihovny umí web stáhnout až s MEDIAGRAFIK Monitorem %s (web má %s) — aktualizujte ho na záložce Pluginy.',
                PluginClient::LIBRARY_SINCE, $installed !== '' ? $installed : 'neznámou verzi');
        }

        return 'Web aktualizaci teď nenabízí — zkuste ji na záložce Pluginy webu.';
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
