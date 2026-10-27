<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Značka pluginu — kdo ho rozdává a jak se jmenuje.
 *
 * Plugin používá víc nasazení Správy webů a každé si ho pojmenuje po svém.
 * Tenhle soubor proto při stažení ZIPu přepíše správa (`PluginDistribution`)
 * hodnotami z Nastavení → Základní nastavení a adresou sebe sama; stejně tak hlavičku
 * hlavního souboru a jméno složky. Hodnoty níže platí jen pro plugin
 * zabalený přímo z repozitáře.
 *
 * MG_MONITOR_SLUG je složka pluginu a začátek REST namespace (`{slug}/v1`) —
 * musí sedět se slugem správy, jinak ji web neuslyší.
 */
define('MG_MONITOR_NAME', 'Správa webů – Monitor');
// Výchozí slug správy (`Brand::DEFAULT_PLUGIN_SLUG`) — historický, kvůli
// webům napojeným před zavedením razítka.
define('MG_MONITOR_SLUG', 'mediagrafik-monitor');
define('MG_MONITOR_AUTHOR', 'Správa webů');
define('MG_MONITOR_AUTHOR_URI', '');
define('MG_MONITOR_HUB_URL', '');
