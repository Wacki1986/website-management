<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bezpečnostní kontroly, které jdou zjistit jen zevnitř webu.
 *
 * Hub k nim přidá dvě vnější (veřejný wp-login.php, Basic auth) a sloučí
 * do pěti opatření z návrhu. Každá kontrola vrací `status` ok | warning |
 * error, `value` (co se našlo) a `note` (věta do tabulky).
 */
final class MG_Security_Checks
{
    /** @return array{checks: array} */
    public static function run()
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $checks = array(
            self::security_plugin(),
            self::two_factor(),
            self::content_dir(),
        );

        $login = self::login_slug();

        if ($login !== null) {
            $checks[] = $login;
        }

        return array('checks' => $checks);
    }

    /**
     * Známé bezpečnostní pluginy — rozšiřitelné filtrem
     * `mg_monitor_security_plugins` (basename => název).
     */
    public static function security_plugin()
    {
        $known = apply_filters('mg_monitor_security_plugins', array(
            'wordfence/wordfence.php' => 'Wordfence',
            'better-wp-security/better-wp-security.php' => 'Solid Security',
            'sucuri-scanner/sucuri.php' => 'Sucuri',
            'all-in-one-wp-security-and-firewall/wp-security.php' => 'All-In-One Security',
            'wp-cerber/wp-cerber.php' => 'WP Cerber',
            'ninjafirewall/ninjafirewall.php' => 'NinjaFirewall',
            'shield-security/icwp-wpsf.php' => 'Shield Security',
            'wp-simple-firewall/icwp-wpsf.php' => 'Shield Security',
        ));

        $plugins = get_plugins();
        $updates = get_site_transient('update_plugins');
        $responses = is_object($updates) && isset($updates->response) ? (array) $updates->response : array();

        foreach ($known as $file => $label) {
            if (!isset($plugins[$file]) || !is_plugin_active($file)) {
                continue;
            }

            $version = isset($plugins[$file]['Version']) ? $plugins[$file]['Version'] : '';

            if (isset($responses[$file])) {
                return self::check('security_plugin', 'warning', $label . ' ' . $version,
                    'Běží, ale čeká na aktualizaci na ' . $responses[$file]->new_version);
            }

            return self::check('security_plugin', 'ok', $label . ' ' . $version, 'Firewall běží a je aktuální');
        }

        return self::check('security_plugin', 'error', 'Žádný', 'Není aktivní žádný známý bezpečnostní plugin');
    }

    /**
     * Dvoufázové ověření — heuristika podle user meta známých pluginů.
     * Filtr `mg_monitor_2fa_meta_keys` doplní další klíče.
     */
    public static function two_factor()
    {
        global $wpdb;

        $meta_keys = apply_filters('mg_monitor_2fa_meta_keys', array(
            '_two_factor_enabled_providers', // Two Factor (WordPress.org)
            'wp_2fa_enabled_methods',        // WP 2FA
            'itsec_two_factor_enabled_providers', // Solid Security
            'mo2f_configured_2FA_method',    // miniOrange
            'wp_2fa_totp_key',
        ));

        $admins = get_users(array('role' => 'administrator', 'fields' => 'ID'));
        $total = count($admins);

        if ($total === 0) {
            return self::check('two_factor', 'warning', '—', 'Web nemá žádného administrátora?');
        }

        $enabled = 0;

        foreach ($admins as $user_id) {
            $has = false;

            foreach ($meta_keys as $meta_key) {
                $value = get_user_meta((int) $user_id, $meta_key, true);

                if (!empty($value)) {
                    $has = true;
                    break;
                }
            }

            // Wordfence Login Security drží tajemství ve vlastní tabulce.
            if (!$has) {
                $table = $wpdb->prefix . 'wfls_2fa_secrets';

                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                    $has = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d", (int) $user_id)) > 0;
                }
            }

            if ($has) {
                $enabled++;
            }
        }

        if ($enabled === $total) {
            return self::check('two_factor', 'ok', 'Všichni · ' . $total, 'Zapnuto u všech administrátorů');
        }

        if ($enabled > 0) {
            return self::check('two_factor', 'warning', 'Částečně · ' . $enabled . ' z ' . $total, 'Zapnuto u ' . $enabled . ' ze ' . $total . ' administrátorů');
        }

        return self::check('two_factor', 'error', 'Vypnuto', 'Žádný administrátor nemá dvoufázové ověření');
    }

    /** Přejmenovaná složka wp-content. */
    public static function content_dir()
    {
        $path = (string) wp_parse_url(WP_CONTENT_URL, PHP_URL_PATH);
        $renamed = basename(WP_CONTENT_DIR) !== 'wp-content' && substr($path, -11) !== '/wp-content';

        return $renamed
            ? self::check('content_dir', 'ok', $path, 'Složka je přejmenovaná, výchozí cesta neexistuje')
            : self::check('content_dir', 'error', '/wp-content', 'Výchozí cesta, obsah je dohledatelný');
    }

    /**
     * Změněná adresa přihlášení (WPS Hide Login, Solid Security). Jen
     * doplňková hodnota — jestli je /wp-login.php veřejný, ověří hub zvenku.
     */
    public static function login_slug()
    {
        $slug = (string) get_option('whl_page', '');

        if ($slug === '') {
            $itsec = get_option('itsec-storage');

            if (is_array($itsec) && !empty($itsec['hide-backend']['enabled']) && !empty($itsec['hide-backend']['slug'])) {
                $slug = (string) $itsec['hide-backend']['slug'];
            }
        }

        if ($slug === '') {
            return null;
        }

        return self::check('login_url', 'ok', '/' . ltrim($slug, '/'), 'Přihlášení je na vlastní adrese');
    }

    private static function check($id, $status, $value, $note)
    {
        return array('id' => $id, 'status' => $status, 'value' => $value, 'note' => $note);
    }
}
