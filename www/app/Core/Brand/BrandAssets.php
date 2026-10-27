<?php

declare(strict_types=1);

namespace App\Core\Brand;

use App\Core\Http\HttpException;
use RuntimeException;
use Throwable;

/**
 * Loga a ikona instance (Nastavení → Základní nastavení).
 *
 * Soubory leží ve `storage/brand/`: logo jako `{slot}.{přípona}`, sada ikon
 * v `storage/brand/icons/` (vyrábí ji `IconSet` z nahraného obrázku).
 * Storage není web root — ven jdou routou `/znacka/{soubor}`, veřejně,
 * protože logo potřebuje i přihlašovací stránka a poštovní klient
 * příjemce reportu.
 *
 * Co nahrané není, má výchozí soubor v `assets/` (neutrální logo „Správa
 * webů“, výchozí ikony), nebo nic — logo firmy pak nahradí její název
 * textem.
 */
final class BrandAssets
{
    /**
     * Sloty pro loga: klíč => adresa ve `/znacka/`, popisek, výchozí soubor
     * v `assets/` (null = žádný) a povolené typy.
     */
    public const SLOTS = [
        'app' => [
            'file' => 'logo-aplikace',
            'label' => 'Logo aplikace',
            'hint' => 'V liště a na přihlášení, světlý motiv. Nejlépe SVG, na šířku.',
            'default' => 'img/sprava-logo.svg',
        ],
        'app_dark' => [
            'file' => 'logo-aplikace-tmave',
            'label' => 'Logo aplikace — tmavý motiv',
            'hint' => 'Stejné logo se světlým písmem. Bez něj se v tmavém motivu ukáže výchozí.',
            'default' => 'img/sprava-logo-dark.svg',
        ],
        'studio' => [
            'file' => 'logo-studia',
            'label' => 'Logo firmy',
            'hint' => 'Hlavička e-mailů a klientských reportů (bílé pozadí). PNG — Gmail ani Outlook SVG nezobrazí.',
            'default' => null,
        ],
        'studio_inverse' => [
            'file' => 'logo-studia-na-tmavem',
            'label' => 'Logo firmy na tmavém',
            'hint' => 'Tmavé záhlaví servisního protokolu v PDF — světlá varianta loga. Bez něj se tam napíše název firmy.',
            'default' => null,
        ],
    ];

