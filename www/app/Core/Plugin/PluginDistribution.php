<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Brand\Brand;
use Throwable;
use ZipArchive;

/**
 * Distribuce našeho pluginu na weby klientů.
 *
 * WordPress na každém webu se ptá `plugin-info.json` (verze, changelog,
 * adresa ZIPu) a při aktualizaci si stáhne ZIP. Obojí leží ve
 * `storage/plugin/` — tam je dává `dev/tools/build-plugin.ps1` — a ven
 * jdou přes veřejné routy `/plugin/{slug}/…`, protože storage není web
 * root. Adresa ZIPu v JSONu se doplňuje z `app_url`, takže soubor
 * v úložišti neví, na jaké doméně běží.
 *
 * ## Razítko instance
 *
 * Build dělá jeden neutrální balíček (`monitor-{verze}.zip`). Každá
 * instance si ho při výdeji „orazítkuje“ (`Brand`): složka a hlavní soubor
 * dostanou slug instance, hlavička pluginu její název, autora a web,
 * a `brand.php` adresu správy. Orazítkovaný ZIP se drží ve
 * `storage/plugin/cache/` — nový vznikne, až se změní značka nebo verze.
 *
 * Balíčky z doby před razítkem (`mediagrafik-monitor-{verze}.zip`, bez
 * `brand.php`) mají jméno i texty v kódu — vydají se beze změny, ale jen
 * instanci s tímtéž (výchozím) slugem.
 */
final class PluginDistribution
{
    /** Jméno neutrálního balíčku z buildu (`monitor-1.10.0.zip`, složka `monitor/`). */
    public const BASE_NAME = 'monitor';

    public function __construct(
        private readonly string $storageDir,
        private readonly string $appUrl,
        private readonly ?Brand $brand = null,
    ) {
    }

    public function slug(): string
    {
        return $this->brand?->pluginSlug() ?? Brand::DEFAULT_PLUGIN_SLUG;
    }

    /** Hlavní soubor pluginu na webu (`slug/slug.php`). */
    public function pluginFile(): string
    {
        return $this->slug() . '/' . $this->slug() . '.php';
    }

