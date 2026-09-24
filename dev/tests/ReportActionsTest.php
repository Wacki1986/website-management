<?php

declare(strict_types=1);

/**
 * Akce v řádku reportu: ikony vpravo (kontrola / náhled, odeslané HTML,
 * koš) ve frontě i u webu, smazání i odeslaného reportu s návratem tam,
 * odkud se mazalo.
 */

use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'ikony v řádku a smazání: koncept i odeslaný, návrat na stránku webu' => function (): void {
        [$kernel, $token] = loggedInKernel('Správce');
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $site = $kernel->sites()->findWithSnapshot($siteId);
        $draft = $kernel->reportSender()->draft($site, 'manual', '2026-08-01', '2026-08-31', 'Srpen 2026', today: '2026-09-01');
        $sent = $kernel->reportSender()->draft($site, 'manual', '2026-07-01', '2026-07-31', 'Červenec 2026', today: '2026-08-01');
        $kernel->reports()->update($sent, ['status' => 'sent', 'sent_at' => '2026-08-01 07:00:00']);

        $page = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/reporty')->body();
        assertContainsString('title="Zkontrolovat a odeslat"', $page);
        assertContainsString('title="Náhled"', $page);
        assertContainsString('title="Otevřít odeslané HTML"', $page);
        assertContainsString('data-confirm="report-delete" data-confirm-action="/reporty/' . $sent . '/smazat"', $page);
        assertContainsString('E-mail, který klient dostal, zůstane u něj.', $page);
        assertContainsString('<dialog class="modal modal--danger" id="report-delete"', $page);
        assertContainsString('name="back" value="weby/' . $siteId . '/reporty"', $page);
        assertFalse(str_contains($page, '>Zkontrolovat</a>'), 'Textový odkaz nahradila ikona');

        $queue = kernelRequest($kernel, 'GET', '/reporty', [], ['stav' => 'all'])->body();
        assertContainsString('data-confirm-action="/reporty/' . $draft . '/smazat"', $queue);

        // Odeslaný jde smazat taky; vrátí se na záložku webu.
        $response = kernelRequest($kernel, 'POST', '/reporty/' . $sent . '/smazat', ['_token' => $token, 'back' => 'weby/' . $siteId . '/reporty']);
        assertSame(302, $response->status());
        assertContainsString('/weby/' . $siteId . '/reporty', (string) ($response->headers()['Location'] ?? ''));
        assertSame(null, $kernel->reports()->find($sent));

        // Podvržená adresa návratu → fronta reportů.
        $response = kernelRequest($kernel, 'POST', '/reporty/' . $draft . '/smazat', ['_token' => $token, 'back' => 'https://example.com']);
        assertContainsString('/reporty', (string) ($response->headers()['Location'] ?? ''));
        assertFalse(str_contains((string) ($response->headers()['Location'] ?? ''), 'example.com'));
        assertSame(null, $kernel->reports()->find($draft));

        Urls::reset();
    },
];
