<?php

declare(strict_types=1);

/**
 * Router pro vestavěný PHP server.
 *
 * Spuštění z kořene projektu:
 *   php -S 127.0.0.1:8099 -t www dev/tools/serve.php
 *
 * `-t www` je povinné: nasaditelná část leží ve `www/` a na server se nahrává
 * její **obsah**. Vestavěný server statické soubory doručuje sám (router vrátí
 * `false`) a hledá je v kořeni, který mu `-t` určí — bez něj by assety končily
 * na 404.
 *
 * Vestavěný server nezná .htaccess, takže by bez tohoto skriptu servíroval
 * zdrojové soubory přímo (a lokální vývoj by se choval jinak než produkce).
 * Skript proto kopíruje pravidlo z .htaccess: veřejné je jen /assets/,
 * všechno ostatní jde přes index.php.
 */

$root = dirname(__DIR__, 2);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Průchod adresářem nahoru nemá v cestě k assetu co dělat — a v tomhle skriptu
// se skládá cesta k souboru ručně, takže si to musí ohlídat sám.
if (str_contains($path, '..')) {
    http_response_code(400);

    return true;
}

$public = ['/favicon.ico', '/robots.txt', '/sw.js'];

if (str_starts_with($path, '/assets/') || in_array($path, $public, true)) {
    $file = $root . '/www' . $path;

    if (!is_file($file)) {
        return http_response_code(404);
    }

    // Manifest PWA vydáme ručně: vestavěný server `.webmanifest` nezná
    // a poslal by ho jako octet-stream, což prohlížeč zahodí. Na produkci
    // to řeší `AddType` v `www/.htaccess`.
    if (str_ends_with($path, '.webmanifest')) {
        header('Content-Type: application/manifest+json');
        readfile($file);

        return true;
    }

    // Vestavěný server umí statický soubor doručit sám (vrácením false).
    return false;
}

require $root . '/www/index.php';
