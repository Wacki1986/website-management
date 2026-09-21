<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;

/**
 * Vstup pro hostingový cron: `GET /system/monitor-cron?token=…` každých
 * 5 minut. Ověřuje se token z Nastavení → Monitoring (uložený šifrovaně),
 * ne session — volá ho wget, ne prohlížeč. Bez tokenu je vstup zavřený.
 */
final class MonitorCronController extends Controller
{
    public const TOKEN_KEY = 'monitor_cron_token';

    public function run(): Response
    {
        $expected = $this->kernel->settings()->secret(self::TOKEN_KEY);
        $given = $this->request()->string('token');

        if ($expected === null || $expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw HttpException::forbidden('Neplatný token cronu. Vygenerujte ho v Nastavení → Monitoring.');
        }

        $summary = $this->kernel->monitor()->run();

        return Response::json(['status' => $summary['status'] === 'error' ? 'error' : 'success'] + $summary);
    }

    /** Řádek pro cPanel — každých 5 minut wget na adresu cronu. */
    public static function cronLine(string $appUrl, string $token): string
    {
        return '*/5 * * * * wget -qO- "' . rtrim($appUrl, '/') . '/system/monitor-cron?token=' . $token . '" >/dev/null 2>&1';
    }
}