    /** MIME (zjištěný z obsahu) => přípona uloženého souboru. */
    private const LOGO_TYPES = [
        'image/svg+xml' => 'svg',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    private const MAX_SIZE = 2 * 1024 * 1024;

    /** Ikona se dělá z rastru — menší obrázek by na ploše telefonu byl rozmazaný. */
    public const ICON_MIN_SIZE = 512;

    private const DIRECTORY = 'brand';

    public function __construct(
        private readonly string $storagePath,
        private readonly string $assetsPath,
    ) {
    }

    // -----------------------------------------------------------------
    // Cesty a adresy
    // -----------------------------------------------------------------

    /** Absolutní cesta k nahranému logu slotu, nebo null. */
    public function uploaded(string $slot): ?string
    {
        foreach (self::LOGO_TYPES as $extension) {
            $path = $this->directory() . '/' . $slot . '.' . $extension;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Nahrané logo, jinak výchozí z `assets/`, jinak null. */
    public function logoPath(string $slot): ?string
    {
        $uploaded = $this->uploaded($slot);

        if ($uploaded !== null) {
            return $uploaded;
        }

        $default = self::SLOTS[$slot]['default'] ?? null;

        return $default !== null ? $this->assetsPath . '/' . $default : null;
    }

    public function hasIcon(): bool
    {
        return is_file($this->iconDirectory() . '/icon-512.png');
    }

    /** Ikona ze sady: nahraná, jinak výchozí z `assets/favicons/`. */
    public function iconPath(string $file): ?string
    {
        if (!in_array($file, IconSet::FILES, true)) {
            return null;
        }

        $uploaded = $this->iconDirectory() . '/' . $file;

        return is_file($uploaded) ? $uploaded : $this->assetsPath . '/favicons/' . $file;
    }

    /**
     * Cesta pro adresu loga v aplikaci: nahrané jde přes routu s otiskem
     * (po výměně se nesmí držet v cache), výchozí rovnou jako statický asset.
     *
     * @return array{kind: 'route'|'asset', path: string}|null
     */
    public function logoTarget(string $slot): ?array
    {
        $uploaded = $this->uploaded($slot);

        if ($uploaded !== null) {
            return ['kind' => 'route', 'path' => 'znacka/' . self::SLOTS[$slot]['file'] . '?v=' . self::stamp($uploaded)];
        }

        $default = self::SLOTS[$slot]['default'] ?? null;

        return $default !== null ? ['kind' => 'asset', 'path' => $default] : null;
    }

    /**
     * Cesta pro adresu ikony: nahraná sada přes routu s otiskem, jinak
     * statický soubor z `assets/favicons/`. Null = soubor v nahrané sadě
     * není (`favicon.svg` — nahraná ikona je rastr).
     *
     * @return array{kind: 'route'|'asset', path: string}|null
     */
    public function iconTarget(string $file): ?array
    {
        if (!$this->hasIcon()) {
            return ['kind' => 'asset', 'path' => 'favicons/' . $file];
        }

        return in_array($file, IconSet::FILES, true)
            ? ['kind' => 'route', 'path' => 'znacka/' . $file . '?v=' . self::stamp($this->iconDirectory() . '/icon-512.png')]
            : null;
    }

    /**
     * Soubor pro výdej `/znacka/{file}` — logo podle adresy slotu, nebo
     * ikona ze sady. Null = taková adresa neexistuje.
     *
     * @return array{path: string, mime: string}|null
     */
    public function resolve(string $file): ?array
    {
        foreach (self::SLOTS as $slot => $definition) {
            if ($definition['file'] === $file) {
                $path = $this->logoPath($slot);

                return $path !== null && is_file($path) ? ['path' => $path, 'mime' => self::mimeOf($path)] : null;
            }
        }

        $path = $this->iconPath($file);

        return $path !== null && is_file($path) ? ['path' => $path, 'mime' => self::mimeOf($path)] : null;
    }

    // -----------------------------------------------------------------
    // Nahrání a odebrání
    // -----------------------------------------------------------------

    /**
     * Uloží logo do slotu. Typ se určuje z obsahu, ne z přípony.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file
     */
    public function storeLogo(string $slot, ?array $file): void
    {
        if (!isset(self::SLOTS[$slot])) {
            throw HttpException::validation('Neznámé místo pro logo.');
        }

        $bytes = $this->readUpload($file);
        $mime = self::detectMime($bytes);

        if ($mime === null) {
            throw HttpException::validation('Logo může být SVG, PNG nebo JPG.');
        }

        if ($mime === 'image/svg+xml' && self::svgHasActiveContent($bytes)) {
            throw HttpException::validation('SVG obsahuje skript nebo vložený obsah — uložte ho v editoru znovu jako prosté SVG.');
        }

        $this->ensureDirectory($this->directory());
        $target = $this->directory() . '/' . $slot . '.' . self::LOGO_TYPES[$mime];
        $previous = $this->uploaded($slot);

        if (file_put_contents($target, $bytes) === false) {
            throw new HttpException(500, 'Logo se nepodařilo uložit.');
        }

        // Starý soubor s jinou příponou by jinak vyhrál při hledání.
        if ($previous !== null && $previous !== $target) {
            @unlink($previous);
        }
    }

    /**
     * Ikona aplikace: z nahraného obrázku se vyrobí celá sada. Sada vzniká
     * vedle a stará se nahradí až po úspěchu — nepovedené nahrání nesmí
     * nechat aplikaci bez ikon.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file
     */
    public function storeIcon(?array $file): void
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new HttpException(500, 'Server nemá rozšíření GD — ikonu z obrázku nejde vyrobit.');
        }

        $bytes = $this->readUpload($file);
        $mime = self::detectMime($bytes);

        if ($mime !== 'image/png' && $mime !== 'image/jpeg') {
            throw HttpException::validation('Ikona musí být PNG (ideálně s průhledným pozadím) nebo JPG.');
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw HttpException::validation('Obrázek se nepodařilo přečíst.');
        }

        if (min(imagesx($image), imagesy($image)) < self::ICON_MIN_SIZE) {
            throw HttpException::validation(sprintf('Ikona musí mít aspoň %1$d × %1$d px (má %2$d × %3$d).', self::ICON_MIN_SIZE, imagesx($image), imagesy($image)));
        }

        $work = $this->directory() . '/icons-new';
        $this->removeDirectory($work);
        $this->ensureDirectory($work);

        try {
            (new IconSet($image))->write($work);
        } catch (Throwable $e) {
            $this->removeDirectory($work);

            throw HttpException::validation($e->getMessage());
        }

        $this->removeDirectory($this->iconDirectory());

        if (!@rename($work, $this->iconDirectory())) {
            throw new HttpException(500, 'Sadu ikon se nepodařilo uložit.');
        }
    }

    /** Odebere nahrané logo (vrátí se výchozí) nebo sadu ikon (`icon`). */
    public function remove(string $slot): void
    {
        if ($slot === 'icon') {
            $this->removeDirectory($this->iconDirectory());

            return;
        }

        $uploaded = $this->uploaded($slot);

        if ($uploaded !== null) {
            @unlink($uploaded);
        }
    }

    // -----------------------------------------------------------------
    // Vnitřnosti
    // -----------------------------------------------------------------

    /**
     * Obsah nahraného souboru po základních kontrolách.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file
     */
    private function readUpload(?array $file): string
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw HttpException::validation('Vyberte nejdřív soubor.');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw HttpException::validation('Nahrání souboru selhalo, zkuste to znovu.');
        }

