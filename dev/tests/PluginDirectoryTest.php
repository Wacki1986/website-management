<?php

declare(strict_types=1);

/**
 * Pluginy v adresáři wordpress.org: čtení odpovědi API, opuštěné
 * a stažené pluginy na záložce Pluginy, ve stavu webu, v alertu,
 * v poznámce servisu a v doporučení reportu. Stahování je podvržené.
 */

use App\Core\Kernel;
use App\Core\Monitor\PluginDirectory;
use App\Core\Sites\SiteStatus;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

const PD_NOW = '2026-09-24 12:00:00';

/** Odpovědi API podle slugu — jako skutečné api.wordpress.org. */
function pdFetcher(): callable
{
    $answers = [
        'woocommerce' => '{"name":"WooCommerce","slug":"woocommerce","version":"9.3.1","last_updated":"2026-09-10 2:15pm GMT","tested":"6.9","active_installs":7000000}',
        'google-pagespeed-insights' => '{"name":"Insights from Google PageSpeed","slug":"google-pagespeed-insights","version":"4.0.8","last_updated":"2024-07-07 7:09pm GMT","tested":"6.5.12","active_installs":10000}',
        'contact-form-7-datepicker' => '{"error":"closed","name":"Contact Form 7 Datepicker","slug":"contact-form-7-datepicker","closed":true,"closed_date":"2020-04-01","reason":"security-issue","reason_text":"Security Issue"}',
        'rank-math-pro' => '{"error":"Plugin not found."}',
    ];

    return static function (string $url) use ($answers): ?string {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $answers[$query['slug'] ?? ''] ?? null;
    };
}

/** @return array{0: Kernel, 1: string, 2: int, 3: PluginDirectory} */
function pdKernel(): array
{
    [$kernel, $token] = loggedInKernel('Správce');
    $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
    $kernel->db()->execute("INSERT INTO site_snapshots (site_id, fetched_at, payload, php_version, wp_version, plugins_total, plugins_active, plugins_updates) VALUES (:id, :now, '{}', '8.4.1', '6.9', 4, 4, 0)", ['id' => $siteId, 'now' => PD_NOW]);

    foreach ([
        ['woocommerce/woocommerce.php', 'WooCommerce'],
        ['google-pagespeed-insights/google-pagespeed-insights.php', 'Insights from Google PageSpeed'],
        ['contact-form-7-datepicker/contact-form-7-datepicker.php', 'Contact Form 7 Datepicker'],
        ['rank-math-pro/rank-math-pro.php', 'Rank Math SEO PRO'],
    ] as [$file, $name]) {
        $kernel->db()->execute('INSERT INTO site_plugins (site_id, file, name, version, is_active, first_seen_at, last_seen_at) VALUES (:id, :file, :name, :v, 1, :first, :last)',
            ['id' => $siteId, 'file' => $file, 'name' => $name, 'v' => '1.0', 'first' => PD_NOW, 'last' => PD_NOW]);
    }

    return [$kernel, $token, $siteId, new PluginDirectory($kernel->db(), $kernel->monitorSettings(), pdFetcher())];
}

