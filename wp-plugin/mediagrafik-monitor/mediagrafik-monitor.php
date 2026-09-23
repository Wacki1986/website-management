<?php

/**
 * Plugin Name: MEDIAGRAFIK Monitor
 * Plugin URI: https://mediagrafik.cz
 * Description: Napojení webu na Správu webů studia MEDIAGRAFIK — hub si přes REST API a API klíč načítá verze, pluginy, obsah a stav zabezpečení. Plugin sám nic neodesílá.
 * Version: 1.4.0
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
 * Na webu něco mění jen akce na pokyn hubu (podepsaný požadavek):
 * aktualizace pluginů (`MG_Plugin_Updates`), smazání neaktivních pluginů
 * a aktualizace WordPressu (`MG_Site_Actions`), přihlášení do administrace
 * jedním klikem (`MG_Login`). Aktualizace nabízí WordPressu pro sebe
 * (`MG_Updater`) i pro pluginy z knihovny Správy webů (`MG_Library`).
 *
 * Verzi je nutné změnit na dvou místech: hlavička `Version:` a konstanta
 * `self::VERSION`. Distribuci (plugin-info.json + ZIP) dělá hub.
 */
final class MG_Monitor
{
    const VERSION = '1.4.0';

    const OPTION_KEY_HASH = 'mg_monitor_key_hash';
    const OPTION_KEY_HINT = 'mg_monitor_key_hint';
    const OPTION_LAST_SEEN = 'mg_monitor_last_seen';
    const OPTION_HUB_URL = 'mg_monitor_hub_url';

    /** Přihlášení ze Správy webů povoleno? '1' / '0', výchozí povoleno. */
    const OPTION_ALLOW_LOGIN = 'mg_monitor_allow_login';

    /**
     * Odkud se stahují aktualizace pluginu. Adresa hubu se ukládá při
     * vložení klíče (admin stránka) — plugin tak sám ví, kam se ptát.
     */
    const DEFAULT_HUB_URL = 'https://sprava.mediagrafik.cz';

    /** @var MG_Monitor|null */
    private static $instance = null;

    /** @var MG_Updater|null */
    private $updater = null;

    /** @var MG_Library|null */
    private $library = null;

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
        // Prio 1: odkaz z hubu přihlásí dřív, než se web začne vykreslovat.
        add_action('init', array('MG_Login', 'maybe_login'), 1);
    }

    private function load_dependencies()
    {
        $dir = plugin_dir_path(__FILE__);

        require_once $dir . 'includes/class-mg-api-key.php';
        require_once $dir . 'includes/class-mg-rate-limit.php';
        require_once $dir . 'includes/class-mg-collector.php';
        require_once $dir . 'includes/class-mg-security-checks.php';
        require_once $dir . 'includes/class-mg-site-actions.php';
        require_once $dir . 'includes/class-mg-plugin-updates.php';
        require_once $dir . 'includes/class-mg-login.php';
        require_once $dir . 'includes/class-mg-rest-controller.php';
        require_once $dir . 'includes/class-mg-updater.php';
        require_once $dir . 'includes/class-mg-library.php';
        require_once $dir . 'admin/class-mg-admin-page.php';
    }

    /**
     * Aktualizace z hubu — tohoto pluginu i pluginů z knihovny Správy webů.
     * Adresa se odvozuje z uložené adresy hubu.
     */
    public function init_updater()
    {
        $hub = trim((string) get_option(self::OPTION_HUB_URL, ''));
        $hub = rtrim($hub !== '' ? $hub : self::DEFAULT_HUB_URL, '/');

        $this->updater = new MG_Updater($hub . '/plugin/mediagrafik-monitor/plugin-info.json', __FILE__, self::VERSION);
        $this->library = new MG_Library($hub);
    }

    /** @return MG_Updater|null null jen před `plugins_loaded` */
    public function updater()
    {
        return $this->updater;
    }

    /** @return MG_Library|null null jen před `plugins_loaded` */
    public function library()
    {
        return $this->library;
    }

    public static function activate()
    {
        // Nic — klíč vloží člověk v administraci. Aktivace nesmí selhat.
    }
}

register_activation_hook(__FILE__, array('MG_Monitor', 'activate'));

MG_Monitor::instance();
