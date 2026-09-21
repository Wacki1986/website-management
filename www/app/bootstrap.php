<?php

declare(strict_types=1);

/**
 * Bootstrap správcovské aplikace.
 *
 * Volá ho index.php (web) i skripty v dev/tools/ (příkazová řádka).
 * Vrací připravený Kernel — spuštění requestu je na volajícím.
 *
 * Oproti klientské aplikaci tu není sdílené jádro ani firemní kód:
 * správa běží v jednom adresáři a je jen jedna.
 */

use App\Core\Autoloader;
use App\Core\Kernel;
use App\Core\View\Urls;

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Aplikace vyžaduje PHP 8.1 nebo novější. Server má ' . PHP_VERSION . '.');
}

$rootPath = dirname(__DIR__);

require $rootPath . '/app/Core/Autoloader.php';

Autoloader::addNamespace('App', $rootPath . '/app');
Autoloader::register();

// Globální funkce pro šablony — autoloader umí jen třídy.
foreach (['markup', 'url', 'forms', 'time'] as $helpers) {
    $file = $rootPath . '/app/helpers/' . $helpers . '.php';

    if (is_file($file)) {
        require_once $file;
    }
}

/**
 * Konfigurace je povinná — správa nemá instalační průvodce.
 *
 * Zakládá ji provozovatel ručně podle provozní dokumentace: zkopírovat
 * `config/env.sample.php`, vyplnit, nahrát. Průvodce tu nedává smysl —
 * uživatel aplikace je tentýž člověk, který ji nasazuje.
 */
$envPath = $rootPath . '/config/env.php';

if (!is_file($envPath)) {
    http_response_code(500);
    exit(
        "Chybí konfigurační soubor config/env.php.\n"
        . "Zkopírujte config/env.sample.php, vyplňte ho a nahrajte na server.\n"
        . '(Soubory v config/ se záměrně neverzují ani nenasazují přes deploy.)'
    );
}

$env = require $envPath;

if (!is_array($env)) {
    http_response_code(500);
    exit('Soubor config/env.php musí vracet pole.');
}

$kernel = new Kernel($env, $rootPath);

// Navázání get_url()/get_asset() před boot() — chybová stránka odkazy potřebuje.
Urls::bind($kernel->url(...), $kernel->asset(...));

$kernel->boot();

return $kernel;
