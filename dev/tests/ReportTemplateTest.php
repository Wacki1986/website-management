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
        assertFalse(str_contains($html, 'data-tpl'), 'Značky editoru nesmí do e-mailu klientovi');

        // Editor: vzorový report se všemi sekcemi, upravitelné texty s data-tpl.
        $page = kernelRequest($kernel, 'GET', '/nastaveni/reporty')->body();
        assertContainsString('Šablona klientského reportu', $page);
        assertContainsString('<span data-tpl="subject_ok">Web kavarnadobra.cz: celý ', $page);
        assertContainsString('<span data-tpl="cta_button">Zavolejte nám</span>', $page);
        assertContainsString('<span data-tpl="intro">Ahoj, tady je přehled webu', $page);
        foreach (['heading_updates', 'heading_services', 'heading_content', 'heading_recommendations', 'note_label', 'content_button', 'cta_text', 'signature', 'footer', 'subject'] as $key) {
            assertContainsString('data-tpl="' . $key . '"', $page);
        }
        assertContainsString('name="cta_button" value="Zavolejte nám" data-template-input="cta_button"', $page);
        assertContainsString('data-config="{&quot;fields&quot;', $page);
        assertContainsString('name="reset" value="1"', $page);
        assertContainsString('href="/nastaveni/reporty">Upravit šablonu</a>', kernelRequest($kernel, 'GET', '/reporty')->body());

        kernelRequest($kernel, 'POST', '/nastaveni/reporty', ['_token' => $token, 'reset' => '1', 'cta_button' => 'Ignorováno']);
        assertSame([], (new ReportTemplate($kernel->settings()))->custom());

        Urls::reset();
    },
    'pořadí sekcí: uloží se jen změněné, e-mail i textová varianta ho dodrží, editor má šipky' => function (): void {
        [$kernel, $token] = loggedInKernel('Správce');
        $template = new ReportTemplate($kernel->settings());

        assertSame(ReportTemplate::SECTION_ORDER, $template->order());
        assertSame(0, $template->save(['section_order' => implode(',', ReportTemplate::SECTION_ORDER)]), 'Základní pořadí se neukládá');
        assertFalse($template->hasCustomOrder());

        kernelRequest($kernel, 'POST', '/nastaveni/reporty', ['_token' => $token, 'section_order' => 'cta,recommendations,neznama,note']);
        $order = (new ReportTemplate($kernel->settings()))->order();
        assertSame(['cta', 'recommendations', 'note', 'updates'], array_slice($order, 0, 4), 'Neznámé klíče pryč, chybějící na konec');
        assertSame(count(ReportTemplate::SECTION_ORDER), count($order));

        $summary = ReportTemplate::sampleSummary(strtotime('2026-09-24'));
        $options = $kernel->reportSender()->options($summary, 'Poznámka pro klienta.', array_keys(\App\Core\Reports\ReportRepository::SECTIONS));
        $html = $kernel->reportRenderer()->body($summary, $options);

        assertTrue(strpos($html, 'Napište nám') < strpos($html, 'Na co bychom se rádi domluvili'), 'Výzva ke kontaktu je teď první');
        assertTrue(strpos($html, 'Na co bychom se rádi domluvili') < strpos($html, 'Co jsme pro vás udělali'));
        assertFalse(str_contains($html, 'data-tpl-section'), 'Značky editoru nesmí do e-mailu');
        assertContainsString('font-size:15px;font-weight:700;line-height:1.4;">&#10003;</td>', $html, 'Odrážka je fajfka, ne kolečko');
        assertContainsString('<tr><td style="height:28px;font-size:0;line-height:0;">&nbsp;</td></tr>', $html, 'Mezera před patičkou');

        $text = $kernel->reportRenderer()->text($summary, $options);
        assertTrue(strpos($text, 'Na co bychom se rádi domluvili:') < strpos($text, 'Poznámka od studia:'));

        $page = kernelRequest($kernel, 'GET', '/nastaveni/reporty')->body();
        assertContainsString('<tr data-tpl-section="cta">', $page);
        assertContainsString('name="section_order" value="cta,recommendations,note,', $page);
        assertContainsString('class="template-preview"', $page);
        assertContainsString('data-confirm="template-reset"', $page, 'Změněné pořadí = je co vracet');

        Urls::reset();
    },
];
