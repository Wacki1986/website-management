<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aktualizace pluginů na pokyn hubu — `POST /actions/plugin-update`.
 *
 * Jde stejnou cestou jako hromadná aktualizace ve wp-admin
 * (`Plugin_Upgrader::bulk_upgrade()`): aktivní pluginy zůstávají aktivní,
 * po dobu aktualizace běží režim údržby a při selhání rozbalení WordPress
 * (6.3+) sám vrátí původní verzi ze zálohy.
 *
 * Aktualizovat jde jen plugin, pro který WordPress zná novou verzi
 * (transient `update_plugins`) — hub nemůže podstrčit vlastní balíček.
 */
final class MG_Plugin_Updates
{
    /** Víc najednou by na sdíleném hostingu nestihlo `max_execution_time`. */
    const MAX_PER_REQUEST = 10;

    /**
     * @param array $files cesty pluginů (`slozka/soubor.php`)
     * @return array|WP_Error seznam výsledků po pluginech
     */
    public static function run($files)
    {
        if (!wp_is_file_mod_allowed('mg_monitor_plugin_update')) {
            return new WP_Error('file_mods_disabled', 'Web má úpravy souborů zakázané (DISALLOW_FILE_MODS) — aktualizujte přes hosting.', array('status' => 409));
        }

        MG_Site_Actions::load_admin();

        $installed = get_plugins();
        $files = array_values(array_unique(array_filter(array_map('strval', (array) $files), function ($file) use ($installed) {
            return isset($installed[$file]);
        })));

        if ($files === array()) {
            return new WP_Error('nothing_to_update', 'Žádný z vybraných pluginů na webu není.', array('status' => 400));
        }

        if (count($files) > self::MAX_PER_REQUEST) {
            return new WP_Error('too_many_plugins', 'Najednou jde aktualizovat nejvýš ' . self::MAX_PER_REQUEST . ' pluginů.', array('status' => 400));
        }

        // Sebe sama hub nabízí k aktualizaci hned, jak vydá novou verzi;
        // web by se o ní jinak dozvěděl až po vypršení cache (12 hodin).
        $own = MG_Monitor::instance()->updater();

        if ($own !== null && in_array($own->basename(), $files, true)) {
            $own->refresh();
        }

        // Totéž pro knihovnu pluginů: plugin, o jehož nové verzi WordPress
        // ještě neví, nejspíš právě přibyl do knihovny — seznam načíst znovu.
        $library = MG_Monitor::instance()->library();
        $known = get_site_transient('update_plugins');

        if ($library !== null) {
            foreach ($files as $file) {
                if (!isset($known->response[$file])) {
                    $library->refresh();
                    break;
                }
            }
        }

        // Údaje o nových verzích mohou být staré až 12 hodin — hub vidí
        // aktualizaci z našeho posledního souhrnu, tak ať ji zná i upgrader.
        wp_update_plugins();

        $before = array();

        foreach ($files as $file) {
            $before[$file] = isset($installed[$file]['Version']) ? (string) $installed[$file]['Version'] : '';
        }

        // Dvojklik ve správě = dva souběžné upgrady, které by si navzájem
        // přepisovaly soubory. Zámek WordPressu drží nejvýš 5 minut.
        if (!WP_Upgrader::create_lock(MG_Site_Actions::LOCK, 5 * MINUTE_IN_SECONDS)) {
            return new WP_Error('busy', 'Na webu už jedna akce ze správy běží — počkejte, až doběhne.', array('status' => 409));
        }

        @set_time_limit(300);

        // Upgrader a skin vypisují HTML (průběh pro wp-admin) — do JSON
        // odpovědi nesmí nic proniknout.
        ob_start();
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $results = $upgrader->bulk_upgrade($files);
        $messages = $skin->get_upgrade_messages();
        ob_end_clean();

        WP_Upgrader::release_lock(MG_Site_Actions::LOCK);

        // `bulk_upgrade()` vrací false, když se nepřipojí k souborům
        // (hosting bez přímého zápisu, FS_METHOD ftpext bez údajů).
        if ($results === false || !is_array($results)) {
            return new WP_Error('filesystem', 'WordPress nemá přímý zápis do složky pluginů — aktualizace musí proběhnout ve wp-admin.', array('status' => 409));
        }

        // Upgrader smazal cache pluginů i transient s aktualizacemi. Příští
        // souhrn si je musí načíst znovu, jinak by hub 15 minut viděl
        // „žádné aktualizace" i u pluginů, které jsme vůbec neaktualizovali.
        delete_transient('mg_monitor_updates_checked');
        wp_clean_plugins_cache(true);
        $after = get_plugins();

        $items = array();

        foreach ($files as $file) {
            $result = isset($results[$file]) ? $results[$file] : null;
            $to = isset($after[$file]['Version']) ? (string) $after[$file]['Version'] : '';
            $status = 'updated';
            $message = '';

            if (is_wp_error($result)) {
                $status = 'failed';
                $message = $result->get_error_message();
            } elseif ($result === true) {
                // Upgrader pro plugin žádnou novou verzi neznal.
                $status = 'up_to_date';
            } elseif ($result === null || $result === false || $to === $before[$file]) {
                $status = 'failed';
                $message = self::last_error($messages);
            }

            $items[] = array(
                'file' => $file,
                'name' => isset($installed[$file]['Name']) ? (string) $installed[$file]['Name'] : $file,
                'status' => $status,
                'from' => $before[$file],
                'to' => $to,
                'message' => $message,
            );
        }

        return $items;
    }

    /**
     * Placené pluginy bez licence nemají balíček; WordPress to hlásí jen
     * textem v průběhu, ne jako WP_Error. Vezme se poslední zpráva.
     *
     * @param array $messages
     */
    private static function last_error($messages)
    {
        $messages = array_values(array_filter(array_map(function ($message) {
            return trim(wp_strip_all_tags(is_string($message) ? $message : ''));
        }, (array) $messages)));

        return $messages !== array() ? (string) end($messages) : 'WordPress aktualizaci nedokončil.';
    }
}
