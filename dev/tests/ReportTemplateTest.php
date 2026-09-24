<?php

declare(strict_types=1);

/**
 * Šablona klientského reportu (Nastavení → Šablona reportu): přepsané
 * texty se dostanou do e-mailu, předmětu i textové varianty, prázdné
 * pole znamená výchozí znění, „Vrátit výchozí texty“ vše smaže.
 */

use App\Core\Reports\ReportTemplate;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'uložení: jen přepsané texty, prázdné a shodné s výchozím = výchozí; značky se dosadí' => function (): void {
        [$kernel] = loggedInKernel('Správce');
        $template = new ReportTemplate($kernel->settings());

        $changed = $template->save([
            'intro' => "Ahoj, web {web} za {obdobi} běžel.\r\nDíky, {studio}",
            'cta_button' => '  Zavolejte nám  ',
            'heading_updates' => ReportTemplate::FIELDS['heading_updates']['default'],
            'footer' => '',
        ]);

        assertSame(2, $changed);
        assertSame(['intro' => "Ahoj, web {web} za {obdobi} běžel.\nDíky, {studio}", 'cta_button' => 'Zavolejte nám'], $template->custom());
        assertSame(ReportTemplate::FIELDS['footer']['default'], $template->texts()['footer']);

        $summary = ['site' => ['host' => 'kavarnadobra.cz'], 'period' => ['label' => 'Září 2026', 'phrase' => 'celý září'], 'allGood' => true];
        assertSame('Váš web celý září: vše v pořádku', ReportTemplate::subject($summary, $template->texts(), 'MEDIAGRAFIK'));
        assertSame('Zpráva o vašem webu — září 2026', ReportTemplate::subject(['allGood' => false] + $summary, $template->texts(), 'MEDIAGRAFIK'));
    },

    'e-mail: texty ze šablony v HTML i textové variantě, stránka nastavení a návrat k výchozím' => function (): void {
        [$kernel, $token] = loggedInKernel('Správce');
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);

        kernelRequest($kernel, 'POST', '/nastaveni/reporty', [
            '_token' => $token,
            'intro' => 'Ahoj, tady je přehled webu {web} za {obdobi}.',
            'cta_button' => 'Zavolejte nám',
            'subject_ok' => 'Web {web}: {kdy} bez potíží',
            'footer' => 'Odhlásit se můžete odpovědí na tento e-mail.',
        ]);

        $summary = $kernel->reportBuilder()->build($kernel->sites()->findWithSnapshot($siteId), '2026-09-01', '2026-09-30', 'Září 2026', '2026-10-01');
        $options = $kernel->reportSender()->options($summary, '', ['cta']);
        $html = $kernel->reportRenderer()->body($summary, $options);

        assertContainsString('Ahoj, tady je přehled webu <b style="font-weight:600;color:#14162b;">kavarnadobra.cz</b> za září 2026.', $html);
        assertContainsString('>Zavolejte nám</a>', $html);
        assertContainsString('Odhlásit se můžete odpovědí na tento e-mail.', $html);
        assertFalse(str_contains($html, 'Napište nám'));
        assertContainsString('Ahoj, tady je přehled webu kavarnadobra.cz za září 2026.', $kernel->reportRenderer()->text($summary, $options));

        // Rozpracovaný report: náhled na stránce nastavení z jeho dat.
        $kernel->reportSender()->draft($kernel->sites()->findWithSnapshot($siteId), 'manual', '2026-09-01', '2026-09-30', 'Září 2026', today: '2026-10-01');

        $page = kernelRequest($kernel, 'GET', '/nastaveni/reporty')->body();
        assertContainsString('Náhled na reportu Kavárna · Září 2026', $page);
        assertContainsString('Předmět: <b>Web kavarnadobra.cz', $page);
        assertContainsString('email-paper__sheet', $page);
        assertContainsString('Texty klientského reportu', $page);
        assertContainsString('placeholder="Napište nám"', $page);
        assertContainsString('value="Zavolejte nám"', $page);
        assertContainsString('name="reset" value="1"', $page);
        assertContainsString('href="/nastaveni/reporty">Upravit šablonu</a>', kernelRequest($kernel, 'GET', '/reporty')->body());

        kernelRequest($kernel, 'POST', '/nastaveni/reporty', ['_token' => $token, 'reset' => '1', 'cta_button' => 'Ignorováno']);
        assertSame([], (new ReportTemplate($kernel->settings()))->custom());

        Urls::reset();
    },
];
