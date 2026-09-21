<?php

declare(strict_types=1);

/**
 * Vzor konfigurace Správy webů.
 *
 * Zkopírujte jako `config/env.php` a vyplňte. Soubor je mimo git i deploy —
 * obsahuje tajemství. Hodnotu pro `app_key` vygeneruje
 * `php dev/tools/generate-tokens.php`.
 */

return [
    // 'production' | 'development' — na produkci se chyby nezobrazují, jen logují.
    'environment' => 'production',

    // Absolutní adresa aplikace (bez lomítka na konci). Z ní se skládají
    // odkazy v e-mailech i adresa pro cron.
    'app_url' => 'https://sprava.mediagrafik.cz',

    'timezone' => 'Europe/Prague',

    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
        'charset' => 'utf8mb4',
        'timeout' => 5,
    ],

    /**
     * Šifrovací klíč (64 hexa znaků). Šifrují se jím tajemství v databázi —
     * API klíče webů, SMTP heslo, token cronu, klíč pro upozornění.
     * Ztráta klíče = ztráta všech klíčů webů; zálohujte ho mimo server.
     */
    'app_key' => '',

    /**
     * Omezení na povolené adresy — vrstva NAD přihlášením. Prázdné pole =
     * vypnuto. Přesné IP nebo IPv4 CIDR. Primární filtr patří do .htaccess;
     * tenhle je záložní a ukáže srozumitelnou 403 s vaší IP.
     * Pozor: s IP filtrem nefunguje klepnutí na upozornění z mobilní sítě.
     */
    'allowed_ips' => [
        // '89.103.12.44',
        // '10.0.0.0/8',
    ],

    // Nepovinné: vlastní umístění storage (logy, relace). Výchozí je storage/.
    // 'storage_path' => __DIR__ . '/../storage',

    /**
     * Záložní nastavení pošty (zapomenuté heslo), dokud se nevyplní
     * Nastavení → Odchozí pošta v aplikaci (tam se ukládá i SMTP heslo,
     * šifrovaně). 'transport': 'none' | 'mail' | 'smtp' | 'log'.
     * Ve vývoji se hodí 'log' — e-maily končí jako soubory ve storage/logs.
     */
    'mail' => [
        'transport' => 'none',
        'from_address' => 'monitor@mediagrafik.cz',
        'from_name' => 'MEDIAGRAFIK · správa webů',
    ],

    /**
     * Weby se evidují jen přes https://. Kdo potřebuje hlídat i web bez
     * certifikátu (vývojový server), zapne tohle — API klíč pak jde po síti
     * nešifrovaně, proto výchozí false.
     */
    'allow_insecure_sites' => false,
];
