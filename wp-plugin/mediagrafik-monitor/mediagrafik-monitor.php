<?php

/**
 * Plugin Name: MEDIAGRAFIK Monitor
 * Plugin URI: https://mediagrafik.cz
 * Description: Napojení webu na Správu webů studia MEDIAGRAFIK — hub si přes REST API a API klíč načítá verze, pluginy, obsah a stav zabezpečení. Plugin sám nic neodesílá.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Author: Mediagrafik.cz
 * Author URI: https://mediagrafik.cz
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mediagrafik-monitor
 */

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hlavní třída pluginu.
 *
 * Plugin je záměrně pasivní: žádný WP-Cron, žádné e-maily, žádné SMTP.
 * Hub (Správa webů) se ptá sám — model „pull" — a pověřuje se hlavičkou
 * `X-MG-Key`. Klíč vydává hub, tady se ukládá jen jeho SHA-256 hash.
 *
 * Verzi je nutné změnit na dvou místech: hlavička `Version:` a konstanta
 * `self::VERSION`. Distribuci (plugin-info.json + ZIP) dělá hub.
 */
final class MG_Monitor
{
    const VERSION = '1.0.0';

    const OPTION_KEY_HASH = 'mg_monitor_key_hash';
    const OPTION_KEY_HINT = 'mg_monitor_key_hint';
    const OPTION_LAST_SEEN = 'mg_monitor_last_seen';
    const OPTION_HUB_URL = 'mg_monitor_hub_url';

    /**
     * Odkud se stahují aktualizace pluginu. Adresa hubu se ukládá při
     * vložení klíče (admin stránka) — plugin tak sám ví, kam se ptát.
     */
    const DEFAULT_UPDATE_URL = 'https://sprava.mediagrafik.cz/plugin/mediagrafik-monitor/plugin-info.json';

    /** @var MG_Monitor|null */
    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->load_dependencies();

        add_action('rest_api_init', array('MG_Rest_Controller', 'register_routes'));
        // Prio 5: dřív než bezpečnostní pluginy, které REST anonymům zavírají.
        add_filter('rest_authentication_errors', array('MG_Rest_Controller', 'allow_keyed_request'), 5);

        if (is_admin()) {
            new MG_Admin_Page();
        }

        add_action('plugins_loaded', array($this, 'init_updater'));
    }

    private function load_dependencies()
    {
        $dir = plugin_dir_path(__FILE__);

        require_once $dir . 'includes/class-mg-api-key.php';
        require_once $dir . 'includes/class-mg-rate-limit.php';
        require_once $dir . 'includes/class-mg-collector.php';
        require_once $dir . 'includes/class-mg-security-checks.php';
        require_once $dir . 'includes/class-mg-rest-controller.php';
        require_once $dir . 'includes/class-mg-updater.php';
        require_once $dir . 'admin/class-mg-admin-page.php';
    }

    /** Aktualizace z hubu — adresa se odvozuje z uložené adresy hubu. */
    public function init_updater()
    {
        $hub = trim((string) get_option(self::OPTION_HUB_URL, ''));
        $url = $hub !== '' ? rtrim($hub, '/') . '/plugin/mediagrafik-monitor/plugin-info.json' : self::DEFAULT_UPDATE_URL;

        new MG_Updater($url, __FILE__, self::VERSION);
    }

    public static function activate()
    {
        // Nic — klíč vloží člověk v administraci. Aktivace nesmí selhat.
    }
}

register_activation_hook(__FILE__, array('MG_Monitor', 'activate'));

MG_Monitor::instance();
