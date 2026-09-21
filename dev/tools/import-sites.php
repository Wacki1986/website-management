<?php

declare(strict_types=1);

/**
 * Hromadné přidání webů z CSV — na první nasazení (desítky webů).
 *
 *   php dev/tools/import-sites.php weby.csv [--klice]
 *
 * CSV (UTF-8, oddělovač čárka nebo středník, první řádek hlavička):
 *   nazev;url;klient;interval;hosting;zalohy
 * Povinné jsou `nazev` a `url`. `klient` je název klienta — když
 * neexistuje, založí se. `interval` v minutách (5/15/30/60, výchozí 15).
 *
 * S `--klice` se každému novému webu rovnou vygeneruje API klíč a vypíše
 * se do `www/storage/import-klice.txt` (jen jednou — klíče se ukládají
 * šifrované a znovu se nedají přečíst). Soubor po vložení do pluginů
 * smažte.
 *
 * Skript je idempotentní: web se stejnou adresou se přeskočí.
 */

use App\Core\Sites\ApiKey;
use App\Core\Sites\SiteRepository;

if (PHP_SAPI !== 'cli') {
    exit("Jen z příkazové řádky.\n");
}

$args = array_slice($argv, 1);
$withKeys = in_array('--klice', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--klice'));
$file = $args[0] ?? '';

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Použití: php dev/tools/import-sites.php weby.csv [--klice]\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
/** @var \App\Core\Kernel $kernel */
$kernel = require $root . '/www/app/bootstrap.php';
$sites = $kernel->sites();
$clients = $kernel->clients();

$handle = fopen($file, 'r');

if ($handle === false) {
    fwrite(STDERR, "Soubor nejde otevřít.\n");
    exit(1);
}

$first = fgets($handle);

if ($first === false) {
    exit("Prázdný soubor.\n");
}

$first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
$delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
$header = array_map(static fn (string $h): string => mb_strtolower(trim($h)), str_getcsv($first, $delimiter));

$col = static function (array $row, string $name) use ($header): string {
    $index = array_search($name, $header, true);

    return $index !== false ? trim((string) ($row[$index] ?? '')) : '';
};

$added = 0;
$skipped = 0;
$keys = [];
$clientCache = [];

foreach ($clients->all() as $client) {
    $clientCache[mb_strtolower((string) $client['name'])] = (int) $client['id'];
}

while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    if ($row === [null] || $row === []) {
        continue;
    }

    $name = $col($row, 'nazev');
    try {
        $url = $col($row, 'url') !== '' ? SiteRepository::normalizeUrl($col($row, 'url')) : null;
    } catch (Throwable) {
        $url = null;
    }

    if ($name === '' || $url === null) {
        fwrite(STDERR, "Přeskočeno (chybí název nebo adresa): " . implode($delimiter, $row) . "\n");
        $skipped++;

        continue;
    }

    if ($sites->findByUrl($url) !== null) {
        echo "= {$url} už existuje\n";
        $skipped++;

        continue;
    }

    $clientName = $col($row, 'klient');
    $clientId = null;

    if ($clientName !== '') {
        $key = mb_strtolower($clientName);

        if (!isset($clientCache[$key])) {
            $clientCache[$key] = $clients->create(['name' => $clientName]);
            echo "+ klient {$clientName}\n";
        }

        $clientId = $clientCache[$key];
    }

    $interval = (int) $col($row, 'interval');
    $id = $sites->create([
        'name' => $name,
        'url' => $url,
        'client_id' => $clientId,
        'check_interval_min' => isset(SiteRepository::INTERVALS[$interval]) ? $interval : 15,
        'hosting_note' => mb_substr($col($row, 'hosting'), 0, 120),
        'backup_note' => mb_substr($col($row, 'zalohy'), 0, 120),
    ]);

    if ($withKeys) {
        $apiKey = ApiKey::generate();
        $sites->setApiKey($id, $apiKey);
        $keys[] = $name . "\t" . $url . "\t" . $apiKey;
    }

    echo "+ {$name} ({$url})\n";
    $added++;
}

fclose($handle);

if ($keys !== []) {
    $out = $root . '/www/storage/import-klice.txt';
    file_put_contents($out, "# API klíče z importu " . date('Y-m-d H:i') . " — po vložení do pluginů soubor smažte\n" . implode("\n", $keys) . "\n");
    echo "Klíče: {$out}\n";
}

echo "Hotovo: přidáno {$added}, přeskočeno {$skipped}.\n";