    /** @return array<string, mixed>|null */
    public function info(): ?array
    {
        $file = $this->storageDir . '/plugin-info.json';

        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded) || !isset($decoded['version'])) {
            return null;
        }

        // Verze, kterou tahle instance nemá z čeho vydat (v úložišti je jen
        // starý balíček s cizí složkou), se nenabízí — WordPress by dostal
        // nabídku aktualizace a správa odkaz ke stažení, který končí 404.
        if (!$this->hasPackage((string) $decoded['version'])) {
            return null;
        }

        $stamp = $this->stampValues();
        $decoded['name'] = $stamp['name'];
        $decoded['author'] = $stamp['author'];
        $decoded['author_profile'] = $stamp['author_uri'];
        $decoded['homepage'] = $stamp['author_uri'];
        $decoded['sections'] = ['description' => $stamp['description']] + (array) ($decoded['sections'] ?? []);
        $decoded['download_url'] = rtrim($this->appUrl, '/') . '/plugin/' . $this->slug() . '/' . $this->zipName((string) $decoded['version']);
        $decoded['slug'] = $this->slug();

        return $decoded;
    }

    public function version(): ?string
    {
        return $this->info()['version'] ?? null;
    }

    /** Jméno ZIPu, pod kterým ho weby stahují (`{slug}-{verze}.zip`). */
    public function zipName(string $version): string
    {
        return $this->slug() . '-' . $version . '.zip';
    }

    /**
     * Cesta k ZIPu pro výdej (orazítkovanému), nebo null, když neexistuje
     * nebo jméno nevypadá bezpečně.
     */
    public function zipPath(string $file): ?string
    {
        if (preg_match('/^' . preg_quote($this->slug(), '/') . '-(\d+\.\d+\.\d+)\.zip$/', $file, $matches) !== 1) {
            return null;
        }

        $version = $matches[1];
        $base = $this->basePath($version);

        if (is_file($base)) {
            return $this->stamped($base, $version);
        }

        return $this->legacyPath($version);
    }

    /**
     * Má instance z čeho vydat danou verzi? Neutrální balíček z buildu,
     * nebo starý balíček z doby před razítkem — ten jen se stejnou složkou.
     */
    public function hasPackage(string $version): bool
    {
        return is_file($this->basePath($version)) || $this->legacyPath($version) !== null;
    }

    private function basePath(string $version): string
    {
        return $this->storageDir . '/' . self::BASE_NAME . '-' . $version . '.zip';
    }

    /** Balíček z doby před razítkem — jen pro instanci se stejným slugem. */
    private function legacyPath(string $version): ?string
    {
        $legacy = $this->storageDir . '/' . Brand::DEFAULT_PLUGIN_SLUG . '-' . $version . '.zip';

        return $this->slug() === Brand::DEFAULT_PLUGIN_SLUG && is_file($legacy) ? $legacy : null;
    }

    // -----------------------------------------------------------------
    // Razítko
    // -----------------------------------------------------------------

    /**
     * Hodnoty do hlavičky a `brand.php`. Jen jeden řádek bez `*\/`
     * (konec komentáře by hlavičku rozbil).
     *
     * @return array{name: string, slug: string, author: string, author_uri: string, hub_url: string, description: string}
     */
    public function stampValues(): array
    {
        $studio = $this->brand?->name() ?? '';
        $line = static fn (string $value): string => trim((string) preg_replace('/\s+/u', ' ', str_replace('*/', '', $value)));

        return [
            'name' => $line($this->brand?->pluginName() ?? Brand::APP_NAME . ' – Monitor'),
            'slug' => $this->slug(),
            'author' => $line($studio !== '' ? $studio : Brand::APP_NAME),
            'author_uri' => $line($this->brand?->web() ?? ''),
            'hub_url' => rtrim($this->appUrl, '/'),
            'description' => $line('Napojení webu na Správu webů' . ($studio !== '' ? ' ' . $studio : '')
                . ' — správa si přes REST API a API klíč načítá verze, pluginy, obsah a stav zabezpečení. Plugin sám nic neodesílá.'),
        ];
    }

    /** Orazítkovaný ZIP z mezipaměti; vyrobí se, když ještě není. */
    private function stamped(string $base, string $version): ?string
    {
        $values = $this->stampValues();
        $key = substr(md5($version . ':' . filemtime($base) . ':' . filesize($base) . ':' . json_encode($values)), 0, 12);
        $cacheDir = $this->storageDir . '/cache';
        $target = $cacheDir . '/' . $this->slug() . '-' . $version . '-' . $key . '.zip';

        if (is_file($target)) {
            return $target;
        }

        if (!class_exists(ZipArchive::class)) {
            return null;
        }

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }

        try {
            $ok = $this->stamp($base, $target . '.tmp', $values);
        } catch (Throwable) {
            $ok = false;
        }

        if (!$ok || !@rename($target . '.tmp', $target)) {
            @unlink($target . '.tmp');

            return null;
        }

        // Starší razítka téže verze (jiná značka) už nikdo nestáhne.
        foreach (glob($cacheDir . '/' . $this->slug() . '-' . $version . '-*.zip') ?: [] as $old) {
            if ($old !== $target) {
                @unlink($old);
            }
        }

        return $target;
    }

    /**
     * Přebalí neutrální balíček: složka `{slug}/`, hlavní soubor
     * `{slug}.php` s novou hlavičkou, `brand.php` s hodnotami instance.
     *
     * @param array{name: string, slug: string, author: string, author_uri: string, hub_url: string, description: string} $values
     */
    private function stamp(string $base, string $target, array $values): bool
    {
        $source = new ZipArchive();

        if ($source->open($base, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $top = strstr((string) $source->getNameIndex(0), '/', true);

        if ($top === false || $top === '' || $source->locateName($top . '/brand.php') === false) {
            $source->close();

            return false;
        }

        $main = $this->mainFile($source, $top);
        $output = new ZipArchive();

        if ($main === null || $output->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();

            return false;
        }

        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = (string) $source->getNameIndex($i);
            $relative = substr($name, strlen($top) + 1);

            if ($relative === '' || str_ends_with($name, '/')) {
                continue;
            }

            $content = (string) $source->getFromIndex($i);

            if ($relative === $main) {
                $relative = $values['slug'] . '.php';
                $content = self::header($content, $values);
            } elseif ($relative === 'brand.php') {
                $content = self::brandFile($values);
            }

            $output->addFromString($values['slug'] . '/' . $relative, $content);
        }

        $source->close();

        return $output->close();
    }

    /** Hlavní soubor = PHP v kořeni složky s hlavičkou `Plugin Name:`. */
    private function mainFile(ZipArchive $zip, string $top): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $relative = substr((string) $zip->getNameIndex($i), strlen($top) + 1);

            if (str_ends_with($relative, '.php') && !str_contains($relative, '/')
                && preg_match('/^\s*\*\s*Plugin Name:/m', (string) $zip->getFromIndex($i)) === 1) {
                return $relative;
            }
        }

        return null;
    }

    /** @param array{name: string, slug: string, author: string, author_uri: string, description: string} $values */
    public static function header(string $content, array $values): string
    {
        $fields = [
            'Plugin Name' => $values['name'],
            'Plugin URI' => $values['author_uri'],
            'Description' => $values['description'],
            'Author' => $values['author'],
            'Author URI' => $values['author_uri'],
            'Text Domain' => $values['slug'],
        ];

        foreach ($fields as $field => $value) {
            $content = (string) preg_replace_callback(
                '/^(\s*\*\s*' . preg_quote($field, '/') . ':)[^\r\n]*/m',
                static fn (array $m): string => $m[1] . ($value !== '' ? ' ' . $value : ''),
                $content,
                1,
            );
        }

        return $content;
    }

    /** @param array{name: string, slug: string, author: string, author_uri: string, hub_url: string} $values */
    public static function brandFile(array $values): string
    {
        $define = static fn (string $name, string $value): string => 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";

        return "<?php\n\n"
            . "// Značka pluginu — vygenerovala ji Správa webů při stažení ZIPu.\n"
            . "if (!defined('ABSPATH')) {\n    exit;\n}\n\n"
            . $define('MG_MONITOR_NAME', $values['name'])
            . $define('MG_MONITOR_SLUG', $values['slug'])
            . $define('MG_MONITOR_AUTHOR', $values['author'])
            . $define('MG_MONITOR_AUTHOR_URI', $values['author_uri'])
            . $define('MG_MONITOR_HUB_URL', $values['hub_url']);
    }
}
