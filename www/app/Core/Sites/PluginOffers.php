<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Core\Monitor\PluginDirectory;
use App\Core\Plugin\PluginDistribution;
use App\Core\Plugin\PluginLibrary;

/**
 * Které pluginy webu správa nabízí k aktualizaci — jedno místo pro všechny.
 *
 * Dřív se to počítalo dvakrát a pokaždé jinak: tabulka pluginů brala
 * i novější verze z Knihovny pluginů a MEDIAGRAFIK Monitoru, uložený
 * počet (metrika, výpis webů, dashboard, alerty) jen to, co hlásí
 * WordPress. Placený plugin z knihovny (WordPress bez licence o nové verzi
 * neví) pak v tabulce měl tlačítko Aktualizovat, ale v počtech chyběl.
 *
 * Pravidla samotná jsou v `SiteActions::updatable()`; tahle třída jí jen
 * dodá, co k tomu potřebuje (vydaný Monitor, knihovnu, pluginy mimo adresář).
 */
final class PluginOffers
{
    public function __construct(
        private readonly PluginDirectory $directory,
        private readonly PluginDistribution $distribution,
        private readonly PluginLibrary $library,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $plugins  řádky `site_plugins` webu
     * @param array<string, mixed>|null        $snapshot řádek `site_snapshots` (verze Monitoru na webu)
     * @return array<string, array{name: string, new_version: string}> cesta pluginu => nabízená verze
     */
    public function forSite(array $plugins, ?array $snapshot): array
    {
        return SiteActions::updatable(
            $plugins,
            $this->distribution->version(),
            SiteActions::libraryFor($snapshot, $this->library->versions()),
            $this->directory->outside($plugins),
        );
    }
}
