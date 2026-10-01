<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instalace pluginu z knihovny Správy webů — `POST /actions/plugin-install`.
 *
 * Hub posílá jen to, **který** plugin chce (cestu `slozka/soubor.php`),
 * nikdy odkaz na ZIP. Odkaz si plugin vezme z čerstvého seznamu knihovny
 * (`MG_Library::fresh_manifest()`), který stáhl ze své uložené adresy hubu —
 * stejná pojistka jako u aktualizací: kdo by ukradl API klíč, stejně na web
 * nepodstrčí vlastní balíček.
 *
 * Instaluje se cestou wp-admin (`Plugin_Upgrader::install()` — jako
 * Pluginy → Nahrát plugin), po instalaci volitelně `activate_plugin()`.
 */
final class MG_Plugin_Install
{
    /** Stejný strop jako u aktualizací (`MG_Plugin_Updates::MAX_PER_REQUEST`). */
    const MAX_PER_REQUEST = 10;

    /**
     * @param array $files    cesty pluginů z knihovny (`slozka/soubor.php`)
     * @param bool  $activate po instalaci plugin rovnou aktivovat
     * @return array|WP_Error seznam výsledků po pluginech
     */
    public static function run($files, $activate)
    {
        if (!wp_is_file_mod_allowed('mg_monitor_plugin_install')) {
            return new WP_Error('file_mods_disabled', 'Web má úpravy souborů zakázané (DISALLOW_FILE_MODS) — nainstalujte plugin přes hosting.', array('status' => 409));
        }

        $files = array_values(array_unique(array_filter(array_map('strval', (array) $files))));

        if ($files === array()) {
            return new WP_Error('nothing_to_install', 'Není vybraný žádný plugin k instalaci.', array('status' => 400));
        }

        if (count($files) > self::MAX_PER_REQUEST) {
            return new WP_Error('too_many_plugins', 'Najednou jde nainstalovat nejvýš ' . self::MAX_PER_REQUEST . ' pluginů.', array('status' => 400));
        }

        $library = MG_Monitor::instance()->library();
        $manifest = $library !== null ? $library->fresh_manifest() : null;

        if ($manifest === null) {
            return new WP_Error('library_unavailable', 'Web se nedostal ke knihovně pluginů ve Správě webů — zkontrolujte adresu správy v nastavení pluginu MEDIAGRAFIK Monitor.', array('status' => 502));
        }

        MG_Site_Actions::load_admin();

        $installed = get_plugins();
        $items = array();
        $todo = array();

        foreach ($files as $file) {
            $entry = isset($manifest[$file]) && is_array($manifest[$file]) ? $manifest[$file] : null;
            $item = array(
                'file' => $file,
                'name' => $entry !== null && isset($entry['name']) ? (string) $entry['name'] : $file,
                'version' => $entry !== null && isset($entry['version']) ? (string) $entry['version'] : '',
                'status' => 'failed',
                'active' => false,
                'message' => '',
            );

            if (isset($installed[$file])) {
                $item['status'] = 'skipped';
                $item['version'] = isset($installed[$file]['Version']) ? (string) $installed[$file]['Version'] : '';
                $item['active'] = is_plugin_active($file);
                $item['message'] = 'Plugin už na webu je.';
            } elseif ($entry === null || empty($entry['package'])) {
                $item['message'] = 'Plugin v knihovně Správy webů není — načtěte stránku znovu.';
            } elseif (!$library->owns_package((string) $entry['package'])) {
                $item['message'] = 'Odkaz na balíček nevede do Správy webů — instalace odmítnuta.';
            } else {
                $item['message'] = self::unmet($file, $entry);

                if ($item['message'] === '') {
                    $todo[$file] = (string) $entry['package'];
                }
            }

            $items[$file] = $item;
        }

        if ($todo !== array()) {
            // Sdílený zámek všech akcí hubu: instalace uprostřed aktualizace
            // by si s ní přepisovala složku pluginů.
            if (!WP_Upgrader::create_lock(MG_Site_Actions::LOCK, 5 * MINUTE_IN_SECONDS)) {
                return new WP_Error('busy', 'Na webu už jedna akce ze správy běží — počkejte, až doběhne.', array('status' => 409));
            }

            @set_time_limit(300);

            foreach ($todo as $file => $package) {
                $items[$file] = self::install($items[$file], $package);
            }

            WP_Upgrader::release_lock(MG_Site_Actions::LOCK);

            delete_transient('mg_monitor_updates_checked');
            wp_clean_plugins_cache(true);
        }

        // Aktivuje se až po uvolnění zámku a nad čerstvým seznamem pluginů.
        if ($activate) {
            foreach ($items as $file => $item) {
                if ($item['status'] === 'installed') {
                    $items[$file] = self::activate($item);
                }
            }
        }

        return array_values($items);
    }

