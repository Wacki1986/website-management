<?php

declare(strict_types=1);

/**
 * Drobnosti nastavení: výchozí den reportu, SMTP pole jen při SMTP,
 * „Poslední test" počítaný z uloženého času (ne uložená věta s „dnes").
 */

use App\Core\Reports\ReportSchedule;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'report: nový web má předvybraný 7. den v měsíci' => function (): void {
        [$kernel] = loggedInKernel('Správce');
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);

        assertSame(7, ReportSchedule::DEFAULT_DAY);
        assertContainsString('<option value="7" selected>', kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/nastaveni')->body());

        Urls::reset();
    },

    'odchozí pošta: SMTP pole v bloku mail-smtp, poslední test podle času, stará věta se neukáže' => function (): void {
        [$kernel] = loggedInKernel('Správce');

        $kernel->settings()->set('mail_last_test', json_encode(['at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'seconds' => 2]));
        $html = kernelRequest($kernel, 'GET', '/nastaveni/email')->body();
        assertContainsString('<div class="mail-smtp form">', $html);
        assertTrue(strpos($html, 'name="host"') > strpos($html, 'mail-smtp'), 'Server je v SMTP bloku');
        assertTrue(strpos($html, 'name="from_address"') > strpos($html, 'name="security"'), 'Odesílatel je až za SMTP blokem');
        assertContainsString('Poslední test', $html);
        assertContainsString('odesláno za 2 s', $html);
        assertFalse(str_contains($html, 'Poslední test dnes'), 'Test byl předevčírem');

        // Starší formát (hotová věta) se neukazuje — „dnes" by lhalo.
        $kernel->settings()->set('mail_last_test', 'Poslední test dnes 23:34 — odesláno za 0 s');
        assertFalse(str_contains(kernelRequest($kernel, 'GET', '/nastaveni/email')->body(), 'dnes 23:34'));

        Urls::reset();
    },
];
