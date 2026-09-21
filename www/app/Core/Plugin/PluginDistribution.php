<?php

declare(strict_types=1);

namespace App\Core\Plugin;

/**
 * Distribuce pluginu MEDIAGRAFIK Monitor na weby klientů.
 *
 * WordPress na každém webu se ptá `plugin-info.json` (verze, changelog,
 * adresa ZIPu) a při aktualizaci si stáhne ZIP. Obojí leží ve
 * `storage/plugin/` — tam je dává `dev/tools/build-plugin.ps1` — a ven
 * jdou přes veřejné routy `/plugin/mediagrafik-monitor/…`, protože storage
 * není web root. Adresa ZIPu v JSONu se doplňuje z `app_url`, takže
 * soubor v úložišti neví, na jaké doméně běží.
 */
final class PluginDistribution
{
    public const SLUG = 'mediagrafik-monitor';

    public function __construct(
        private readonly string $storageDir,
        private readonly string $appUrl,
    ) {
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

        $zip = self::SLUG . '-' . $decoded['version'] . '.zip';
        $decoded['download_url'] = rtrim($this->appUrl, '/') . '/plugin/' . self::SLUG . '/' . $zip;
        $decoded['slug'] = self::SLUG;

        return $decoded;
    }

    /** Cesta k ZIPu, nebo null když neexistuje nebo jméno nevypadá bezpečně. */
    public function zipPath(string $file): ?string
    {
        if (preg_match('/^' . self::SLUG . '-\d+\.\d+\.\d+\.zip$/', $file) !== 1) {
            return null;
        }

        $path = $this->storageDir . '/' . $file;

        return is_file($path) ? $path : null;
    }

    public function version(): ?string
    {
        return $this->info()['version'] ?? null;
    }
}