    /**
     * Proč plugin na webu nepůjde (prázdné = půjde). WordPress při instalaci
     * požadavky nekontroluje — plugin by se nahrál a pak nešel aktivovat.
     *
     * @param string $file
     * @param array  $entry položka seznamu knihovny
     * @return string
     */
    private static function unmet($file, $entry)
    {
        $requires = isset($entry['requires']) ? (string) $entry['requires'] : '';
        $requires_php = isset($entry['requires_php']) ? (string) $entry['requires_php'] : '';

        if ($requires !== '' && version_compare(get_bloginfo('version'), $requires, '<')) {
            return sprintf('Plugin potřebuje WordPress %s, web má %s.', $requires, get_bloginfo('version'));
        }

        if ($requires_php !== '' && version_compare(PHP_VERSION, $requires_php, '<')) {
            return sprintf('Plugin potřebuje PHP %s, web má %s.', $requires_php, PHP_VERSION);
        }

        // Stejně pojmenovaná složka (jiný plugin, starší ruční nahrání):
        // WordPress by instalaci odmítl hláškou „Cílová složka již existuje".
        if (file_exists(trailingslashit(WP_PLUGIN_DIR) . dirname($file))) {
            return 'Na webu už je složka ' . dirname($file) . ' — nejdřív ji smažte (ve wp-admin nebo na hostingu).';
        }

        return '';
    }

    /**
     * @param array  $item
     * @param string $package podepsaný odkaz z knihovny
     * @return array
     */
    private static function install($item, $package)
    {
        // Upgrader a skin vypisují HTML (průběh pro wp-admin) — do JSON
        // odpovědi nesmí nic proniknout.
        ob_start();
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->install($package);
        $messages = $skin->get_upgrade_messages();
        $main = $upgrader->plugin_info();
        ob_end_clean();

        if (is_wp_error($result)) {
            $item['message'] = $result->get_error_message();

            return $item;
        }

        // `install()` vrací null, když se nepřipojí k souborům (hosting bez
        // přímého zápisu, FS_METHOD ftpext bez údajů).
        if ($result !== true) {
            $last = array_values(array_filter(array_map(function ($message) {
                return trim(wp_strip_all_tags(is_string($message) ? $message : ''));
            }, (array) $messages)));
            $item['message'] = $last !== array() ? (string) end($last) : 'WordPress nemá přímý zápis do složky pluginů — nainstalujte plugin ve wp-admin.';

            return $item;
        }

        // ZIP z knihovny má jinou hlavní cestu, než hlásí seznam (správa
        // čte ZIP stejně jako WordPress, takže jen pojistka).
        if ($main !== false && $main !== $item['file']) {
            $item['message'] = 'Plugin se nainstaloval jako ' . $main . ' — ve správě se objeví po načtení dat.';
        }

        $item['status'] = 'installed';

        return $item;
    }

    /**
     * Nepovedená aktivace instalaci neruší — plugin na webu zůstane
     * neaktivní a důvod jde do zprávy.
     *
     * @param array $item
     * @return array
     */
    private static function activate($item)
    {
        // activate_plugin() vypisuje případný výstup pluginu — do JSON nesmí.
        ob_start();
        $result = activate_plugin($item['file']);
        ob_end_clean();

        if (is_wp_error($result)) {
            $item['message'] = 'Nainstalováno, ale aktivace se nezdařila: ' . $result->get_error_message();
        }

        $item['active'] = is_plugin_active($item['file']);

        return $item;
    }
}