return [
    'API: vydaný, stažený kvůli bezpečnosti, mimo adresář; nesmyslná odpověď se zkusí příště' => function (): void {
        $found = PluginDirectory::parse('{"name":"Insights &#8211; PageSpeed","slug":"x","version":"4.0.8","last_updated":"2024-07-07 7:09pm GMT","tested":"6.5.12","active_installs":10000}');
        assertSame('found', $found['status']);
        assertSame('2024-07-07', $found['last_updated']);
        assertSame('Insights – PageSpeed', $found['name']);

        $closed = PluginDirectory::parse('{"error":"closed","name":"Datepicker","closed":true,"closed_date":"2020-04-01","reason":"security-issue","reason_text":"Security Issue"}');
        assertSame(['closed', '2020-04-01', 'Security Issue'], [$closed['status'], $closed['closed_date'], $closed['closed_reason']]);
        assertSame('missing', PluginDirectory::parse('{"error":"Plugin not found."}')['status']);
        assertSame(null, PluginDirectory::parse('<html>502</html>'));

        assertSame('woocommerce', PluginDirectory::slug('woocommerce/woocommerce.php'));
        assertSame('hello', PluginDirectory::slug('hello.php'));
    },

    'ověření: uloží, přepočítá počty webu, záložka Pluginy ukáže stav a sloupec Vydáno' => function (): void {
        [$kernel, , $siteId, $directory] = pdKernel();
        $now = strtotime(PD_NOW);

        $due = $directory->dueSlugs($now);
        sort($due);
        assertSame(['contact-form-7-datepicker', 'google-pagespeed-insights', 'rank-math-pro', 'woocommerce'], $due);
        assertSame(4, $directory->refresh($due, $now));
        assertSame([], $directory->dueSlugs($now), 'Týden se znovu neověřuje');
        assertSame(4, count($directory->dueSlugs($now + 8 * 86400)));

        $snapshot = $kernel->db()->selectOne('SELECT plugins_abandoned, plugins_closed, plugins_insecure FROM site_snapshots WHERE site_id = :id', ['id' => $siteId]);
        assertSame([1, 1, 1], array_map('intval', array_values($snapshot)));

        $issues = $directory->issues($siteId, $now);
        assertSame(['Contact Form 7 Datepicker', 'Insights from Google PageSpeed'], array_column($issues, 'name'));
        assertTrue($issues[0]['security']);
        assertContainsString('kvůli bezpečnostní chybě', PluginDirectory::issueText($issues[0]));
        assertContainsString('poslední aktualizace 7. 7. 2024', PluginDirectory::issueText($issues[1]));

        assertSame('Nebezpečný plugin', SiteStatus::of($kernel->sites()->all()[0], $now)['label']);

        $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
        assertContainsString('<div>Vydáno</div>', $html);
        assertContainsString('Aktivní · opuštěný', $html);
        assertContainsString('Aktivní · stažen — bezpečnost', $html);
        // Pilulky Vydáno: stažený i opuštěný červeně, čerstvý zeleně, mimo adresář šedě.
        assertContainsString('pill--button pill--error" title="Plugin byl stažen z adresáře wordpress.org (důvod: Security Issue)', $html);
        assertContainsString('>staženo 1. 4. 2020</a>', $html);
        assertContainsString("pill--error\" title=\"Poslední vydání 7. 7. 2024, testováno do WordPressu 6.5.12 — plugin se přes 24 měsíců nevyvíjí. Placená verze", $html);
        assertContainsString('<span class="pill pill--sm pill--ok" title="Poslední vydání 10. 9. 2026', $html);
        assertContainsString('pill--muted" title="Plugin není na wordpress.org (placený nebo vlastní) — stáří se nehodnotí.">mimo adresář</span>', $html);
        assertContainsString('2 opuštěné', $html);

        Urls::reset();
    },

    'práh a stav: kratší práh = víc opuštěných; bez staženého je web jen v Pozornosti' => function (): void {
        [$kernel, , $siteId, $directory] = pdKernel();
        $now = strtotime(PD_NOW);
        $directory->refresh(['woocommerce', 'google-pagespeed-insights'], $now);

        assertSame('Opuštěný plugin', SiteStatus::of($kernel->sites()->all()[0], $now)['label']);
        assertSame('attention', SiteStatus::of($kernel->sites()->all()[0], $now)['level']);

        // 30 měsíců: plugin z července 2024 ještě opuštěný není.
        $kernel->monitorSettings()->save(['rule_abandoned_months' => 30]);
        $directory->recount($siteId, $now);
        assertSame([], $directory->issues($siteId, $now));
        assertSame('V pořádku', SiteStatus::of(['status' => 'ok', 'api_status' => 'ok'] + $kernel->sites()->all()[0], $now)['label']);
    },

    'alert, servis a report: opuštěné pluginy všude stejnou větou' => function (): void {
        [$kernel, , $siteId, $directory] = pdKernel();
        $directory->refresh($directory->dueSlugs(strtotime(PD_NOW)), strtotime(PD_NOW));

        // Alert po načtení dat z webu (engine z Kernelu má skutečný adresář, data jsou už v tabulce).
        $site = $kernel->sites()->find($siteId);
        $kernel->alertEngine()->afterSnapshot($site, $kernel->snapshots()->snapshot($siteId), PD_NOW);
        $alert = $kernel->alerts()->openOf($siteId, 'plugins_outdated');
        assertTrue($alert !== null, 'Alert se otevřel');
        assertSame('error', $alert['severity'], 'Stažený plugin = vážný alert');
        assertContainsString('Insights from Google PageSpeed', (string) $alert['body']);

        // Vypnuté pravidlo alert zavře.
        $kernel->monitorSettings()->save(['rule_abandoned_on' => '0']);
        $kernel->alertEngine()->afterSnapshot($site, $kernel->snapshots()->snapshot($siteId), PD_NOW);
        assertSame(null, $kernel->alerts()->openOf($siteId, 'plugins_outdated'));

        $form = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/zapsat')->body();
        assertContainsString('Plugin Contact Form 7 Datepicker byl stažen z adresáře WordPressu kvůli bezpečnostní chybě', $form);

        $summary = $kernel->reportBuilder()->build($kernel->sites()->findWithSnapshot($siteId), '2026-09-01', '2026-09-30', 'Září 2026', '2026-09-24');
        $titles = array_column($summary['recommendations'], 'title');
        assertTrue(in_array('Doplněk s bezpečnostní chybou', $titles, true));

        $alerts = kernelRequest($kernel, 'GET', '/nastaveni/alerty')->body();
        assertContainsString('Opuštěné pluginy', $alerts);

        Urls::reset();
    },
    'placená verze: podle zdroje aktualizací se nehodnotí, ruční označení platí pro všechny weby a jde zrušit' => function (): void {
        [$kernel, $token, $siteId, $directory] = pdKernel();
        $now = strtotime(PD_NOW);
        $directory->refresh($directory->dueSlugs($now), $now);
        $datepicker = 'contact-form-7-datepicker/contact-form-7-datepicker.php';

        // Plugin na webu hlásí vlastní updater autora (jako WPML) — nehodnotí se.
        $kernel->db()->execute("UPDATE site_plugins SET source = 'external' WHERE file = :file", ['file' => $datepicker]);
        $directory->recount($siteId, $now);
        assertSame(['Insights from Google PageSpeed'], array_column($directory->issues($siteId, $now), 'name'));
        $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
        assertContainsString('Plugin se aktualizuje přes vlastní systém autora', $html);
        assertFalse(str_contains($html, 'stažen — bezpečnost'));

        // Ruční označení opuštěného pluginu přes okno.
        assertContainsString('data-confirm="plugin-external" data-confirm-value="google-pagespeed-insights/google-pagespeed-insights.php"', $html);
        assertContainsString('<dialog class="modal" id="plugin-external"', $html);
        kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/pluginy/mimo-adresar', ['_token' => $token, 'plugin' => 'google-pagespeed-insights/google-pagespeed-insights.php']);
        assertSame([], $directory->issues($siteId, $now));
        assertSame(0, (int) $kernel->db()->selectOne('SELECT plugins_abandoned FROM site_snapshots WHERE site_id = :id', ['id' => $siteId])['plugins_abandoned']);
        $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
        assertContainsString('formaction="/weby/' . $siteId . '/pluginy/z-adresare"', $html);
        assertContainsString('>placená verze</button>', $html);

        // Zrušení vrátí hodnocení.
        kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/pluginy/z-adresare', ['_token' => $token, 'plugin' => 'google-pagespeed-insights/google-pagespeed-insights.php']);
        assertSame(['Insights from Google PageSpeed'], array_column($directory->issues($siteId, $now), 'name'));

        Urls::reset();
    },
    'aktualizace, která nemá odkud přijít: vypnutý plugin mimo adresář a bez balíčku se nenabízí, koš zůstává' => function (): void {
        [$kernel, , $siteId, $directory] = pdKernel();
        $now = strtotime(PD_NOW);
        $directory->refresh($directory->dueSlugs($now), $now);
        $rank = 'rank-math-pro/rank-math-pro.php';
        $woo = 'woocommerce/woocommerce.php';

        // Rank Math PRO (mimo adresář) vypnutý s ohlášenou verzí; WooCommerce aktivní, ale bez balíčku.
        $kernel->db()->execute("UPDATE site_plugins SET is_active = 0, has_update = 1, new_version = '3.1' WHERE file = :file", ['file' => $rank]);
        $kernel->db()->execute("UPDATE site_plugins SET has_update = 1, new_version = '9.9', update_package = 0 WHERE file = :file", ['file' => $woo]);
        $kernel->db()->execute("UPDATE site_snapshots SET plugin_version = '1.5.4' WHERE site_id = :id", ['id' => $siteId]);
        $kernel->sites()->setApiKey($siteId, 'mg_live_TESTKEY0000000000000000000000');
        $kernel->db()->execute("INSERT INTO site_plugins (site_id, file, name, version, is_active, has_update, new_version, first_seen_at, last_seen_at) VALUES (:id, 'elementor/elementor.php', 'Elementor', '3.0', 1, 1, '3.1', :a, :b)", ['id' => $siteId, 'a' => PD_NOW, 'b' => PD_NOW]);
        assertSame(1, $kernel->snapshots()->recountUpdates($siteId), 'Počítá se jen Elementor');

        $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/pluginy')->body();
        assertFalse(str_contains($html, 'name="plugins[]" value="' . $rank . '"'), 'Vypnutý plugin mimo adresář se nenabízí');
        assertFalse(str_contains($html, 'name="plugins[]" value="' . $woo . '"'), 'Bez balíčku se nenabízí');
        assertContainsString('name="plugins[]" value="elementor/elementor.php"', $html);
        assertContainsString('Plugin je mimo adresář wordpress.org a vypnutý', $html);
        assertContainsString('nemá balíček ke stažení', $html);
        assertContainsString('>3.1 · ručně</div>', $html);
        assertContainsString('data-confirm="plugin-delete" data-confirm-value="' . $rank . '"', $html, 'Vypnutý plugin jde smazat');

        Urls::reset();
    },
];

