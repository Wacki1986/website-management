<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Modules\Modules;
use App\Core\Modules\SeoScore;

/**
 * Detail webu — záložka SEO (modul SEO). Návrh z Claude Design bez
 * předávky: vlevo tabulka slabých stránek a pod ní pokrytí metadat
 * a nefunkční odkazy, vpravo karta skóre a viditelnost. Data o Search
 * Console z návrhu tu nejsou (modul Search Console zatím není).
 *
 * Data posílá plugin MEDIAGRAFIK Monitor pod klíčem `seo` — od 1.6.0
 * skóre a viditelnost, od 1.7.0 i pokrytí metadat, co stránkám chybí
 * a nefunkční odkazy. Bez zapnutého modulu záložka neexistuje.
 */
final class SeoController extends Controller
{
    use SiteHeaderTrait;

    /** Od této verze plugin posílá pokrytí metadat, co chybí a 404. */
    private const DETAILS_SINCE = '1.7.0';

    /** Kolik nejslabších stránek ukázat na záložce (zbytek na „Zobrazit všechny"). */
    private const TAB_PAGES = 6;

    /** Popisky štítků „Co chybí" a jejich bublina. */
    private const ISSUES = [
        'keyword' => 'klíčové slovo',
        'description' => 'meta popis',
        'short_text' => 'krátký text',
        'image_alt' => 'alt obrázků',
    ];

    public function show(string $id): Response
    {
        $site = $this->seoSiteOr404((int) $id);
        $snapshot = $this->kernel->snapshots()->snapshot((int) $id);
        $seo = is_array($snapshot['data']['seo'] ?? null) ? $snapshot['data']['seo'] : null;
        $view = [
            'empty' => $seo === null ? $this->emptyState($snapshot) : null,
            'checkedAt' => $snapshot['seo_checked_at'] ?? null,
            'score' => null, 'visibility' => [], 'coverage' => [], 'notFound' => null, 'pages' => [], 'pagesTotal' => 0,
            'allPagesUrl' => get_url('weby/' . (int) $id . '/seo/stranky'), 'noPlugin' => false, 'upgradeNote' => '',
        ];

        if ($seo !== null) {
            $pages = $this->pages($seo);
            $view = [
                'score' => $this->score((int) $id, $seo),
                'visibility' => $this->visibility($seo),
                'coverage' => $this->coverage($seo),
                'notFound' => $this->notFound($seo),
                'pages' => array_slice($pages, 0, self::TAB_PAGES),
                'pagesTotal' => count($pages),
                'noPlugin' => self::plugin($seo) === '',
                'upgradeNote' => !isset($seo['coverage']) ? 'Pokrytí metadat, co stránkám chybí a nefunkční odkazy posílá MEDIAGRAFIK Monitor ' . self::DETAILS_SINCE . ' — aktualizujte ho na záložce Pluginy.' : '',
            ] + $view;
        }

        return $this->view('sites/seo', $this->header($site, 'seo') + $view);
    }

    /** Všechny slabě hodnocené stránky (plugin jich posílá nejvýš 200). */
    public function allPages(string $id): Response
    {
        $site = $this->seoSiteOr404((int) $id);
        $snapshot = $this->kernel->snapshots()->snapshot((int) $id);
        $seo = is_array($snapshot['data']['seo'] ?? null) ? $snapshot['data']['seo'] : [];
        $pages = $this->pages($seo);
        $weak = (int) ($seo['scores']['ok'] ?? 0) + (int) ($seo['scores']['bad'] ?? 0);

        return $this->view('sites/seo-pages', $this->header($site, 'seo') + [
            'pages' => $pages,
            'checkedAt' => $snapshot['seo_checked_at'] ?? null,
            'backUrl' => get_url('weby/' . (int) $id . '/seo'),
            'note' => $weak > count($pages) ? 'Prvních ' . count($pages) . ' z ' . $weak . ' stránek, od nejslabší.' : get_count(count($pages), 'stránka', 'stránky', 'stránek') . ' s průměrným nebo slabým hodnocením, od nejslabší.',
        ]);
    }

