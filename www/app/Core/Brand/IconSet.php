<?php

declare(strict_types=1);

namespace App\Core\Brand;

use GdImage;
use RuntimeException;

/**
 * Sada ikon aplikace z jednoho obrázku znaku (GD).
 *
 * Používá ji nahrání ikony v Nastavení → Základní nastavení i `dev/tools/build-favicons.php`
 * (ten si znak nejdřív vykreslí ze SVG Chromem). Vstupem je obrázek
 * s průhledným pozadím; okraje kolem znaku se oříznou, aby šel přesně
 * vycentrovat. Vznikne:
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
 */
final class IconSet
{
    /** Soubory sady — stejná jména jako výchozí ikony v `assets/favicons/`. */
    public const FILES = [
        'favicon.ico',
        'favicon-96x96.png',
        'apple-touch-icon.png',
        'icon-192.png',
        'icon-512.png',
        'icon-maskable-512.png',
        'badge-96.png',
    ];

    /** Větší vstup se nejdřív zmenší — ořez prochází každý pixel. */
    private const WORK_SIZE = 1024;

    private const WHITE = [255, 255, 255];

    private GdImage $source;

    private int $minX;

    private int $minY;

    private int $markW;

    private int $markH;

    public function __construct(GdImage $image)
    {
        $this->source = self::shrink($image);
        $this->trim();
    }

    /** Všechny soubory sady do složky `$dir` (musí existovat). */
    public function write(string $dir): void
    {
        $this->save($this->compose(96, 0.92, null), $dir . '/favicon-96x96.png');
        $this->save($this->compose(180, 0.72, self::WHITE), $dir . '/apple-touch-icon.png');
        $this->save($this->compose(192, 0.72, self::WHITE), $dir . '/icon-192.png');
        $this->save($this->compose(512, 0.72, self::WHITE), $dir . '/icon-512.png');
        // Bezpečný kruh má poloměr 40 % strany; znak 0,58 strany vysoký se do něj vejde i rohy.
        $this->save($this->compose(512, 0.58, self::WHITE), $dir . '/icon-maskable-512.png');
        $this->save($this->badge(), $dir . '/badge-96.png');

        if (file_put_contents($dir . '/favicon.ico', $this->ico()) === false) {
            throw new RuntimeException('Nepodařilo se uložit favicon.ico.');
        }
    }

    /**
     * Čtverec `$size` px se znakem uprostřed. `$scale` = jak velkou část
     * strany zabere delší rozměr znaku; `$background` null = průhledné.
     *
     * @param array{0: int, 1: int, 2: int}|null $background
     */
    private function compose(int $size, float $scale, ?array $background): GdImage
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $background !== null
            ? (int) imagecolorallocate($image, ...$background)
            : (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        $ratio = $scale * $size / max($this->markW, $this->markH);
        $w = max(1, (int) round($this->markW * $ratio));
        $h = max(1, (int) round($this->markH * $ratio));

        // Na barevný podklad se kreslí s mícháním (hladké okraje), na průhledný bez.
        imagealphablending($image, $background !== null);
        imagecopyresampled($image, $this->source, intdiv($size - $w, 2), intdiv($size - $h, 2), $this->minX, $this->minY, $w, $h, $this->markW, $this->markH);

        return $image;
    }

    /** Badge: bílá silueta — barvy pryč, průhlednost zůstává. */
    private function badge(): GdImage
    {
        $badge = $this->compose(96, 0.9, null);

        for ($y = 0; $y < 96; $y++) {
            for ($x = 0; $x < 96; $x++) {
                $alpha = (imagecolorat($badge, $x, $y) >> 24) & 0x7F;
                imagesetpixel($badge, $x, $y, (int) imagecolorallocatealpha($badge, 255, 255, 255, $alpha));
            }
        }

        return $badge;
    }

    /** favicon.ico: PNG obrázky v kontejneru ICO (umí každý prohlížeč od IE 11). */
    private function ico(): string
    {
        $entries = [];

        foreach ([16, 32, 48] as $size) {
            $entries[$size] = self::png($this->compose($size, 0.96, null));
        }

        $ico = pack('vvv', 0, 1, count($entries));
        $offset = 6 + 16 * count($entries);

        foreach ($entries as $size => $data) {
            $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($data), $offset);
            $offset += strlen($data);
        }

        return $ico . implode('', $entries);
    }

    /**
     * Ořez na obsah: hledají se pixely, které nejsou (skoro) průhledné.
     * Obrázek bez průhlednosti (JPG, PNG s bílým pozadím) zůstane celý.
     */
    private function trim(): void
    {
        $width = imagesx($this->source);
        $height = imagesy($this->source);
        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (((imagecolorat($this->source, $x, $y) >> 24) & 0x7F) < 120) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX < 0) {
            throw new RuntimeException('Obrázek je celý průhledný — není z čeho udělat ikonu.');
        }

        $this->minX = $minX;
        $this->minY = $minY;
        $this->markW = $maxX - $minX + 1;
        $this->markH = $maxY - $minY + 1;
    }

    /** Zmenší velký vstup na pracovní velikost a převede ho na truecolor s průhledností. */
    private static function shrink(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = min(1.0, self::WORK_SIZE / max($width, $height));
        $w = max(1, (int) round($width * $ratio));
        $h = max(1, (int) round($height * $ratio));

        $target = imagecreatetruecolor($w, $h);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $target;
    }

    private function save(GdImage $image, string $path): void
    {
        if (!imagepng($image, $path, 9)) {
            throw new RuntimeException('Nepodařilo se uložit ' . basename($path) . '.');
        }
    }

    /** Obsah PNG jako řetězec — bez `ob_start()`: přes dočasný soubor. */
    private static function png(GdImage $image): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ico');
        imagepng($image, $file, 9);
        $data = (string) file_get_contents($file);
        @unlink($file);

        return $data;
    }
}
