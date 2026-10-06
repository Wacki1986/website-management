<?php

declare(strict_types=1);

/**
 * Ikony aplikace z jednoho SVG: `www/assets/favicons/favicon.svg`.
 *
 * Spuštění z kořene projektu:  php dev/tools/build-favicons.php
 *
 * SVG vykreslí Chrome bez okna (GD ani PHP samo SVG neumí) na průhledný
 * čtverec 1024 px; z něj se v GD složí všechny velikosti:
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

$source = imagecreatefrompng($png);

// --- 2. Ořez na obsah: znak pak jde přesně vycentrovat ------------------

$minX = $minY = PHP_INT_MAX;
$maxX = $maxY = 0;

for ($y = 0; $y < imagesy($source); $y++) {
    for ($x = 0; $x < imagesx($source); $x++) {
        if (((imagecolorat($source, $x, $y) >> 24) & 0x7F) < 120) {
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }
    }
}

$markW = $maxX - $minX + 1;
$markH = $maxY - $minY + 1;

/**
 * Čtverec `$size` px se znakem uprostřed. `$scale` = jak velkou část
 * strany zabere delší rozměr znaku; `$background` null = průhledné.
 *
 * @param array{0: int, 1: int, 2: int}|null $background
 */
$compose = static function (int $size, float $scale, ?array $background) use ($source, $minX, $minY, $markW, $markH): GdImage {
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, $background !== null
        ? imagecolorallocate($image, ...$background)
        : imagecolorallocatealpha($image, 0, 0, 0, 127));

    $ratio = $scale * $size / max($markW, $markH);
    $w = (int) round($markW * $ratio);
    $h = (int) round($markH * $ratio);

    // Na barevný podklad se kreslí s mícháním (hladké okraje), na průhledný bez.
    imagealphablending($image, $background !== null);
    imagecopyresampled($image, $source, intdiv($size - $w, 2), intdiv($size - $h, 2), $minX, $minY, $w, $h, $markW, $markH);

    return $image;
};

$white = [255, 255, 255];
$written = [];
$save = static function (GdImage $image, string $name) use ($dir, &$written): void {
    imagepng($image, $dir . '/' . $name, 9);
    $written[] = $name;
};

// --- 3. Velikosti ---------------------------------------------------------

$save($compose(96, 0.92, null), 'favicon-96x96.png');
$save($compose(180, 0.72, $white), 'apple-touch-icon.png');
$save($compose(192, 0.72, $white), 'icon-192.png');
$save($compose(512, 0.72, $white), 'icon-512.png');
// Bezpečný kruh má poloměr 40 % strany; znak 0,58 strany vysoký se do něj vejde i rohy.
$save($compose(512, 0.58, $white), 'icon-maskable-512.png');

// Badge: bílá silueta — barvy pryč, průhlednost zůstává.
$badge = $compose(96, 0.9, null);

for ($y = 0; $y < 96; $y++) {
    for ($x = 0; $x < 96; $x++) {
        $alpha = (imagecolorat($badge, $x, $y) >> 24) & 0x7F;
        imagesetpixel($badge, $x, $y, imagecolorallocatealpha($badge, 255, 255, 255, $alpha));
    }
}

$save($badge, 'badge-96.png');

// favicon.ico: PNG obrázky v kontejneru ICO (umí každý prohlížeč od IE 11).
$entries = [];

foreach ([16, 32, 48] as $size) {
    ob_start();
    imagepng($compose($size, 0.96, null), null, 9);
    $entries[$size] = (string) ob_get_clean();
}

$ico = pack('vvv', 0, 1, count($entries));
$offset = 6 + 16 * count($entries);

foreach ($entries as $size => $data) {
    $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($data), $offset);
    $offset += strlen($data);
}

file_put_contents($dir . '/favicon.ico', $ico . implode('', $entries));
$written[] = 'favicon.ico';

@unlink($page);
@unlink($png);
@rmdir($work);

echo 'Hotovo: ' . implode(', ', $written) . PHP_EOL;
