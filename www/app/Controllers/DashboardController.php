<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\Response;
use App\Core\Monitor\MonitorRun;

/**
 * Dashboard (návrh `dashboard*.html`): hero karta, metriky a tabulka
 * „Vyžaduje řešení" se segmenty Vše / Problém / Pozornost, filtrem
 * klienta a hledáním. Stavy: prázdný (bez webů), načítání (weby bez
 * kontroly), chyba monitoru (cron neběží nebo selhal).
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        $request = $this->request();
        $level = in_array($request->string('stav'), ['problem', 'attention'], true) ? $request->string('stav') : '';
        $clientId = $request->int('klient');
        $q = $request->string('q');

        $data = $this->kernel->dashboard()->build(
            ['level' => $level, 'client' => $clientId, 'q' => $q],
            MonitorRun::lastRun($this->kernel->settings()),
        );

        return $this->view('dashboard/index', $data + [
            'title' => 'Dashboard',
            'level' => $level,
            'clientId' => $clientId,
            'q' => $q,
            'clients' => $this->kernel->clients()->options(),
            'monitorUrl' => get_url('nastaveni/monitoring'),
        ]);
    }
}
