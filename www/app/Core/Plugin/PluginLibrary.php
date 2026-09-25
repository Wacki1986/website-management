<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Db\Connection;
use App\Core\Http\HttpException;
use ZipArchive;

/**
 * Knihovna pluginů mimo wordpress.org (placené, vlastní) — od každého jen
 * nejnovější verze.
 *
 * Distribuce jde stejnou cestou jako u MEDIAGRAFIK Monitoru: plugin na
 * webu (1.4.0+) se správy zeptá na seznam (`manifest()`) a WordPressu nabídne
 * aktualizaci; ZIP si stáhne přes odkaz podepsaný pro konkrétní web
 * (`packageToken()`) — placené pluginy tak nejsou volně ke stažení.
 *
 * ZIP leží ve `storage/plugin-library/<slug>.zip`; nahrání novější verze
 * starou přepíše, na serveru nezůstávají staré verze.
 */
final class PluginLibrary
{
    public const DIRECTORY = 'plugin-library';

    /** Větší ZIP by se na sdíleném hostingu stejně nenahrál (upload_max_filesize). */
    public const MAX_SIZE = 64 * 1024 * 1024;

    public function __construct(
        private readonly Connection $db,
        private readonly string $storagePath,
        #[\SensitiveParameter] private readonly string $appKey,
    ) {
    }

