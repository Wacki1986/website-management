<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Přihlášení do administrace jedním klikem ze Správy webů.
 *
 * 1. Hub podepsaným požadavkem (`POST /actions/login-link`) požádá
 *    o odkaz pro účet studia.
 * 2. Plugin vydá jednorázový token (platí minutu) a vrátí odkaz
 *    `https://web/?mg_login=<token>`.
 * 3. Prohlížeč odkaz otevře, `maybe_login()` token spotřebuje, přihlásí
 *    účet a přesměruje do administrace.
 *
 * Heslo se nikde neukládá ani nepřenáší. Token leží v databázi jen jako
 * SHA-256 otisk — z databáze webu ho nikdo nepoužije. Přihlásit jde jen
 * účet s právem správce; na webu jde funkci vypnout v nastavení pluginu.
 */
final class MG_Login
{
    const TTL = 60;
    const QUERY_ARG = 'mg_login';

    public static function is_allowed()
    {
        return get_option(MG_Monitor::OPTION_ALLOW_LOGIN, '1') === '1';
    }

    /**
     * @param string $login uživatelské jméno nebo e-mail účtu
     * @return array|WP_Error `{url, user, expires_in}`
     */
    public static function create_link($login)
    {
        if (!self::is_allowed()) {
            return new WP_Error('login_disabled', 'Přihlášení ze Správy webů je na webu vypnuté (Nastavení → MEDIAGRAFIK Monitor).', array('status' => 403));
        }

        $login = trim((string) $login);
        $user = $login !== '' ? get_user_by('login', $login) : false;

        if (!$user && is_email($login)) {
            $user = get_user_by('email', $login);
        }

        if (!$user) {
            return new WP_Error('login_user_missing', 'Na webu není účet „' . $login . '" — založte ho, nebo ve Správě webů nastavte jiný.', array('status' => 404));
        }

        if (!user_can($user, 'manage_options')) {
            return new WP_Error('login_not_admin', 'Účet „' . $login . '" není na webu správce — přihlášení ze Správy webů jde jen do správcovského účtu.', array('status' => 403));
        }

        $token = bin2hex(random_bytes(32));
        set_transient(self::transient($token), (int) $user->ID, self::TTL);

        return array(
            'url' => add_query_arg(self::QUERY_ARG, $token, home_url('/')),
            'user' => $user->user_login,
            'expires_in' => self::TTL,
        );
    }

    /**
     * Hák `init`: odkaz s tokenem přihlásí a přesměruje do administrace.
     * Token platí jednou — maže se hned při prvním použití, i neúspěšném.
     */
    public static function maybe_login()
    {
        if (!isset($_GET[self::QUERY_ARG])) {
            return;
        }

        $token = (string) wp_unslash($_GET[self::QUERY_ARG]);
        $user_id = 0;

        if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            $user_id = (int) get_transient(self::transient($token));
            delete_transient(self::transient($token));
        }

        $user = $user_id > 0 ? get_user_by('id', $user_id) : false;

        if (!$user || !self::is_allowed() || !user_can($user, 'manage_options')) {
            nocache_headers();
            wp_die(
                'Odkaz na přihlášení už neplatí — platí jen jednou a jen minutu. Klikněte ve Správě webů na „wp-admin" znovu.',
                'Přihlášení',
                array('response' => 403, 'link_url' => wp_login_url(), 'link_text' => 'Přihlásit se heslem')
            );
        }

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());

        // Stejná událost jako po běžném přihlášení — bezpečnostní pluginy
        // si ho zapíšou do svého logu.
        do_action('wp_login', $user->user_login, $user);

        nocache_headers();
        wp_safe_redirect(admin_url());
        exit;
    }

    private static function transient($token)
    {
        return 'mg_monitor_login_' . hash('sha256', (string) $token);
    }
}
