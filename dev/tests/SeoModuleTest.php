<?php

declare(strict_types=1);

/**
 * Moduly a modul SEO: globální a webový vypínač, `?modules=seo` pro plugin
 * jen u webů se zapnutým modulem, uložení dat do snímku a denní historie,
 * záložka SEO, sloupec v seznamu webů, alerty a sekce v reportu.
 */

use App\Core\Events\EventLog;
use App\Core\Kernel;
use App\Core\Modules\Modules;
use App\Core\Modules\SeoRepository;
use App\Core\Monitor\PluginClient;
use App\Core\Monitor\SnapshotImporter;
use App\Core\Security\Secrets;
use App\Core\Settings\Settings;
use App\Core\Sites\SiteRepository;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/FakeInstance.php';
require_once __DIR__ . '/fixtures/logged-in-kernel.php';

const SEO_KEY = 'mg_live_TESTKEY0000000000000000000000';

/** Data modulu SEO tak, jak je posílá plugin 1.6.0. */
function seoPayload(int $average = 72, bool $indexable = true): array
{
    return [
        'plugin' => 'rank-math',
        'plugin_version' => '1.0.230',
        'indexable' => $indexable,
        'sitemap' => ['enabled' => true, 'url' => 'https://kavarnadobra.cz/sitemap_index.xml', 'source' => 'rank-math'],
        'scores' => ['average' => $average, 'good' => 18, 'ok' => 6, 'bad' => 3, 'unscored' => 2],
        'missing' => ['keyword' => 5, 'description' => 7],
        'noindex' => 1,
        'worst' => [],
    ];
}

/** Kernel s webem a zapnutým modulem SEO (globálně i u webu). @return array{0: Kernel, 1: string, 2: int} */
function seoKernel(string $url = 'https://kavarnadobra.cz', array $env = []): array
{
    [$kernel, $token] = loggedInKernel('Správce', $env);
    $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => $url]);
    $kernel->modules()->save(Modules::SEO, true, false);
    $kernel->modules()->setForSite($siteId, Modules::SEO, true);

    return [$kernel, $token, $siteId];
}

/** Snímek webu s daty modulu SEO k danému času. */
function seoImport(Kernel $kernel, int $siteId, array $seo, string $now): void
{
    $kernel->snapshots()->import($kernel->sites()->find($siteId), [
        'ok' => true, 'code' => 'ok', 'status' => 200, 'error' => null, 'plugin_version' => '1.6.0',
        'data' => ['wordpress' => ['version' => '6.8.2'], 'plugins' => ['items' => []], 'seo' => $seo],
    ], $now);
}