    /** @return array<int, array<string, mixed>> všechny pluginy knihovny podle názvu */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM plugin_library ORDER BY name');
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM plugin_library WHERE slug = :slug', ['slug' => $slug]);
    }

    /** @return array<string, string> soubor pluginu => verze v knihovně */
    public function versions(): array
    {
        $versions = [];

        foreach ($this->all() as $plugin) {
            $versions[(string) $plugin['file']] = (string) $plugin['version'];
        }

        return $versions;
    }

    /**
     * Nahraný ZIP z formuláře.
     *
     * @param array{name: string, tmp_name: string, error: int, size: int}|null $file
     * @return array{entry: array<string, mixed>, previous: ?string} uložený plugin a verze, kterou nahradil
     */
    public function upload(?array $file, string $who): array
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw HttpException::validation('Vyberte ZIP s pluginem.');
        }

        if (in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw HttpException::validation('ZIP je větší, než hosting dovolí nahrát (upload_max_filesize v PHP).');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw HttpException::validation('Nahrání souboru selhalo, zkuste to znovu.');
        }

        return $this->import((string) $file['tmp_name'], $who);
    }

    /**
     * Uloží ZIP do knihovny (volá `upload()`, testy přímo s cestou).
     * Starší verze v knihovně se přepíše; nižší verzi nahrát nejde —
     * weby by se „aktualizovaly" dolů.
     *
     * @return array{entry: array<string, mixed>, previous: ?string}
     */
    public function import(string $path, string $who): array
    {
        if (filesize($path) > self::MAX_SIZE) {
            throw HttpException::validation('ZIP je příliš velký (nejvýš 64 MB).');
        }

        $info = self::inspect($path);
        $current = $this->find($info['slug']);

        if ($current !== null && version_compare($info['version'], (string) $current['version'], '<')) {
            throw HttpException::validation(sprintf('V knihovně už je novější verze %s (%s) — nahráváte %s.', $current['version'], $current['name'], $info['version']));
        }

        $sameFile = $this->db->selectOne('SELECT slug FROM plugin_library WHERE file = :file', ['file' => $info['file']]);

        if ($sameFile !== null && (string) $sameFile['slug'] !== $info['slug']) {
            throw HttpException::validation('Plugin se stejným souborem už v knihovně je pod jiným názvem složky.');
        }

        $directory = $this->storagePath . '/' . self::DIRECTORY;

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new HttpException(500, 'Nepodařilo se připravit úložiště knihovny pluginů.');
        }

        // Nejdřív vedle, pak přejmenovat: rozpracovaný zápis nesmí nahradit
        // funkční ZIP, který si zrovna stahuje některý web.
        $target = $this->zipPath($info['slug']);
        $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (!@copy($path, $temporary) || !@rename($temporary, $target)) {
            @unlink($temporary);

            throw new HttpException(500, 'ZIP se nepodařilo uložit do knihovny.');
        }

        @chmod($target, 0644);

        $row = [
            'file' => $info['file'],
            'name' => mb_substr($info['name'], 0, 150),
            'version' => mb_substr($info['version'], 0, 30),
            'author' => mb_substr($info['author'], 0, 150),
            'requires_wp' => mb_substr($info['requires_wp'], 0, 20),
            'requires_php' => mb_substr($info['requires_php'], 0, 20),
            'size' => (int) filesize($target),
            'uploaded_by' => mb_substr($who, 0, 100),
            'uploaded_at' => date('Y-m-d H:i:s'),
        ];

        $current !== null
            ? $this->db->update('plugin_library', $row, ['slug' => $info['slug']])
            : $this->db->insert('plugin_library', $row + ['slug' => $info['slug']]);

        return ['entry' => (array) $this->find($info['slug']), 'previous' => $current !== null ? (string) $current['version'] : null];
    }

    public function remove(string $slug): void
    {
        @unlink($this->zipPath($slug));
        $this->db->delete('plugin_library', ['slug' => $slug]);
    }

    public function zipPath(string $slug): string
    {
        return $this->storagePath . '/' . self::DIRECTORY . '/' . basename($slug) . '.zip';
    }

    /**
     * Podpis odkazu na ZIP pro konkrétní web — web ho dostane v manifestu,
     * cizí web ani prohlížeč bez něj ZIP nestáhne.
     */
    public function packageToken(string $slug, int $siteId): string
    {
        return hash_hmac('sha256', 'plugin-library|' . $slug . '|' . $siteId, $this->appKey);
    }

    public function validToken(string $slug, int $siteId, string $token): bool
    {
        return $token !== '' && hash_equals($this->packageToken($slug, $siteId), $token);
    }

    /**
     * Seznam pro plugin na webu: co je v knihovně a odkud to stáhnout.
     *
     * @return array<string, array{name: string, slug: string, version: string, requires: string, requires_php: string, package: string}> podle souboru pluginu
     */
    public function manifest(int $siteId, string $appUrl): array
    {
        $manifest = [];

        foreach ($this->all() as $plugin) {
            $slug = (string) $plugin['slug'];
            $manifest[(string) $plugin['file']] = [
                'name' => (string) $plugin['name'],
                'slug' => $slug,
                'version' => (string) $plugin['version'],
                'requires' => (string) $plugin['requires_wp'],
                'requires_php' => (string) $plugin['requires_php'],
                'package' => rtrim($appUrl, '/') . '/plugin/mediagrafik-monitor/knihovna/' . rawurlencode($slug) . '.zip?web=' . $siteId . '&podpis=' . $this->packageToken($slug, $siteId),
            ];
        }

        return $manifest;
    }

    /**
     * Na kterých webech je který plugin z knihovny a v jaké verzi.
     *
     * @return array<string, array<int, array{id: int, name: string, version: string}>> podle souboru pluginu
     */
    public function usage(): array
    {
        $usage = [];

        foreach ($this->db->select(
            'SELECT p.file, p.version, s.id, s.name FROM site_plugins p
             JOIN plugin_library l ON l.file = p.file
             JOIN sites s ON s.id = p.site_id AND s.removed_at IS NULL
             ORDER BY s.name',
        ) as $row) {
            $usage[(string) $row['file']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'version' => (string) $row['version']];
        }

        return $usage;
    }

    /**
     * Na kterých webech je náš MEDIAGRAFIK Monitor a v jaké verzi — pro jeho
     * kartu v knihovně. Pozná se podle souboru jako v `SiteActions::isMonitor()`:
     * složka na webu se může jmenovat jinak (ruční nahrání ZIPu s verzí).
     *
     * @return array<int, array{id: int, name: string, version: string, file: string}>
     */
    public function monitorUsage(): array
    {
        $sites = [];

        foreach ($this->db->select(
            'SELECT p.file, p.version, s.id, s.name FROM site_plugins p
             JOIN sites s ON s.id = p.site_id AND s.removed_at IS NULL
             WHERE p.file LIKE :pattern
             ORDER BY s.name',
            ['pattern' => '%/' . PluginDistribution::SLUG . '.php'],
        ) as $row) {
            $sites[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'version' => (string) $row['version'], 'file' => (string) $row['file']];
        }

        return $sites;
    }

    /**
     * Rozbor ZIPu: WordPress čeká jednu složku v kořeni a v ní PHP soubor
     * s hlavičkou `Plugin Name:` — stejná pravidla jako při nahrání pluginu
     * ve wp-admin.
     *
     * @return array{slug: string, file: string, name: string, version: string, author: string, requires_wp: string, requires_php: string}
     */
    public static function inspect(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new HttpException(500, 'Na serveru chybí rozšíření PHP zip.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw HttpException::validation('Soubor není platný ZIP.');
        }

        $folders = [];
        $candidates = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $parts = explode('/', $name);

            if ($parts[0] !== '' && $parts[0] !== '__MACOSX') {
                $folders[$parts[0]] = true;
            }

            // Hlavní soubor pluginu je PHP přímo ve složce pluginu.
            if (count($parts) === 2 && str_ends_with(strtolower($parts[1]), '.php')) {
                $candidates[] = $i;
            }
        }

        if (count($folders) !== 1) {
            $zip->close();

            throw HttpException::validation('ZIP musí obsahovat jednu složku pluginu (tak jak ho vydává autor nebo jak jde nahrát ve wp-admin).');
        }

        foreach ($candidates as $index) {
            $headers = self::headers((string) $zip->getFromIndex($index, 8192));

            if ($headers['name'] !== '') {
                $file = str_replace('\\', '/', (string) $zip->getNameIndex($index));
                $zip->close();

                if ($headers['version'] === '') {
                    throw HttpException::validation('Plugin v hlavičce nemá verzi (Version:) — bez ní nejde poznat, co je novější.');
                }

                return ['slug' => (string) array_key_first($folders), 'file' => $file] + $headers;
            }
        }

        $zip->close();

        throw HttpException::validation('V ZIPu není soubor s hlavičkou WordPress pluginu (Plugin Name:).');
    }

    /**
     * Hlavička pluginu — stejný zápis, jaký čte WordPress (`get_file_data`).
     *
     * @return array{name: string, version: string, author: string, requires_wp: string, requires_php: string}
     */
    private static function headers(string $source): array
    {
        $read = static function (string $header) use ($source): string {
            return preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi', $source, $match) === 1
                ? trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]) ?? '')
                : '';
        };

        return [
            'name' => $read('Plugin Name'),
            'version' => $read('Version'),
            'author' => strip_tags($read('Author')),
            'requires_wp' => $read('Requires at least'),
            'requires_php' => $read('Requires PHP'),
        ];
    }
}
