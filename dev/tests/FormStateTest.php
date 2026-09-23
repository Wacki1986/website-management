<?php

declare(strict_types=1);

/**
 * Stav přepínačů ve formulářích kreslí jen CSS `:has(:checked)`.
 *
 * Šablona nesmí u rádia/zaškrtávátka vypsat třídu `--active` / `--on`:
 * po kliknutí na jinou položku by zůstala viset a svítily by dvě položky
 * (nebo by vypnutý přepínač dál vypadal zapnutě).
 */

use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'formuláře s přepínači: stav jen v `checked`, žádná visící třída ze serveru' => function (): void {
        [$kernel] = loggedInKernel();
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $kernel->service()->savePlan($siteId, ['is_active' => true, 'kind' => 'medium', 'frequency' => 'quarterly', 'first_date' => '2026-09-01'], '2026-09-23');

        $pages = [
            '/nastaveni/monitoring',
            '/nastaveni/email',
            '/weby/' . $siteId . '/servis',
            '/weby/' . $siteId . '/servis/zapsat',
            '/weby/' . $siteId . '/nastaveni',
        ];

        foreach ($pages as $path) {
            $html = kernelRequest($kernel, 'GET', $path)->body();

            assertContainsString(' checked', $html, $path . ': vybraná položka má být zaškrtnutá');

            foreach (['<label class="segmented__item segmented__item--active', 'choice__item--active', 'pick__item--active', 'toggle--on'] as $stale) {
                assertFalse(str_contains($html, $stale), $path . ': visící třída ' . $stale);
            }
        }

        // Krokovací pole mají jednotku za číslem (návrh: „30 dní", „48 h").
        $alerts = kernelRequest($kernel, 'GET', '/nastaveni/alerty')->body();
        assertContainsString('<span class="stepper__unit" aria-hidden="true">dní</span>', $alerts);
        assertContainsString('aria-label="Stáří zálohy (h)"', $alerts);
        assertContainsString('<span class="stepper__unit" aria-hidden="true">měsíců</span>', kernelRequest($kernel, 'GET', '/nastaveni/monitoring')->body());

        // Plán servisu: zapnutý přepínač i vybraný druh jsou jen `checked`.
        $service = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis')->body();
        assertContainsString('name="is_active" value="1" checked', $service);
        assertContainsString('name="kind" value="medium" checked', $service);

        Urls::reset();
    },

    'odkazy filtrů (segmenty) mění stav filtru a ostatní filtry zachovají' => function (): void {
        [$kernel] = loggedInKernel();

        // Seznam webů s filtrem „problém" a hledáním: odkaz „V pořádku" mění
        // stav, hledání drží; „Vše" stav zruší.
        $sites = kernelRequest($kernel, 'GET', '/weby', [], ['stav' => 'problem', 'q' => 'kav'])->body();
        assertContainsString('href="/weby?q=kav&stav=ok"', $sites);
        assertContainsString('href="/weby?q=kav"', $sites);

        // Dashboard bez webů ukazuje uvítání bez filtrů — potřebuje aspoň jeden web.
        $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $dashboard = kernelRequest($kernel, 'GET', '/', [], ['stav' => 'problem'])->body();
        assertContainsString('href="/?stav=attention"', $dashboard);

        // Reporty: výchozí stav je „naplánované", odkaz „Odeslané" ho musí změnit.
        assertContainsString('stav=sent', kernelRequest($kernel, 'GET', '/reporty')->body());

        Urls::reset();
    },

    'náhled reportu: přepínače sekcí nesou klíč sekce a uloží se jen zapnuté' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $reportId = $kernel->reportSender()->draft($kernel->sites()->find($siteId), 'manual', '2026-09-01', '2026-09-22', 'Září 2026');

        // Koncept vzniklý před sekcí „Obsah webu" — souhrn bez části content.
        $old = $kernel->reports()->find($reportId)['summary'];
        unset($old['content']);
        $kernel->reports()->update($reportId, ['summary' => $old]);

        $response = kernelRequest($kernel, 'POST', '/reporty/' . $reportId . '/sekce', [
            '_token' => $token,
            'sections' => ['uptime_chart', 'services'],
        ]);
        assertSame(302, $response->status());
        assertTrue(isset($kernel->reports()->find($reportId)['summary']['content']), 'Uložení konceptu přepočítá souhrn z aktuálních dat');

        $preview = kernelRequest($kernel, 'GET', '/reporty/' . $reportId . '/nahled')->body();
        assertContainsString('name="sections[]" value="uptime_chart" checked', $preview);
        assertContainsString('name="sections[]" value="services" checked', $preview);
        assertContainsString('name="sections[]" value="updates" aria-label', $preview, 'Vypnutá sekce nemá být zaškrtnutá');
        assertFalse(str_contains($preview, 'data-autosubmit'), 'Sekce se ukládají tlačítkem, ne po každém přepnutí');
        assertContainsString('data-unsaved-button', $preview);

        // Náhled má i vypnuté sekce, jen skryté — přepínač je ukáže bez načtení.
        assertContainsString('<tr data-report-section="cta" hidden>', $preview);
        assertContainsString('<tr data-report-section="updates" hidden>', $preview);
        assertContainsString('<tr data-report-section="uptime_chart">', $preview);

        // E-mail pro klienta: jen zapnuté sekce, bez značek náhledu.
        $html = (string) $kernel->reports()->find($reportId)['html'];
        assertFalse(str_contains($html, 'data-report-section'));
        assertFalse(str_contains($html, 'Napište nám'), 'Vypnutá výzva ke kontaktu nesmí být v e-mailu');

        Urls::reset();
    },
];
