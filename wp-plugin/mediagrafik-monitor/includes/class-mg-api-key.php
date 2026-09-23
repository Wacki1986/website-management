<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API klíč hubu — ukládá se jen jako SHA-256 hash + poslední 4 znaky.
 *
 * Kdo se dostane k databázi webu (záloha, jiný plugin), klíč nezíská;
 * hub ho má šifrovaný u sebe a při ztrátě vygeneruje nový.
 */
final class MG_Api_Key
{
    const PREFIX = 'mg_live_';

    /** Uloží klíč (hash + hint). Prázdný klíč = odpojení. */
    public static function store($key)
    {
        $key = trim((string) $key);

        if ($key === '') {
            delete_option(MG_Monitor::OPTION_KEY_HASH);
            delete_option(MG_Monitor::OPTION_KEY_HINT);

            return;
        }

        update_option(MG_Monitor::OPTION_KEY_HASH, hash('sha256', $key), false);
        update_option(MG_Monitor::OPTION_KEY_HINT, substr($key, -4), false);
    }

    public static function is_configured()
    {
        return (string) get_option(MG_Monitor::OPTION_KEY_HASH, '') !== '';
    }

    /** Porovnání v konstantním čase. */
    public static function verify($key)
    {
        $stored = (string) get_option(MG_Monitor::OPTION_KEY_HASH, '');
        $key = (string) $key;

        if ($stored === '' || $key === '') {
            return false;
        }

        return hash_equals($stored, hash('sha256', $key));
    }

    public static function hint()
    {
        return (string) get_option(MG_Monitor::OPTION_KEY_HINT, '');
    }

    /** Tvar klíče z hubu: `mg_live_` + 32 znaků. */
    public static function looks_valid($key)
    {
        return preg_match('/^' . preg_quote(self::PREFIX, '/') . '[A-Za-z0-9]{32}$/', (string) $key) === 1;
    }

    /**
     * Podpis mutujících požadavků (aktualizace pluginů).
     *
     * `X-MG-Timestamp` + `X-MG-Signature` = HMAC-SHA256(klíč, metoda \n cesta
     * \n timestamp \n tělo). Okno ±5 minut. Protějšek v hubu:
     * `PluginClient::signature()`.
     *
     * @param WP_REST_Request $request
     * @param string          $key     čitelný klíč (jen hub ho zná — sem se
     *                                 dostane jako hlavička X-MG-Key)
     */
    public static function verify_signature($request, $key)
    {
        $timestamp = (int) $request->get_header('x-mg-timestamp');
        $signature = (string) $request->get_header('x-mg-signature');

        if ($timestamp === 0 || $signature === '' || abs(time() - $timestamp) > 300) {
            return false;
        }

        $payload = $request->get_method() . "\n" . $request->get_route() . "\n" . $timestamp . "\n" . $request->get_body();

        return hash_equals(hash_hmac('sha256', $payload, (string) $key), $signature);
    }
}
