<?php

declare(strict_types=1);

/**
 * Průchod monitoru z příkazové řádky — pro hosting se systémovým cronem
 * bez možnosti volat adresu přes wget.
 *
 *   (každých 5 minut) php /cesta/k/dev/tools/monitor-cron.php >> /cesta/k/www/storage/logs/monitor-cron.log 2>&1
 *
 * Dělá totéž co `GET /system/monitor-cron?token=…`, jen bez tokenu — kdo
 * spouští skript na serveru, je vlastník.
 */

if (PHP_SAPI !== 'cli') {
    exit('Skript se spouští z příkazové řádky.');
}

/** @var \App\Core\Kernel $kernel */
$kernel = require dirname(__DIR__, 2) . '/www/app/bootstrap.php';

$summary = $kernel->monitor()->run();

echo date('Y-m-d H:i:s'), ' ', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

exit($summary['status'] === 'error' ? 1 : 0);