return [
    'moduly: platí jen zapnuto globálně i u webu; nové weby a „u všech webů"' => function (): void {
        $db = freshTestDb();
        $sites = new SiteRepository($db, new Secrets(str_repeat('ab', 32)));
        $modules = new Modules($db, new Settings($db, new Secrets(str_repeat('ab', 32))));
        $a = $sites->create(['name' => 'A', 'url' => 'https://a.cz']);
        $b = $sites->create(['name' => 'B', 'url' => 'https://b.cz']);

        $modules->setForSite($a, Modules::SEO, true);
        assertFalse($modules->forSite($a, Modules::SEO), 'Globálně vypnutý modul neplatí nikde');
        assertSame([], $modules->forSites([$a, $b]));
        assertSame([], $modules->siteChoices($a), 'Karta Hlídání modul bez globálního zapnutí nenabízí');

        $modules->save(Modules::SEO, true, true);
        assertTrue($modules->forSite($a, Modules::SEO));
        assertFalse($modules->forSite($b, Modules::SEO));
        assertSame([$a => ['seo']], $modules->forSites([$a, $b]));
        assertTrue($modules->siteChoices($a)['seo']['on']);
        assertFalse($modules->siteChoices($b)['seo']['on']);

        $c = $sites->create(['name' => 'C', 'url' => 'https://c.cz']);
        $modules->onNewSite($c);
        assertTrue($modules->forSite($c, Modules::SEO), 'Volba „zapnout u nových webů"');

        assertSame(3, $modules->enableForAll(Modules::SEO));
        assertSame(3, $modules->siteCount(Modules::SEO));
        assertFalse($modules->forSite(999, 'neznamy'));
    },

    'klient pluginu: moduly v adrese souhrnu, hezká i záložní adresa' => function (): void {
        assertSame('https://a.cz/wp-json/mediagrafik-monitor/v1/summary?modules=seo', PluginClient::endpointUrl('https://a.cz', 'summary?modules=seo'));
        assertSame('https://a.cz/?rest_route=/mediagrafik-monitor/v1/summary&modules=seo', PluginClient::endpointUrl('https://a.cz', 'summary?modules=seo', true));
    },

    'import: data.seo do sloupců a denní historie; souhrn bez nich sloupce nepřepíše' => function (): void {
        $db = freshTestDb();
        $sites = new SiteRepository($db, new Secrets(str_repeat('ab', 32)));
        $seo = new SeoRepository($db);
        $importer = new SnapshotImporter($db, $sites, new EventLog($db), null, $seo);
        $id = $sites->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $result = static fn (array $data): array => ['ok' => true, 'code' => 'ok', 'status' => 200, 'data' => $data, 'error' => null, 'plugin_version' => '1.6.0'];

        $importer->import($sites->find($id), $result(['plugins' => ['items' => []], 'seo' => seoPayload(68)]), '2026-09-01 08:00:00');
        $importer->import($sites->find($id), $result(['plugins' => ['items' => []], 'seo' => seoPayload(74)]), '2026-09-25 08:00:00');
        $importer->import($sites->find($id), $result(['plugins' => ['items' => []], 'seo' => seoPayload(75)]), '2026-09-25 14:00:00');

        $snapshot = $importer->snapshot($id);
        assertSame('rank-math', $snapshot['seo_plugin']);
        assertSame(75, (int) $snapshot['seo_average']);
        assertSame(3, (int) $snapshot['seo_bad']);
        assertSame(1, (int) $snapshot['seo_indexable']);
        assertSame('2026-09-25 14:00:00', $snapshot['seo_checked_at']);

        // Jeden řádek na den, poslední kontrola dne vyhrává.
        assertSame([68, 75], array_column($seo->history($id, '2026-09-01', '2026-09-30'), 'average'));
        assertSame(68, $seo->averageOn($id, '2026-09-10'));
        assertSame(null, $seo->averageOn($id, '2026-08-31'));

        // Souhrn bez modulu (vypnutý, starý plugin) poslední stav nemaže.
        $importer->import($sites->find($id), $result(['plugins' => ['items' => []]]), '2026-09-26 08:00:00');
        assertSame(75, (int) $importer->snapshot($id)['seo_average']);
        assertSame('2026-09-25 14:00:00', $importer->snapshot($id)['seo_checked_at']);
    },

    'kontrola webu: ?modules=seo jen se zapnutým modulem; záložka, sloupec a odkazy jen http' => function (): void {
        if (!FakeInstance::start(8261, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_KEY' => SEO_KEY, 'FAKE_WP_RELEASE' => '1.7.0'], 'fake-wp-site.php')) {
            skip('falešný web nenastartoval: ' . FakeInstance::lastError());
        }

        try {
            [$kernel, , $siteId] = seoKernel('http://127.0.0.1:8261', ['allow_insecure_sites' => true]);
            $kernel->sites()->setApiKey($siteId, SEO_KEY);

            // Modul u webu vypnutý: plugin data nepošle, záložka ani sloupec nejsou.
            $kernel->modules()->setForSite($siteId, Modules::SEO, false);
            $kernel->monitor()->checkOne($kernel->sites()->find($siteId));
            assertSame(null, $kernel->snapshots()->snapshot($siteId)['seo_checked_at']);
            assertSame(404, kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/seo')->status());
            assertFalse(str_contains(kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/prehled')->body(), '/seo"'));

            // Zapnutý: data přijdou s kontrolou.
            $kernel->modules()->setForSite($siteId, Modules::SEO, true);
            $kernel->monitor()->checkOne($kernel->sites()->find($siteId));
            $snapshot = $kernel->snapshots()->snapshot($siteId);
            assertSame(72, (int) $snapshot['seo_average']);
            assertSame(72, $kernel->seo()->averageOn($siteId, date('Y-m-d')));

            $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/seo')->body();
            assertContainsString('72 / 100', $html);
            assertContainsString('Kontakt', $html);
            assertContainsString('31 ze 100 · slabé', $html);
            assertContainsString('Upravit ve wp-admin', $html);
            assertFalse(str_contains($html, 'javascript:'), 'Odkaz z pluginu bez http(s) se nevypíše');
            assertContainsString('3 slab.', $html, 'Odznak na záložce');

            // Plugin 1.7.0: klíčové slovo, co chybí, pokrytí metadat, 404 a přesměrování.
            assertContainsString('podzimní menu', $html);
            assertContainsString('>nenastaveno<', $html);
            assertContainsString("title=\"Text má 42\u{00A0}slov, doporučeno aspoň 300.\">krátký text</span>", $html);
            assertContainsString("2\u{00A0}obrázky v textu bez alt textu.", $html);
            assertContainsString('24 z 29 · 83 %', $html);
            assertContainsString('612 z 940 · 65 %', $html);
            assertContainsString('/menu-leto-2025', $html);
            assertContainsString("38\u{00A0}zásahů", $html);
            assertContainsString('Aktivní přesměrování', $html);
            assertContainsString("zapnutá · 29\u{00A0}stránek", $html);
            assertFalse(str_contains($html, 'Víc údajů s novějším pluginem'));

            $list = kernelRequest($kernel, 'GET', '/weby')->body();
            assertContainsString('table--sites-seo', $list);
            assertContainsString('<div>SEO</div>', $list);
            assertContainsString('Průměrné SEO skóre 72 ze 100 (průměrné)', $list);

            // Globálně vypnutý modul: sloupec zmizí.
            $kernel->modules()->save(Modules::SEO, false, false);
            assertFalse(str_contains(kernelRequest($kernel, 'GET', '/weby')->body(), 'table--sites-seo'));
        } finally {
            FakeInstance::stop();
            Urls::reset();
        }
    },

    'záložka SEO: data z pluginu 1.6.0 bez podrobností, „Zobrazit všechny" u víc než šesti stránek' => function (): void {
        [$kernel, , $siteId] = seoKernel();
        $worst = [['id' => 1, 'title' => 'Kontakt', 'type' => 'page', 'type_label' => 'Stránka', 'score' => 31, 'url' => 'https://kavarnadobra.cz/kontakt/', 'edit_url' => 'https://kavarnadobra.cz/wp-admin/post.php?post=1&action=edit']];
        seoImport($kernel, $siteId, ['worst' => $worst] + seoPayload(), date('Y-m-d H:i:s'));

        $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/seo')->body();
        assertContainsString('Víc údajů s novějším pluginem', $html);
        assertContainsString('Kontakt', $html);
        assertFalse(str_contains($html, 'class="coverage"'), 'Bez dat z 1.7.0 se karta neukáže');

        $weak = [];

        for ($i = 1; $i <= 8; $i++) {
            $weak[] = ['id' => $i, 'title' => 'Stránka ' . $i, 'type' => 'page', 'type_label' => 'Stránka', 'score' => 20 + $i, 'keyword' => '', 'issues' => ['keyword'],
                'words' => 100, 'images_without_alt' => 0, 'url' => 'https://kavarnadobra.cz/' . $i . '/', 'edit_url' => 'https://kavarnadobra.cz/wp-admin/post.php?post=' . $i . '&action=edit'];
        }

        seoImport($kernel, $siteId, ['weak' => $weak, 'coverage' => ['images' => ['total' => 0, 'with_alt' => 0]], 'not_found' => ['source' => '', 'days' => 7, 'hits' => 0, 'urls' => 0, 'top' => []], 'redirects' => ['source' => '', 'active' => 0]] + seoPayload(), date('Y-m-d H:i:s'));
        $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/seo')->body();
        assertContainsString('Zobrazit všech 8', $html);
        assertContainsString('Stránka 6<', $html);
        assertFalse(str_contains($html, 'Stránka 7<'), 'Na záložce jen šest nejslabších');
        assertContainsString('Web nefunkční odkazy nezaznamenává', $html);

        $all = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/seo/stranky')->body();
        assertContainsString('Stránka 8<', $all);
        assertContainsString('Zpět na SEO', $all);

        $kernel->modules()->setForSite($siteId, Modules::SEO, false);
        assertSame(404, kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/seo/stranky')->status());

        Urls::reset();
    },

    'záložka SEO: starý plugin dostane výzvu k aktualizaci' => function (): void {
        [$kernel, , $siteId] = seoKernel();
        $kernel->snapshots()->import($kernel->sites()->find($siteId), [
            'ok' => true, 'code' => 'ok', 'status' => 200, 'error' => null, 'plugin_version' => '1.5.5',
            'data' => ['plugins' => ['items' => []]],
        ]);

        $html = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/seo')->body();
        assertContainsString('MEDIAGRAFIK Monitor 1.6.0 a novější (web má 1.5.5)', $html);

        Urls::reset();
    },

    'alerty: skrytý web, slabé skóre podle prahu; vypnutý modul je zavře' => function (): void {
        [$kernel, , $siteId] = seoKernel();
        $engine = $kernel->alertEngine();
        $check = static function () use ($kernel, $siteId, $engine): void {
            $engine->afterSnapshot($kernel->sites()->find($siteId), $kernel->snapshots()->snapshot($siteId), '2026-09-25 10:00:00');
        };

        seoImport($kernel, $siteId, seoPayload(42, false), '2026-09-25 10:00:00');
        $check();
        $hidden = $kernel->alerts()->openOf($siteId, 'seo_hidden');
        assertTrue($hidden !== null, 'Skrytý web = alert');
        assertSame('error', $hidden['severity']);
        $low = $kernel->alerts()->openOf($siteId, 'seo_low');
        assertTrue($low !== null, 'Průměr 42 pod výchozím prahem 50');
        assertContainsString('42 ze 100', (string) $low['title']);

        // Práh 40: 42 už není slabé; web zase viditelný.
        $kernel->monitorSettings()->save(['rule_seo_low_score' => 40]);
        seoImport($kernel, $siteId, seoPayload(42, true), '2026-09-25 11:00:00');
        $check();
        assertSame(null, $kernel->alerts()->openOf($siteId, 'seo_hidden'));
        assertSame(null, $kernel->alerts()->openOf($siteId, 'seo_low'));

        // Vypnutí modulu u webu otevřený alert zavře.
        seoImport($kernel, $siteId, seoPayload(42, false), '2026-09-25 12:00:00');
        $check();
        assertTrue($kernel->alerts()->openOf($siteId, 'seo_hidden') !== null);
        $kernel->modules()->setForSite($siteId, Modules::SEO, false);
        $check();
        assertSame(null, $kernel->alerts()->openOf($siteId, 'seo_hidden'));

        Urls::reset();
    },

    'report: sekce SEO s posunem od začátku období, skrytý web v doporučeních' => function (): void {
        [$kernel, , $siteId] = seoKernel();
        seoImport($kernel, $siteId, seoPayload(70), '2026-08-31 08:00:00');
        seoImport($kernel, $siteId, seoPayload(74, false), '2026-09-29 08:00:00');

        $summary = $kernel->reportBuilder()->build($kernel->sites()->findWithSnapshot($siteId), '2026-09-01', '2026-09-30', 'Září 2026', '2026-10-01');
        $rows = array_column($summary['seo']['rows'], 'value', 'label');
        assertSame('74 ze 100 · průměrné', $rows['Průměrné hodnocení stránek']);
        // get_count() dává mezi číslo a slovo nezlomitelnou mezeru.
        assertSame("o 4\u{00A0}body lépe", $rows['Oproti začátku období']);
        assertSame('9', $rows['Stránky k vylepšení']);
        assertSame('web je skrytý', $rows['Viditelnost pro vyhledávače']);
        assertTrue(in_array('Web je skrytý před vyhledávači', array_column($summary['recommendations'], 'title'), true));

        $options = ['sections' => ['seo'], 'texts' => []];
        assertContainsString('Jak je web připravený pro vyhledávače', $kernel->reportRenderer()->body($summary, $options));
        assertContainsString("- Oproti začátku období: o 4\u{00A0}body lépe", $kernel->reportRenderer()->text($summary, $options));
        assertFalse(str_contains($kernel->reportRenderer()->body($summary, ['sections' => ['updates']]), 'připravený pro vyhledávače'), 'Vypnutá sekce se nevykreslí');

        // Bez modulu sekce není.
        $kernel->modules()->setForSite($siteId, Modules::SEO, false);
        assertSame(null, $kernel->reportBuilder()->build($kernel->sites()->findWithSnapshot($siteId), '2026-09-01', '2026-09-30', 'Září 2026', '2026-10-01')['seo']);

        Urls::reset();
    },

    'nastavení: Moduly, pravidla SEO v Alertech jen se zapnutým modulem, přepínač u webu' => function (): void {
        [$kernel, $token] = loggedInKernel('Správce');
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);

        assertContainsString('SEO z pluginu na webu', kernelRequest($kernel, 'GET', '/nastaveni/moduly')->body());
        assertFalse(str_contains(kernelRequest($kernel, 'GET', '/nastaveni/alerty')->body(), 'Slabé SEO'));
        assertFalse(str_contains(kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/nastaveni')->body(), 'module_seo'));

        kernelRequest($kernel, 'POST', '/nastaveni/moduly', ['_token' => $token, 'module' => 'seo', 'on' => '1']);
        assertTrue($kernel->modules()->isOn(Modules::SEO));
        assertFalse($kernel->modules()->isOnForNewSites(Modules::SEO));

        $alerts = kernelRequest($kernel, 'GET', '/nastaveni/alerty')->body();
        assertContainsString('Slabé SEO', $alerts);
        assertContainsString('Web skrytý před vyhledávači', $alerts);

        // Uložení Alertů s pravidlem bez prahu.
        kernelRequest($kernel, 'POST', '/nastaveni/alerty', ['_token' => $token, 'rule_seo_low_max' => '60', 'rule_seo_low_on' => '1']);
        assertSame(60, $kernel->monitorSettings()->int('rule_seo_low_score'));
        assertFalse($kernel->monitorSettings()->bool('rule_seo_hidden_on'), 'Neodeslaný přepínač = vypnuto');

        assertContainsString('module_seo', kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/nastaveni')->body());
        kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/nastaveni/hlidani', ['_token' => $token, 'watch_uptime' => '1', 'module_seo' => '1']);
        assertTrue($kernel->modules()->forSite($siteId, Modules::SEO));

        kernelRequest($kernel, 'POST', '/nastaveni/moduly/seo/vsechny-weby', ['_token' => $token]);
        assertSame(1, $kernel->modules()->siteCount(Modules::SEO));
        assertSame(404, kernelRequest($kernel, 'POST', '/nastaveni/moduly/neznamy/vsechny-weby', ['_token' => $token])->status());

        Urls::reset();
    },
];
