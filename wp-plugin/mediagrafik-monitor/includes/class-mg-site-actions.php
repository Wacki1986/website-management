<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Další akce na pokyn hubu: smazání neaktivních pluginů
 * (`POST /actions/plugin-delete`) a aktualizace WordPressu
 * (`POST /actions/core-update`).
 *
 * Obojí jde stejnou cestou jako wp-admin — `delete_plugins()` (spustí
 * i odinstalaci pluginu) a `Core_Upgrader` (stejně aktualizuje i
 * automatická aktualizace WordPressu). Všechny akce sdílejí jeden zámek:
 * mazání uprostřed aktualizace by skončilo rozbitým webem.
 */
final class MG_Site_Actions
{
    /** Zámek WordPressu (`WP_Upgrader::create_lock()`) pro všechny akce hubu. */
    const LOCK = 'mg_monitor_action';

    /** Načte z wp-admin to, co akce potřebují — REST běží mimo administraci. */
    public static function load_admin()
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    /**
     * Smaže neaktivní pluginy. Aktivní a sebe sama přeskočí s vysvětlením —
     * hub je nabízí jen u neaktivních, tohle je pojistka pro zastaralá data.
     *
     * @param array $files cesty pluginů (`slozka/soubor.php`)
     * @return array|WP_Error seznam výsledků po pluginech
     */
    public static function delete_plugins($files)
    {
        if (!wp_is_file_mod_allowed('mg_monitor_plugin_delete')) {
            return new WP_Error('file_mods_disabled', 'Web má úpravy souborů zakázané (DISALLOW_FILE_MODS) — smažte plugin přes hosting.', array('status' => 409));
        }

        self::load_admin();

        $installed = get_plugins();
        $own = plugin_basename(dirname(__DIR__) . '/mediagrafik-monitor.php');
        $items = array();
        $deletable = array();

        foreach (array_values(array_unique(array_map('strval', (array) $files))) as $file) {
            if (!isset($installed[$file])) {
                continue;
            }

            $item = array(
                'file' => $file,
                'name' => isset($installed[$file]['Name']) ? (string) $installed[$file]['Name'] : $file,
                'version' => isset($installed[$file]['Version']) ? (string) $installed[$file]['Version'] : '',
                'status' => 'deleted',
                'message' => '',
            );

            if ($file === $own) {
                $item['status'] = 'skipped';
                $item['message'] = 'MEDIAGRAFIK Monitor se ze správy mazat nedá — bez něj by správa web ztratila.';
            } elseif (is_plugin_active($file) || is_plugin_active_for_network($file)) {
                $item['status'] = 'skipped';
                $item['message'] = 'Plugin je aktivní — nejdřív ho ve wp-admin deaktivujte.';
            } else {
                $deletable[] = $file;
            }

            $items[$file] = $item;
        }

        if ($items === array()) {
            return new WP_Error('nothing_to_delete', 'Žádný z vybraných pluginů na webu není.', array('status' => 400));
        }

        if ($deletable !== array()) {
            if (!WP_Upgrader::create_lock(self::LOCK, 5 * MINUTE_IN_SECONDS)) {
                return new WP_Error('busy', 'Na webu už jedna akce ze správy běží — počkejte, až doběhne.', array('status' => 409));
            }

            // `delete_plugins()` si při chybějícím přímém zápisu vykreslí
            // formulář na FTP údaje — do JSON odpovědi nesmí nic proniknout.
            ob_start();
            $result = delete_plugins($deletable);
            ob_end_clean();

            WP_Upgrader::release_lock(self::LOCK);

            if ($result === null) {
                return new WP_Error('filesystem', 'WordPress nemá přímý zápis do složky pluginů — smažte plugin ve wp-admin.', array('status' => 409));
            }

            if (is_wp_error($result)) {
                foreach ($deletable as $file) {
                    $items[$file]['status'] = 'failed';
                    $items[$file]['message'] = $result->get_error_message();
                }
            }

            // Plugin, který selhal jen zčásti, na disku zůstal.
            wp_clean_plugins_cache(false);
            $left = get_plugins();

            foreach ($deletable as $file) {
                if ($items[$file]['status'] === 'deleted' && isset($left[$file])) {
                    $items[$file]['status'] = 'failed';
                    $items[$file]['message'] = 'Soubory pluginu se nepodařilo smazat.';
                }
            }

            delete_transient('mg_monitor_updates_checked');
        }

        return array_values($items);
    }