    /**
     * Proč zatím nejsou data: plugin je starší než modul, nebo s daty
     * modulu ještě nebyl dotázán.
     *
     * @param array<string, mixed>|null $snapshot
     * @return array{title: string, text: string}
     */
    private function emptyState(?array $snapshot): array
    {
        $since = Modules::REGISTRY[Modules::SEO]['plugin_since'];
        $version = (string) ($snapshot['plugin_version'] ?? '');

        if ($snapshot !== null && $version !== '' && version_compare($version, $since, '<')) {
            return [
                'title' => 'Potřeba novější plugin',
                'text' => 'SEO data posílá MEDIAGRAFIK Monitor ' . $since . ' a novější (web má ' . $version . ') — aktualizujte ho na záložce Pluginy nebo ve wp-admin.',
            ];
        }

        return [
            'title' => 'Zatím žádná data',
            'text' => 'SEO data přijdou s další kontrolou přes plugin MEDIAGRAFIK Monitor. Hned je načte „Zkontrolovat teď".',
        ];
    }

    /** @param array<string, mixed> $seo */
    private static function plugin(array $seo): string
    {
        return isset(SeoScore::THRESHOLDS[(string) ($seo['plugin'] ?? '')]) ? (string) $seo['plugin'] : '';
    }

    /**
     * Karta průměrného skóre: hodnota, posun za 90 dní, pruh rozložení
     * a legenda s počty.
     *
     * @param array<string, mixed> $seo
     * @return array<string, mixed>|null
     */
    private function score(int $siteId, array $seo): ?array
    {
        $plugin = self::plugin($seo);
        $scores = is_array($seo['scores'] ?? null) ? $seo['scores'] : null;
        $average = SeoScore::average($scores['average'] ?? null);

        if ($plugin === '' || $scores === null || $average === null) {
            return null;
        }

        $limits = SeoScore::thresholds($plugin);
        $scored = (int) $scores['good'] + (int) $scores['ok'] + (int) $scores['bad'];
        $unscored = (int) $scores['unscored'];
        $before = $this->kernel->seo()->averageOn($siteId, date('Y-m-d', strtotime('-90 days')));
        $diff = $before !== null ? $average - $before : null;

        return [
            'tone' => SeoScore::tone($average, $plugin),
            'label' => 'Průměrné SEO skóre · ' . SeoScore::word($average, $plugin),
            'value' => $average . ' / 100',
            'change' => $diff === null || $diff === 0 ? null : [
                'direction' => $diff > 0 ? 'up' : 'down',
                'text' => ($diff > 0 ? '▲ ' : '▼ ') . abs($diff) . ' za 90 dní',
                'title' => 'Před 90 dny ' . $before . ' / 100',
            ],
            'note' => SeoScore::PLUGIN_NAMES[$plugin] . ' hodnotí ' . $scored . ' z ' . get_count($scored + $unscored, 'stránky', 'stránek', 'stránek')
                . ($unscored > 0 ? ', ' . $unscored . ' zatím bez hodnocení' : '') . '.',
            'segments' => [
                ['tone' => 'ok', 'label' => 'Dobré (' . $limits['good'] . ' a víc)', 'count' => (int) $scores['good']],
                ['tone' => 'warning', 'label' => 'Průměrné (' . $limits['ok'] . '–' . ($limits['good'] - 1) . ')', 'count' => (int) $scores['ok']],
                ['tone' => 'error', 'label' => 'Slabé (pod ' . $limits['ok'] . ')', 'count' => (int) $scores['bad']],
                ['tone' => 'muted', 'label' => 'Bez hodnocení', 'count' => $unscored],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $seo
     * @return array<int, array{label: string, tone: string, text: string, url: string}>
     */
    private function visibility(array $seo): array
    {
        $plugin = self::plugin($seo);
        $sitemap = is_array($seo['sitemap'] ?? null) ? $seo['sitemap'] : [];
        $pages = isset($seo['coverage']['pages']) ? (int) $seo['coverage']['pages'] : null;
        $indexable = !empty($seo['indexable']);

        return [
            ['label' => 'Vyhledávače', 'tone' => $indexable ? 'ok' : 'error', 'text' => $indexable ? 'web je viditelný' : 'web je skrytý', 'url' => ''],
            ['label' => 'Mapa webu', 'tone' => !empty($sitemap['enabled']) ? 'ok' : 'warning',
                'text' => !empty($sitemap['enabled']) ? 'zapnutá' . ($pages !== null ? ' · ' . get_count($pages, 'stránka', 'stránky', 'stránek') : '') : 'chybí',
                'url' => self::safeUrl((string) ($sitemap['url'] ?? ''))],
            ['label' => 'SEO plugin', 'tone' => $plugin !== '' ? 'ok' : 'warning', 'text' => $plugin !== '' ? trim(SeoScore::PLUGIN_NAMES[$plugin] . ' ' . (string) ($seo['plugin_version'] ?? '')) : 'žádný', 'url' => ''],
        ];
    }

    /**
     * Pokrytí metadat — řádky s pruhem. Procenta z počtu stránek bez
     * noindex; alt text z obrázků v knihovně médií.
     *
     * @param array<string, mixed> $seo
     * @return array<int, array{label: string, note: string, value: string, percent: int, tone: string}>
     */
    private function coverage(array $seo): array
    {
        $coverage = is_array($seo['coverage'] ?? null) ? $seo['coverage'] : [];
        $pages = (int) ($coverage['pages'] ?? 0);
        $rows = [];

        if (isset($coverage['pages']) && $pages > 0) {
            $rows[] = self::coverageRow('Klíčové slovo', '', (int) $coverage['keyword'], $pages);
            $rows[] = self::coverageRow('Vlastní meta popis', '', (int) $coverage['description'], $pages);
            $rows[] = !empty($coverage['og_default'])
                ? ['label' => 'Obrázek pro sdílení (OG)', 'note' => 'výchozí obrázek z nastavení SEO pluginu platí pro všechny stránky', 'value' => 'výchozí pro celý web', 'percent' => 100, 'tone' => 'ok']
                : self::coverageRow('Obrázek pro sdílení (OG)', 'vlastní obrázek nebo náhledový obrázek stránky', (int) $coverage['og_image'], $pages);
        }

        $images = is_array($coverage['images'] ?? null) ? $coverage['images'] : [];

        if ((int) ($images['total'] ?? 0) > 0) {
            $rows[] = self::coverageRow('Alt text obrázků', 'obrázky v knihovně médií', (int) $images['with_alt'], (int) $images['total']);
        }

        return $rows;
    }

    /** @return array{label: string, note: string, value: string, percent: int, tone: string} */
    private static function coverageRow(string $label, string $note, int $done, int $total): array
    {
        $done = max(0, min($done, $total));
        $percent = (int) round($done / max(1, $total) * 100);

        return [
            'label' => $label,
            'note' => $note,
            'value' => number_format($done, 0, ',', ' ') . ' z ' . number_format($total, 0, ',', ' ') . ' · ' . $percent . ' %',
            'percent' => $percent,
            'tone' => $percent >= 80 ? 'ok' : ($percent >= 50 ? 'warning' : 'error'),
        ];
    }

    /**
     * Karta nefunkčních odkazů. Null = plugin starší než 1.7.0 (karta se
     * neukáže); `available` false = web 404 nezaznamenává.
     *
     * @param array<string, mixed> $seo
     * @return array<string, mixed>|null
     */
    private function notFound(array $seo): ?array
    {
        if (!is_array($seo['not_found'] ?? null)) {
            return null;
        }

        $log = $seo['not_found'];
        $redirects = is_array($seo['redirects'] ?? null) ? $seo['redirects'] : ['source' => ''];
        $hits = (int) ($log['hits'] ?? 0);
        $sources = ['rank-math' => 'Rank Math · 404 Monitor', 'redirection' => 'plugin Redirection'];

        return [
            'available' => ($log['source'] ?? '') !== '',
            'title' => 'Nefunkční odkazy · ' . (int) ($log['days'] ?? 7) . ' dní',
            'badge' => $hits > 0 ? get_count($hits, 'zásah', 'zásahy', 'zásahů') : '',
            'source' => $sources[(string) ($log['source'] ?? '')] ?? '',
            'rows' => array_map(static fn (array $row): array => ['url' => (string) ($row['url'] ?? ''), 'hits' => (int) ($row['hits'] ?? 0) . '×'], array_filter((array) ($log['top'] ?? []), 'is_array')),
            'redirects' => ($redirects['source'] ?? '') !== '' ? (int) ($redirects['active'] ?? 0) : null,
        ];
    }

    /**
     * Řádky tabulky slabých stránek. Plugin 1.6.0 posílá jen `worst`
     * (bez klíčového slova a toho, co chybí) — ukáže se, co je.
     *
     * @param array<string, mixed> $seo
     * @return array<int, array<string, mixed>>
     */
    private function pages(array $seo): array
    {
        $plugin = self::plugin($seo);
        $detailed = is_array($seo['weak'] ?? null);
        $rows = [];

        foreach ((array) ($detailed ? $seo['weak'] : ($seo['worst'] ?? [])) as $page) {
            if (!is_array($page)) {
                continue;
            }

            $score = (int) ($page['score'] ?? 0);
            $title = (string) ($page['title'] ?? '');
            $issues = [];

            foreach ((array) ($page['issues'] ?? []) as $issue) {
                if (isset(self::ISSUES[$issue])) {
                    $issues[] = ['label' => self::ISSUES[$issue], 'title' => self::issueTitle((string) $issue, $page)];
                }
            }

            $rows[] = [
                'title' => $title,
                'type' => (string) ($page['type_label'] ?? $page['type'] ?? ''),
                // Plugin 1.6.0 klíčové slovo neposílá — pomlčka, ne „nenastaveno".
                'keyword' => match (true) {
                    !$detailed => '—',
                    (string) ($page['keyword'] ?? '') === '' => 'nenastaveno',
                    default => (string) $page['keyword'],
                },
                'keywordSet' => $detailed && (string) ($page['keyword'] ?? '') !== '',
                'issues' => $issues,
                'score' => $score,
                'tone' => SeoScore::tone($score, $plugin),
                'scoreTitle' => $score . ' ze 100 · ' . SeoScore::word($score, $plugin),
                'url' => self::safeUrl((string) ($page['url'] ?? '')),
                'editUrl' => self::safeUrl((string) ($page['edit_url'] ?? '')),
            ];
        }

        return $rows;
    }

    /** Bublina ke štítku „Co chybí" — konkrétní číslo, kde ho plugin zná. @param array<string, mixed> $page */
    private static function issueTitle(string $issue, array $page): string
    {
        return match ($issue) {
            'keyword' => 'Stránka nemá v SEO pluginu nastavené klíčové slovo.',
            'description' => 'Google si popis stránky vymyslí z textu — vlastní popis ve výsledcích hledání chybí.',
            'short_text' => 'Text má ' . get_count((int) ($page['words'] ?? 0), 'slovo', 'slova', 'slov') . ', doporučeno aspoň 300.',
            'image_alt' => get_count((int) ($page['images_without_alt'] ?? 0), 'obrázek', 'obrázky', 'obrázků') . ' v textu bez alt textu.',
            default => '',
        };
    }

    /** Odkaz z pluginu jen s http(s) — do `href` nesmí projít `javascript:`. */
    private static function safeUrl(string $url): string
    {
        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    /** Web se zapnutým modulem SEO, jinak 404. @return array<string, mixed> */
    private function seoSiteOr404(int $id): array
    {
        $site = $this->kernel->sites()->findWithSnapshot($id);

        if ($site === null || $site['removed_at'] !== null) {
            throw HttpException::notFound('Web neexistuje nebo byl odebrán z monitoringu.');
        }

        if (!$this->kernel->modules()->forSite($id, Modules::SEO)) {
            throw HttpException::notFound('Modul SEO není u webu zapnutý (Nastavení webu → Hlídání).');
        }

        return $site;
    }
}
