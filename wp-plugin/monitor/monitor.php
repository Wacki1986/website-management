<?php

/**
 * Plugin Name: Správa webů – Monitor
 * Plugin URI:
 * Description: Napojení webu na Správu webů — správa si přes REST API a API klíč načítá verze, pluginy, obsah a stav zabezpečení. Plugin sám nic neodesílá.
 * Version: 1.10.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Author: Správa webů
 * Author URI:
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: monitor
 */

// Hlavičku výše, `brand.php` i jméno tohoto souboru a složky přepisuje
// Správa webů při stažení ZIPu (`PluginDistribution`) — každá instance
// rozdává plugin pod svým jménem. Tady jsou hodnoty pro balíček
// zabalený přímo z repozitáře.

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Na webu už běží kopie pluginu od jiné správy (obě mají stejné třídy).
 * Druhá se nenačte a jen to ohlásí v administraci — jinak by PHP spadlo
 * na dvakrát deklarované třídě a s ním celý web.
 */
if (class_exists('MG_Monitor', false)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-warning"><p>'
            . esc_html(sprintf('Plugin „%s“ nejde spustit vedle jiného pluginu Správy webů, který už na webu běží. Jeden z nich vypněte.', basename(__FILE__, '.php')))
            . '</p></div>';
    });

    return;
}

define('MG_MONITOR_FILE', __FILE__);

require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/includes/class-mg-monitor.php';

register_activation_hook(__FILE__, array('MG_Monitor', 'activate'));

MG_Monitor::instance();