    /**
     * Aktivace / deaktivace pluginu — jako odkaz ve wp-admin, včetně háčků
     * pluginu (aktivační a deaktivační kód se spustí). Sebe sama plugin
     * vypnout nedovolí: správa by k webu ztratila přístup.
     *
     * @param string $file   cesta pluginu (`slozka/soubor.php`)
     * @param bool   $active true = aktivovat, false = deaktivovat
     * @return array|WP_Error `{file, name, active}`
     */
    public static function set_active($file, $active)
    {
        self::load_admin();

        $file = (string) $file;
        $installed = get_plugins();

        if (!isset($installed[$file])) {
            return new WP_Error('plugin_missing', 'Plugin na webu není — načtěte data znovu.', array('status' => 404));
        }

        if ($file === plugin_basename(dirname(__DIR__) . '/mediagrafik-monitor.php')) {
            return new WP_Error('plugin_self', 'MEDIAGRAFIK Monitor se ze správy vypnout nedá — správa by k webu ztratila přístup.', array('status' => 409));
        }

        if (is_multisite() && is_plugin_active_for_network($file)) {
            return new WP_Error('activation_failed', 'Plugin je zapnutý pro celou síť webů — přepněte ho ve správě sítě.', array('status' => 409));
        }

        $name = isset($installed[$file]['Name']) ? (string) $installed[$file]['Name'] : $file;

        if ($active) {
            // activate_plugin() vypisuje případný výstup pluginu — do JSON nesmí.
            ob_start();
            $result = activate_plugin($file);
            ob_end_clean();

            if (is_wp_error($result)) {
                return new WP_Error('activation_failed', $name . ' se nepodařilo aktivovat: ' . $result->get_error_message(), array('status' => 409));
            }
        } else {
            deactivate_plugins($file);
        }

        delete_transient('mg_monitor_updates_checked');

        return array('file' => $file, 'name' => $name, 'active' => is_plugin_active($file));
    }

    /**
     * Aktualizace WordPressu na verzi, kterou web nabízí (a kterou viděl
     * člověk ve správě — `$expected`). Když web mezitím nabízí jinou, nic
     * se nestane: potvrzoval se konkrétní skok, ne „cokoli nového".
     *
     * @param string $expected verze z posledního souhrnu v hubu
     * @return array|WP_Error `{from, to}`
     */
    public static function update_core($expected)
    {
        global $wpdb;

        if (!wp_is_file_mod_allowed('capability_update_core')) {
            return new WP_Error('file_mods_disabled', 'Web má úpravy souborů zakázané (DISALLOW_FILE_MODS) — aktualizujte přes hosting.', array('status' => 409));
        }

        self::load_admin();

        // Nabídky se drží až 12 hodin; aktualizuje se podle čerstvých.
        delete_site_transient('update_core');
        wp_version_check(array(), true);

        $offers = get_core_updates();
        $offer = is_array($offers) && isset($offers[0]->response) && $offers[0]->response === 'upgrade' ? $offers[0] : null;

        if ($offer === null) {
            return new WP_Error('no_core_update', 'Web žádnou novější verzi WordPressu nenabízí — načtěte data znovu.', array('status' => 409));
        }

        if ((string) $expected !== '' && (string) $offer->current !== (string) $expected) {
            return new WP_Error('core_offer_changed', sprintf('Web teď nabízí WordPress %s místo %s — načtěte data znovu a potvrďte novou verzi.', $offer->current, $expected), array('status' => 409));
        }

        if (!empty($offer->php_version) && version_compare(PHP_VERSION, $offer->php_version, '<')) {
            return new WP_Error('php_too_old', sprintf('WordPress %s potřebuje PHP %s, web má %s — nejdřív zvyšte PHP na hostingu.', $offer->current, $offer->php_version, PHP_VERSION), array('status' => 409));
        }

        if (!empty($offer->mysql_version) && version_compare($wpdb->db_version(), $offer->mysql_version, '<')) {
            return new WP_Error('db_too_old', sprintf('WordPress %s potřebuje MySQL %s, web má %s.', $offer->current, $offer->mysql_version, $wpdb->db_version()), array('status' => 409));
        }

        if (!WP_Upgrader::create_lock(self::LOCK, 15 * MINUTE_IN_SECONDS)) {
            return new WP_Error('busy', 'Na webu už jedna akce ze správy běží — počkejte, až doběhne.', array('status' => 409));
        }

        $from = get_bloginfo('version');
        @set_time_limit(600);

        ob_start();
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);
        $result = $upgrader->upgrade($offer, array('allow_relaxed_file_ownership' => true));
        $messages = $skin->get_upgrade_messages();
        ob_end_clean();

        WP_Upgrader::release_lock(self::LOCK);

        if (is_wp_error($result)) {
            return new WP_Error('core_update_failed', 'Aktualizace WordPressu se nezdařila: ' . $result->get_error_message(), array('status' => 500));
        }

        if (!is_string($result) || $result === '') {
            $last = array_values(array_filter(array_map('trim', array_map('wp_strip_all_tags', array_filter((array) $messages, 'is_string')))));

            return new WP_Error('core_update_failed', 'Aktualizace WordPressu se nezdařila' . ($last !== array() ? ': ' . end($last) : '.'), array('status' => 500));
        }

        delete_transient('mg_monitor_updates_checked');

        return array('from' => $from, 'to' => $result);
    }
}
