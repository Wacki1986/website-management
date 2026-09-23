<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aktualizace pluginů z knihovny Správy webů — placené a vlastní pluginy,
 * které nejsou na wordpress.org a WordPress by je sám neaktualizoval.
 *
 * Stejný mechanismus jako `MG_Updater` pro tento plugin: správa vydá seznam
 * (`knihovna.json`) a WordPress nabídne aktualizaci běžnou cestou — ve
 * wp-admin i přes „Aktualizovat" ve Správě webů. Web se prokazuje otiskem
 * svého API klíče (sám klíč nezná); odkaz na ZIP je podepsaný jen pro něj.
 */
final class MG_Library
{
    const CACHE = 'mg_monitor_library';
    const TTL = 43200; // 12 hodin — stejně jako kontrola aktualizací WordPressu

    /** @var string */
    private $hub_url;

    /** @param string $hub_url adresa Správy webů bez lomítka na konci */
    public function __construct($hub_url)
    {
        $this->hub_url = rtrim((string) $hub_url, '/');

        // Prio 20: po updaterech placených pluginů (ty bez licence nabízejí
        // prázdný balíček), knihovna má přednost.
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'), 20);
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_action('upgrader_process_complete', array($this, 'purge_cache'), 10, 0);
    }

    /**
     * Seznam z knihovny: soubor pluginu => {name, slug, version, package…}.
     * Null = správa neodpověděla (nic neměnit).
     *
     * @return array|null
     */
    public function manifest()
    {
        $cached = get_transient(self::CACHE);

        if (is_array($cached)) {
            return $cached;
        }

        $hash = (string) get_option(MG_Monitor::OPTION_KEY_HASH, '');

        if ($hash === '') {
            return array();
        }

        $response = wp_remote_get($this->hub_url . '/plugin/mediagrafik-monitor/knihovna.json', array(
            'timeout' => 10,
            'headers' => array('Accept' => 'application/json', 'X-MG-Key-Hash' => $hash),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || empty($body['ok']) || !isset($body['plugins']) || !is_array($body['plugins'])) {
            return null;
        }

        set_transient(self::CACHE, $body['plugins'], self::TTL);

        return $body['plugins'];
    }

    public function check_for_updates($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $manifest = $this->manifest();

        if ($manifest === null) {
            return $transient;
        }

        foreach ($manifest as $file => $entry) {
            if (!isset($transient->checked[$file], $entry['version'], $entry['package'])) {
                continue;
            }

            // Verze na disku (`checked`) — ne v paměti; viz MG_Updater.
            if (version_compare((string) $transient->checked[$file], (string) $entry['version'], '>=')) {
                // Zbytek naší dřívější nabídky pryč; cizí (wordpress.org) nechat.
                if (isset($transient->response[$file]->package) && strpos((string) $transient->response[$file]->package, $this->hub_url) === 0) {
                    unset($transient->response[$file]);
                }

                continue;
            }

            $transient->response[$file] = (object) array(
                'slug' => isset($entry['slug']) ? (string) $entry['slug'] : dirname($file),
                'plugin' => $file,
                'new_version' => (string) $entry['version'],
                'url' => '',
                'package' => (string) $entry['package'],
                'requires' => isset($entry['requires']) ? (string) $entry['requires'] : '',
                'requires_php' => isset($entry['requires_php']) ? (string) $entry['requires_php'] : '',
            );
        }

        return $transient;
    }

    /** „Zobrazit podrobnosti" ve wp-admin — jinak by se WordPress ptal wordpress.org. */
    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug)) {
            return $result;
        }

        foreach ((array) $this->manifest() as $entry) {
            if (isset($entry['slug']) && $entry['slug'] === $args->slug) {
                return (object) array(
                    'name' => isset($entry['name']) ? (string) $entry['name'] : $args->slug,
                    'slug' => (string) $entry['slug'],
                    'version' => (string) $entry['version'],
                    'requires' => isset($entry['requires']) ? (string) $entry['requires'] : '',
                    'requires_php' => isset($entry['requires_php']) ? (string) $entry['requires_php'] : '',
                    'download_link' => (string) $entry['package'],
                    'sections' => array('description' => 'Plugin z knihovny Správy webů studia MEDIAGRAFIK.'),
                );
            }
        }

        return $result;
    }

    /**
     * Správa chce aktualizovat teď: zahodit uložený seznam a údaje
     * o aktualizacích označit za staré (viz `MG_Updater::refresh()`).
     */
    public function refresh()
    {
        delete_transient(self::CACHE);

        $current = get_site_transient('update_plugins');

        if (is_object($current)) {
            $current->last_checked = 0;
            set_site_transient('update_plugins', $current);
        }
    }

    public function purge_cache()
    {
        delete_transient(self::CACHE);
    }
}
