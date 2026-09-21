<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;

/**
 * Výdej pluginu MEDIAGRAFIK Monitor WordPressu na webech klientů:
 * `plugin-info.json` (verze, changelog, adresa ZIPu) a samotný ZIP.
 * Obojí připravuje `dev/tools/build-plugin.ps1` do `storage/plugin/`.
 */
final class PluginDistributionController extends Controller
{
    public function info(): Response
    {
        $info = $this->kernel->pluginDistribution()->info();

        if ($info === null) {
            throw HttpException::notFound('Plugin zatím není k distribuci připravený.');
        }

        return Response::json($info)->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function download(string $file): Response
    {
        $path = $this->kernel->pluginDistribution()->zipPath($file);

        if ($path === null) {
            throw HttpException::notFound('Soubor pluginu neexistuje.');
        }

        return Response::stream(
            static function () use ($path): void {
                readfile($path);
            },
            [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) filesize($path),
                'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }
}
