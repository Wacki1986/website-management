<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST endpointy pro hub: `/wp-json/mediagrafik-monitor/v1/{ping,summary,security}`
 * (GET) a akce `POST /actions/{plugin-update,plugin-delete,plugin-activation,
 * core-update,login-link}`.
 *
 * Všechno za klíčem v hlavičce `X-MG-Key`. Odpověď má vždy obálku
 * `{ok, plugin_version, generated_at, data}` nebo
 * `{ok: false, error: {code, message}}` a `Cache-Control: no-store`.
 */
final class MG_Rest_Controller
{
    const NAMESPACE_V1 = 'mediagrafik-monitor/v1';

    public static function register_routes()
    {
        $common = array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'permission'),
        );

        register_rest_route(self::NAMESPACE_V1, '/ping', $common + array('callback' => array(__CLASS__, 'ping')));
        register_rest_route(self::NAMESPACE_V1, '/summary', $common + array('callback' => array(__CLASS__, 'summary')));
        register_rest_route(self::NAMESPACE_V1, '/security', $common + array('callback' => array(__CLASS__, 'security')));

        self::register_action_routes();
    }

    /**
     * Akce, které na webu něco mění. Kromě klíče chtějí podpis s časem
     * (`MG_Api_Key::verify_signature()`) — zachycený požadavek nejde po
     * pěti minutách zopakovat ani mu změnit tělo.
     */
    public static function register_action_routes()
    {
        $actions = array(
            'plugin-update' => 'plugin_update',
            'plugin-delete' => 'plugin_delete',
            'core-update' => 'core_update',
            'login-link' => 'login_link',
            'plugin-activation' => 'plugin_activation',
        );

        foreach ($actions as $path => $callback) {
            register_rest_route(self::NAMESPACE_V1, '/actions/' . $path, array(
                'methods' => 'POST',
                'permission_callback' => array(__CLASS__, 'action_permission'),
                'callback' => array(__CLASS__, $callback),
            ));
        }
    }

    /**
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function action_permission($request)
    {
        $allowed = self::permission($request);

        if ($allowed !== true) {
            return $allowed;
        }

        if (!MG_Api_Key::verify_signature($request, trim((string) $request->get_header('x-mg-key')))) {
            return new WP_Error('invalid_signature', 'Podpis požadavku nesouhlasí nebo vypršel — zkontrolujte čas serveru.', array('status' => 401));
        }

        return true;
    }

    /**
     * Ověření klíče. Vrací true, nebo WP_Error se stejným kódem, jaký hub
     * rozpoznává (`missing_key`, `invalid_key`, `too_many_attempts`).
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function permission($request)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        if (MG_Rate_Limit::too_many($ip)) {
            return new WP_Error('too_many_attempts', 'Příliš mnoho neúspěšných pokusů, zkuste to později.', array('status' => 429));
        }

        $key = trim((string) $request->get_header('x-mg-key'));

        if ($key === '') {
            return new WP_Error('missing_key', 'Chybí hlavička X-MG-Key.', array('status' => 401));
        }

        if (!MG_Api_Key::is_configured()) {
            return new WP_Error('invalid_key', 'Plugin zatím nemá vložený API klíč (Nastavení → MEDIAGRAFIK Monitor).', array('status' => 401));
        }

        if (!MG_Api_Key::verify($key)) {
            MG_Rate_Limit::record_failure($ip);

            return new WP_Error('invalid_key', 'Neplatný API klíč.', array('status' => 401));
        }

        update_option(MG_Monitor::OPTION_LAST_SEEN, current_time('mysql'), false);

        return true;
    }

    /**
     * Bezpečnostní pluginy zavírají REST anonymům přes
     * `rest_authentication_errors`. Náš požadavek s platným klíčem se
     * pustí dál; všechno ostatní zůstává, jak bylo.
     *
     * @param WP_Error|null|true $result
     */
    public static function allow_keyed_request($result)
    {
        $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';

        if ($route === '' && isset($_SERVER['REQUEST_URI'])) {
            $route = (string) $_SERVER['REQUEST_URI'];
        }

        if (strpos($route, self::NAMESPACE_V1) === false) {
            return $result;
        }

        $key = isset($_SERVER['HTTP_X_MG_KEY']) ? trim((string) $_SERVER['HTTP_X_MG_KEY']) : '';

        return $key !== '' && MG_Api_Key::verify($key) ? true : $result;
    }

    // -----------------------------------------------------------------
    // Endpointy
    // -----------------------------------------------------------------

    public static function ping()
    {
        return self::respond(array(
            'site_url' => home_url('/'),
            'wp_version' => get_bloginfo('version'),
            'time' => current_time('c'),
        ));
    }

    public static function summary()
    {
        $collector = new MG_Collector();
        $data = $collector->all();
        $data['security'] = MG_Security_Checks::run();

        return self::respond($data);
    }

    public static function security()
    {
        return self::respond(array('security' => MG_Security_Checks::run()));
    }

    /**
     * @param WP_REST_Request $request tělo `{"plugins": ["slozka/soubor.php", …]}`
     * @return WP_REST_Response|WP_Error
     */
    public static function plugin_update($request)
    {
        $body = json_decode((string) $request->get_body(), true);
        $files = is_array($body) && isset($body['plugins']) && is_array($body['plugins']) ? $body['plugins'] : array();
        $items = MG_Plugin_Updates::run($files);

        if (is_wp_error($items)) {
            return $items;
        }

        return self::respond(array('plugins' => $items));
    }

    /**
     * @param WP_REST_Request $request tělo `{"plugins": ["slozka/soubor.php", …]}`
     * @return WP_REST_Response|WP_Error
     */
    public static function plugin_delete($request)
    {
        $body = json_decode((string) $request->get_body(), true);
        $files = is_array($body) && isset($body['plugins']) && is_array($body['plugins']) ? $body['plugins'] : array();
        $items = MG_Site_Actions::delete_plugins($files);

        if (is_wp_error($items)) {
            return $items;
        }

        return self::respond(array('plugins' => $items));
    }

    /**
     * @param WP_REST_Request $request tělo `{"version": "6.9"}` — verze, kterou člověk potvrdil
     * @return WP_REST_Response|WP_Error
     */
    public static function core_update($request)
    {
        $body = json_decode((string) $request->get_body(), true);
        $result = MG_Site_Actions::update_core(is_array($body) && isset($body['version']) ? (string) $body['version'] : '');

        if (is_wp_error($result)) {
            return $result;
        }

        return self::respond(array('core' => $result));
    }

    /**
     * @param WP_REST_Request $request tělo `{"plugin": "slozka/soubor.php", "active": true}`
     * @return WP_REST_Response|WP_Error
     */
    public static function plugin_activation($request)
    {
        $body = json_decode((string) $request->get_body(), true);
        $result = MG_Site_Actions::set_active(
            is_array($body) && isset($body['plugin']) ? (string) $body['plugin'] : '',
            is_array($body) && !empty($body['active'])
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return self::respond(array('plugin' => $result));
    }

    /**
     * @param WP_REST_Request $request tělo `{"user": "mediagrafik"}`
     * @return WP_REST_Response|WP_Error
     */
    public static function login_link($request)
    {
        $body = json_decode((string) $request->get_body(), true);
        $link = MG_Login::create_link(is_array($body) && isset($body['user']) ? (string) $body['user'] : '');

        if (is_wp_error($link)) {
            return $link;
        }

        return self::respond(array('login' => $link));
    }

    /**
     * Obálka odpovědi. Chyby z `permission()` balí WordPress sám do
     * `{code, message, data}` — hub podle `code` pozná, co se stalo.
     *
     * @param array $data
     * @return WP_REST_Response
     */
    private static function respond($data)
    {
        $response = new WP_REST_Response(array(
            'ok' => true,
            'plugin_version' => MG_Monitor::VERSION,
            'generated_at' => current_time('c'),
            'data' => $data,
        ));

        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }
}