        if ((int) $file['size'] > self::MAX_SIZE) {
            throw HttpException::validation('Soubor je příliš velký (maximum jsou 2 MB).');
        }

        return (string) file_get_contents((string) $file['tmp_name']);
    }

    /** MIME podle obsahu: PNG, JPG, nebo SVG (text začínající XML / `<svg`). */
    public static function detectMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        $head = ltrim(substr($bytes, 0, 2048), "\xEF\xBB\xBF \t\r\n");

        if ((str_starts_with($head, '<?xml') || str_starts_with($head, '<svg') || str_starts_with($head, '<!--'))
            && preg_match('/<svg[\s>]/i', $bytes) === 1) {
            return 'image/svg+xml';
        }

        return null;
    }

    /**
     * SVG se skriptem, obsluhou událostí nebo vloženým HTML. V `<img>` by
     * se nespustily a výdej posílá `CSP: sandbox`, ale logo takové věci
     * nepotřebuje a otevřené samostatně by byly v doméně aplikace.
     */
    public static function svgHasActiveContent(string $svg): bool
    {
        return preg_match('/<script|<foreignObject|\son[a-z]+\s*=|javascript:/i', $svg) === 1;
    }

    public static function mimeOf(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }

    /** Krátký otisk souboru do adresy — změní se s každým nahráním. */
    private static function stamp(string $path): string
    {
        return substr(md5((string) filemtime($path) . ':' . (string) filesize($path)), 0, 8);
    }

    private function directory(): string
    {
        return rtrim($this->storagePath, '/\\') . '/' . self::DIRECTORY;
    }

    private function iconDirectory(): string
    {
        return $this->directory() . '/icons';
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Nepodařilo se připravit složku ' . $directory . '.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
