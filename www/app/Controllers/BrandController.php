<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;

/**
 * Výdej log a ikon instance: `/znacka/{soubor}` (Nastavení → Základní nastavení).
 *
 * Veřejně — logo potřebuje přihlašovací stránka, ikony manifest a service
 * worker a logo studia poštovní klient příjemce reportu. Nic z toho není
 * tajné. Co nahrané není, vydá se výchozí soubor z `assets/`, takže
 * adresy v manifestu a v `sw.js` fungují v každé instanci.
 */
final class BrandController extends Controller
{
    public function file(string $file): Response
    {
        $resolved = $this->kernel->brand()->assets()->resolve($file);

        if ($resolved === null) {
            throw HttpException::notFound('Soubor neexistuje.');
        }

        $path = $resolved['path'];
        $headers = [
            'Content-Type' => $resolved['mime'],
            'Content-Length' => (string) filesize($path),
            // Adresa s otiskem (`?v=`) se po nahrání změní, smí se držet rok.
            // Bez otisku (manifest, sw.js) jen den — jinak by nová ikona
            // dorazila za rok.
            'Cache-Control' => isset($this->request()->query['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // SVG otevřené samostatně je dokument v doméně aplikace — sandbox
        // mu vezme skripty i formuláře (v `<img>` se nespouští tak jako tak).
        if ($resolved['mime'] === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return Response::stream(
            static function () use ($path): void {
                readfile($path);
            },
            $headers,
        );
    }
}
