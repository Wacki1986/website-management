<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Brzda proti hádání klíče: 10 neúspěšných pokusů z jedné adresy za
 * 10 minut a další požadavky dostanou 429. Transient, žádná tabulka.
 */
final class MG_Rate_Limit
{
    const MAX_FAILURES = 10;
    const WINDOW = 600;

    public static function too_many($ip)
    {
        return (int) get_transient(self::key($ip)) >= self::MAX_FAILURES;
    }

    public static function record_failure($ip)
    {
        $key = self::key($ip);
        $count = (int) get_transient($key);

        set_transient($key, $count + 1, self::WINDOW);
    }

    private static function key($ip)
    {
        return 'mg_monitor_fail_' . md5((string) $ip);
    }
}
