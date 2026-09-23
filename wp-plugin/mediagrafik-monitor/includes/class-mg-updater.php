<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aktualizace pluginu z hubu (ne z WordPress.org).
 *
 * Převzato z `WS_Plugin_Updater` (website-summary): hub vydává
 * `plugin-info.json` s verzí a adresou ZIPu, WordPress si ho stáhne přes
 * standardní mechanismus. Cache 12 hodin v transientu.
 */
final class MG_Updater
{
    /** @var string */
    private $update_url;
    /** @var string */
    private $plugin_slug;
    /** @var string */
    private $version;
    /** @var string */
    private $plugin_basename;
    /** @var string */
    private $cache_key;

    public function __construct($update_url, $plugin_file, $version)
    {
        $this->update_url = $update_url;
        $this->plugin_slug = dirname(plugin_basename($plugin_file));
        $this->version = $version;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->cache_key = 'mg_monitor_update_' . md5($this->update_url);

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_action('upgrader_process_complete', array($this, 'purge_cache'), 10, 2);
        add_filter('auto_update_plugin', array($this, 'enable_auto_update'), 10, 2);
    }

    public function check_for_updates($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_info();

        // Verze podle souboru na disku (`checked`), ne podle konstanty: hned
        // po aktualizaci WordPress kontroluje aktualizace ve stejném požadavku,
        // kdy je v paměti ještě starý kód se starou VERSION — a nabídl by
        // „aktualizaci" na verzi, která už je nainstalovaná.
        $installed = isset($transient->checked[$this->plugin_basename]) ? (string) $transient->checked[$this->plugin_basename] : $this->version;

        // Hub neodpověděl — nic neměnit, ani platnou nabídku nemazat.
        if (!$remote) {
            return $transient;
        }

        if (version_compare($installed, $remote->version, '>=')) {
            // Zbytek po předchozí chybné kontrole ven — jinak drží 12 hodin.
            unset($transient->response[$this->plugin_basename]);

            return $transient;
        }

        $transient->response[$this->plugin_basename] = (object) array(
            'slug' => $this->plugin_slug,
            'plugin' => $this->plugin_basename,
            'new_version' => $remote->version,
            'url' => isset($remote->homepage) ? $remote->homepage : '',
            'package' => $remote->download_url,
            'tested' => isset($remote->tested) ? $remote->tested : '',
            'requires_php' => isset($remote->requires_php) ? $remote->requires_php : '',
            'requires' => isset($remote->requires) ? $remote->requires : '',
        );

        return $transient;
    }

    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $remote = $this->get_remote_info();

        if (!$remote) {
            return $result;
        }

        return (object) array(
            'name' => isset($remote->name) ? $remote->name : 'MEDIAGRAFIK Monitor',
            'slug' => $this->plugin_slug,
            'version' => $remote->version,
            'tested' => isset($remote->tested) ? $remote->tested : '',
            'requires' => isset($remote->requires) ? $remote->requires : '',
            'requires_php' => isset($remote->requires_php) ? $remote->requires_php : '',
            'author' => isset($remote->author) ? $remote->author : 'Mediagrafik.cz',
            'homepage' => isset($remote->homepage) ? $remote->homepage : '',
            'download_link' => $remote->download_url,
            'sections' => array(
                'description' => isset($remote->sections->description) ? $remote->sections->description : '',
                'changelog' => isset($remote->sections->changelog) ? $remote->sections->changelog : '',
            ),
            'last_updated' => isset($remote->last_updated) ? $remote->last_updated : '',
        );
    }

    private function get_remote_info()
    {
        $cached = get_transient($this->cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get($this->update_url, array('timeout' => 10, 'headers' => array('Accept' => 'application/json')));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $remote = json_decode(wp_remote_retrieve_body($response));

        if (!$remote || !isset($remote->version, $remote->download_url)) {
            return false;
        }

        set_transient($this->cache_key, $remote, 12 * HOUR_IN_SECONDS);

        return $remote;
    }

    /** Soubor pluginu (`slozka/mediagrafik-monitor.php`) — tak ho zná upgrader. */
    public function basename()
    {
        return $this->plugin_basename;
    }

    /**
     * Hub chce plugin aktualizovat hned, bez čekání na 12hodinovou cache.
     * Zahodí uložené `plugin-info.json` a údaje o aktualizacích označí za
     * staré — příští `wp_update_plugins()` se hubu zeptá znovu a
     * `check_for_updates()` doplní novou verzi i s balíčkem.
     */
    public function refresh()
    {
        delete_transient($this->cache_key);

        $current = get_site_transient('update_plugins');

        if (is_object($current)) {
            $current->last_checked = 0;
            set_site_transient('update_plugins', $current);
        }
    }

    public function purge_cache($upgrader, $options)
    {
        if (isset($options['action'], $options['type']) && $options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient($this->cache_key);
        }
    }

    public function enable_auto_update($update, $item)
    {
        if (isset($item->plugin) && $item->plugin === $this->plugin_basename) {
            return true;
        }

        return $update;
    }
}
