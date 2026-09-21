<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\Response;

/**
 * Sledovací obrázek v klientském reportu: `GET /r/{token}.gif`.
 *
 * Vždy vrací tentýž průhledný 1×1 GIF (i pro neznámý token), aby se z
 * odpovědi nedalo poznat, jestli token existuje. Bez cache — každé
 * otevření e-mailu se má počítat.
 */
final class TrackingController extends Controller
{
    private const GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function pixel(string $file): Response
    {
        if (preg_match('/^([a-f0-9]{32})\.gif$/', $file, $m) === 1) {
            try {
                $this->kernel->reports()->markOpened($m[1]);
            } catch (\Throwable) {
                // Obrázek musí odejít vždy — chyba zápisu klienta nezajímá.
            }
        }

        return (new Response(self::GIF, 200))
            ->withHeader('Content-Type', 'image/gif')
            ->withHeader('Content-Length', (string) strlen(self::GIF))
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }
}
