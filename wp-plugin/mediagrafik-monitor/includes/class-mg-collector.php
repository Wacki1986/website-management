<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sběr dat o webu — jediný zdroj pravdy pro odpověď `/summary`.
 *
 * Vychází z `WS_Data_Collector` pluginu website-summary. Načítá si admin
 * závislosti sám (`wp-admin/includes/plugin.php`, `update.php`), protože
 * REST požadavek přichází mimo administraci. `wp_update_plugins()` je
 * vzdálené volání na api.wordpress.org — je pomalé, proto se drží
 * v transientu 15 minut, aby hub při ručním „Zkontrolovat teď" web nezdržel.
 */
final class MG_Collector
{
    /** @return array */
    public function all()
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        $this->refresh_update_data();

        return array(
            'site' => $this->site(),
            'wordpress' => $this->wordpress(),
            'server' => $this->server(),
            'theme' => $this->theme(),
            'plugins' => $this->plugins(),
            'content' => $this->content(),
            'backup' => $this->backup(),
        );
    }

    /** Kontrola aktualizací proti api.wordpress.org nejvýš jednou za 15 minut. */
    private function refresh_update_data()
    {
        if (get_transient('mg_monitor_updates_checked')) {
            return;
        }

        wp_version_check();
        wp_update_plugins();
        wp_update_themes();

        set_transient('mg_monitor_updates_checked', 1, 15 * MINUTE_IN_SECONDS);
    }

    public function site()
    {
        return array(
            'name' => get_bloginfo('name'),
            'url' => home_url('/'),
            'admin_email' => get_option('admin_email'),
            'locale' => get_locale(),
            'timezone' => wp_timezone_string(),
            'multisite' => is_multisite(),
        );
    }

    public function wordpress()
    {
        $core = get_core_updates();
        $has_update = !empty($core) && isset($core[0]->response) && $core[0]->response === 'upgrade';

        return array(
            'version' => get_bloginfo('version'),
            'has_update' => $has_update,
            'new_version' => $has_update && isset($core[0]->version) ? $core[0]->version : null,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'auto_updates' => (string) get_site_option('auto_update_core_major', ''),
        );
    }

    public function server()
    {
        global $wpdb;

        $raw = (string) $wpdb->get_var('SELECT VERSION()');
        $version = preg_replace('/-.*$/', '', $raw);
        $size = $wpdb->get_var($wpdb->prepare(
            'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024) FROM information_schema.TABLES WHERE table_schema = %s',
            DB_NAME
        ));

        return array(
            'php_version' => PHP_VERSION,
            'db_type' => stripos($raw, 'mariadb') !== false ? 'MariaDB' : 'MySQL',
            'db_version' => $version,
            'db_size_mb' => $size !== null ? (int) $size : null,
            'memory_limit' => (string) ini_get('memory_limit'),
            'https' => is_ssl(),
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '',
        );
    }

    public function theme()
    {
        $theme = wp_get_theme();
        $updates = get_site_transient('update_themes');
        $slug = $theme->get_stylesheet();

        return array(
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'is_child' => $theme->parent() !== false,
            'parent_name' => $theme->parent() !== false ? $theme->parent()->get('Name') : '',
            'has_update' => is_object($updates) && isset($updates->response[$slug]),
        );
    }

    public function plugins()
    {
        $all = get_plugins();
        $transient = get_site_transient('update_plugins');
        $responses = is_object($transient) && isset($transient->response) && is_array($transient->response) ? $transient->response : array();
        $auto = (array) get_site_option('auto_update_plugins', array());

        $items = array();
        $active = 0;
        $updates = 0;

        foreach ($all as $file => $info) {
            $is_active = is_plugin_active($file);
            // Nabídka stejné nebo starší verze (zbytek mezipaměti po
            // aktualizaci) není aktualizace — hub by ji zbytečně nabízel.
            $has_update = isset($responses[$file]->new_version)
                && version_compare((string) $responses[$file]->new_version, isset($info['Version']) ? (string) $info['Version'] : '0', '>');

            if ($is_active) {
                $active++;
            }

            if ($has_update) {
                $updates++;
            }

            $items[] = array(
                'file' => $file,
                'name' => isset($info['Name']) ? $info['Name'] : $file,
                'author' => isset($info['AuthorName']) && $info['AuthorName'] !== '' ? wp_strip_all_tags($info['AuthorName']) : wp_strip_all_tags(isset($info['Author']) ? $info['Author'] : ''),
                'version' => isset($info['Version']) ? $info['Version'] : '',
                'is_active' => $is_active,
                'has_update' => $has_update,
                'new_version' => $has_update && isset($responses[$file]->new_version) ? $responses[$file]->new_version : null,
                'auto_update' => in_array($file, $auto, true),
            );
        }

        return array(
            'total' => count($all),
            'active' => $active,
            'updates' => $updates,
            // WordPress bezpečnostní aktualizace pluginů nijak neoznačuje;
            // hub si počet odvodí sám (v2). Zatím 0.
            'security_updates' => 0,
            'items' => $items,
        );
    }

    /** Veřejné typy obsahu: počty a poslední publikovaný kus. */
    public function content()
    {
        $types = array();

        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }

            $counts = wp_count_posts($type->name);
            $latest = get_posts(array(
                'post_type' => $type->name,
                'post_status' => 'publish',
                'numberposts' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'suppress_filters' => true,
            ));

            $latest_data = null;

            if (!empty($latest)) {
                $post = $latest[0];
                $timestamp = get_post_time('U', true, $post);
                $latest_data = array(
                    'title' => get_the_title($post),
                    'date' => get_post_time('Y-m-d H:i:s', false, $post),
                    'days_ago' => (int) floor((time() - (int) $timestamp) / DAY_IN_SECONDS),
                );
            }

            $types[] = array(
                'slug' => $type->name,
                'label' => $type->labels->name,
                'published' => isset($counts->publish) ? (int) $counts->publish : 0,
                'drafts' => isset($counts->draft) ? (int) $counts->draft : 0,
                'latest' => $latest_data,
            );
        }

        return array('post_types' => $types);
    }

    /**
     * Poslední záloha — jen když ji umí prozradit známý zálohovací plugin.
     * Bez toho `last_backup_at` = null a hub pravidlo „stáří zálohy" přeskočí.
     */
    public function backup()
    {
        $when = null;
        $source = '';

        // UpdraftPlus: pole posledního běhu v options.
        $updraft = get_option('updraft_last_backup');

        if (is_array($updraft) && !empty($updraft['backup_time'])) {
            $when = gmdate('Y-m-d H:i:s', (int) $updraft['backup_time']);
            $source = 'UpdraftPlus';
        }

        // BackWPup: logy jobů v options `backwpup_jobs` → lastrun.
        if ($when === null) {
            $jobs = get_option('backwpup_jobs');

            if (is_array($jobs)) {
                foreach ($jobs as $job) {
                    if (is_array($job) && !empty($job['lastrun'])) {
                        $candidate = gmdate('Y-m-d H:i:s', (int) $job['lastrun']);

                        if ($when === null || $candidate > $when) {
                            $when = $candidate;
                            $source = 'BackWPup';
                        }
                    }
                }
            }
        }

        return array('last_backup_at' => $when, 'source' => $source);
    }
}
