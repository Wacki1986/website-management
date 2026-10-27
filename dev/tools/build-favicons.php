<?php

declare(strict_types=1);

/**
 * Ikony aplikace z jednoho SVG: `www/assets/favicons/favicon.svg`.
 *
 * Spuštění z kořene projektu:  php dev/tools/build-favicons.php
 *
 * SVG vykreslí Chrome bez okna (GD ani PHP samo SVG neumí) na průhledný
 * čtverec 1024 px; z něj složí všechny velikosti `App\Core\Brand\IconSet`
 * — tatáž třída, která dělá sadu z ikony nahrané v Nastavení → Základní nastavení:
 *
 *  - `favicon.ico` (16, 32, 48) a `favicon-96x96.png` — záložka
 *    prohlížeče, průhledné pozadí;
 *  - `apple-touch-icon.png` (180) — plocha iPhonu; iOS průhlednost vyplní
 *    černou, proto bílý podklad;
 *  - `icon-192.png`, `icon-512.png` — instalace na plochu (manifest)
 *    a ikona notifikací, bílý podklad;
 *  - `icon-maskable-512.png` — Android ořízne do kruhu/kapky: znak musí
 *    celý ležet v bezpečném kruhu (80 % průměru), proto je menší;
 *  - `badge-96.png` — ikonka ve stavovém řádku telefonu. Android z ní
 *    bere jen průhlednost, proto bílá silueta znaku.
 *
 * Po nové favicone stačí nahrát SVG a skript spustit znovu.
 */

$root = dirname(__DIR__, 2);
$dir = $root . '/www/assets/favicons';
$svg = $dir . '/favicon.svg';
$chrome = getenv('CHROME') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';

if (!is_file($svg)) {
    fwrite(STDERR, "Chybí $svg\n");
    exit(1);
}

if (!is_file($chrome)) {
    fwrite(STDERR, "Chrome nenalezen ($chrome) — cestu nastavte v proměnné prostředí CHROME.\n");
    exit(1);
}

if (!function_exists('imagecreatefrompng')) {
    fwrite(STDERR, "Chybí rozšíření GD.\n");
    exit(1);
}

// --- 1. SVG → průhledné PNG 1024 px -------------------------------------

$work = sys_get_temp_dir() . '/sprava-favicons-' . getmypid();
@mkdir($work, 0775, true);
$page = $work . '/mark.html';
$png = $work . '/mark.png';
$svgUrl = 'file:///' . str_replace('\\', '/', realpath($svg));

file_put_contents($page, '<!doctype html><html><head><style>html,body{margin:0;background:transparent}'
    . 'img{display:block;width:1024px;height:1024px}</style></head><body><img src="' . htmlspecialchars($svgUrl) . '"></body></html>');

$command = escapeshellarg($chrome) . ' --headless=new --disable-gpu --allow-file-access-from-files --hide-scrollbars'
    . ' --default-background-color=00000000 --force-device-scale-factor=1 --window-size=1024,1024'
    . ' --screenshot=' . escapeshellarg($png) . ' ' . escapeshellarg('file:///' . str_replace('\\', '/', $page)) . ' 2>&1';
exec($command);

if (!is_file($png)) {
    fwrite(STDERR, "Chrome SVG nevykreslil.\n");
    exit(1);
}

// --- 2. Velikosti (IconSet) -------------------------------------------

require $root . '/www/app/Core/Autoloader.php';
App\Core\Autoloader::addNamespace('App', $root . '/www/app');
App\Core\Autoloader::register();

(new App\Core\Brand\IconSet(imagecreatefrompng($png)))->write($dir);

@unlink($page);
@unlink($png);
@rmdir($work);

echo 'Hotovo: ' . implode(', ', App\Core\Brand\IconSet::FILES) . PHP_EOL;
