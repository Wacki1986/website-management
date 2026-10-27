<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Modul SEO pro Správu webů — posílá se jen na vyžádání
 * (`/summary?modules=seo`), web s vypnutým modulem nic navíc nepočítá.
 *
 * Skóre stránek čte z toho, co si ukládá SEO plugin k příspěvkům: Rank Math
 * (`rank_math_seo_score`) nebo Yoast (`_yoast_wpseo_linkdex`). Skóre vzniká
 * v editoru při uložení stránky — stránka, kterou od instalace SEO pluginu
 * nikdo neotevřel, je „bez hodnocení". Stránky s noindex se do hodnocení
 * nepočítají (ve vyhledávání být nemají), jen se sečtou zvlášť.
 *
 * Počty se sčítají v databázi jedním dotazem — e-shop s desítkami tisíc
 * produktů nesmí do PHP tahat řádek po řádku. Text stránek se čte jen
 * u slabě hodnocených (nejvýš `WEAK_LIMIT`).
 *
 * Nefunkční odkazy a přesměrování jsou jen tam, kde je web zaznamenává:
 * moduly 404 Monitor a Přesměrování v Rank Math, nebo plugin Redirection.
 */
final class MG_Seo
{
    /** Kolik slabě hodnocených stránek poslat (seznam „Zobrazit všechny"). */
    const WEAK_LIMIT = 200;

    /** Pod kolik slov je text stránky „krátký" (Yoast doporučuje 300). */
    const SHORT_TEXT_WORDS = 300;

    /** Nefunkční odkazy za posledních N dní. */
    const NOT_FOUND_DAYS = 7;

    /** Kolik nejčastějších nefunkčních adres poslat. */
    const NOT_FOUND_TOP = 5;

    /**
     * Klíče metadat a hranice skóre podle pluginu. Hranice jsou ty, které
     * plugin sám ukazuje barvou v editoru.
     */
    const PLUGINS = array(
        'rank-math' => array(
            'name' => 'Rank Math SEO',
            'score' => 'rank_math_seo_score',
            'keyword' => 'rank_math_focus_keyword',
            'description' => 'rank_math_description',
            'robots' => 'rank_math_robots',
            'og_image' => 'rank_math_facebook_image',
            'good' => 80,
            'ok' => 50,
            // Rank Math ukládá i nulu, když stránku hodnotil.
            'zero_is_unscored' => false,
        ),
        'yoast' => array(
            'name' => 'Yoast SEO',
            'score' => '_yoast_wpseo_linkdex',
            'keyword' => '_yoast_wpseo_focuskw',
            'description' => '_yoast_wpseo_metadesc',
            'robots' => '_yoast_wpseo_meta-robots-noindex',
            'og_image' => '_yoast_wpseo_opengraph-image',
            'good' => 71,
            'ok' => 41,
            // Yoast bez klíčové fráze ukládá 0 = nehodnoceno.
            'zero_is_unscored' => true,
        ),
    );

    /** @return array */
    public static function collect()
    {
        $plugin = self::detect_plugin();
        $types = self::post_types($plugin);
        $data = array(
            'plugin' => $plugin,
            'plugin_name' => $plugin !== '' ? self::PLUGINS[$plugin]['name'] : '',
            'plugin_version' => self::plugin_version($plugin),
            // „Požádat vyhledávače o neindexování" (Nastavení → Zobrazení) —
            // po spuštění webu nejčastěji zapomenutá věc.
            'indexable' => (string) get_option('blog_public') !== '0',
            'sitemap' => self::sitemap($plugin),
            'post_types' => $types,
            'pages' => null,
            'scores' => null,
            'thresholds' => null,
            'missing' => null,
            'noindex' => null,
            'coverage' => array('images' => self::image_alts()),
            'weak' => array(),
            'not_found' => self::not_found(),
            'redirects' => self::redirects(),
        );

        if ($plugin === '' || $types === array()) {
            return $data;
        }

        $config = self::PLUGINS[$plugin];
        $totals = self::totals($config, $types);
        $indexed = (int) $totals['total'] - (int) $totals['noindex'];

        $data['pages'] = (int) $totals['total'];
        $data['scores'] = array(
            'average' => $totals['average'] !== null ? (int) $totals['average'] : null,
            'good' => (int) $totals['good'],
            'ok' => (int) $totals['ok'],
            'bad' => (int) $totals['bad'],
            'unscored' => (int) $totals['unscored'],
        );
        $data['thresholds'] = array('good' => $config['good'], 'ok' => $config['ok']);
        $data['missing'] = array(
            'keyword' => (int) $totals['no_keyword'],
            'description' => (int) $totals['no_description'],
        );
        $data['noindex'] = (int) $totals['noindex'];
        $data['coverage'] += array(
            // Stránky, které mají být ve vyhledávání (bez noindex) — základ procent.
            'pages' => $indexed,
            'keyword' => $indexed - (int) $totals['no_keyword'],
            'description' => $indexed - (int) $totals['no_description'],
            'og_image' => (int) $totals['og_image'],
            // Výchozí obrázek pro sdílení z nastavení pluginu platí pro každou stránku.
            'og_default' => self::og_default($plugin),
        );
        $data['weak'] = self::weak($config, $types);

        return $data;
    }

    /** Rank Math má přednost — oba najednou bývají jen při přechodu. */
    private static function detect_plugin()
    {
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            return 'rank-math';
        }

        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }

        return '';
    }

    private static function plugin_version($plugin)
    {
        if ($plugin === 'rank-math' && defined('RANK_MATH_VERSION')) {
            return (string) RANK_MATH_VERSION;
        }

        if ($plugin === 'yoast' && defined('WPSEO_VERSION')) {
            return (string) WPSEO_VERSION;
        }

        return '';
    }

    /**
     * Typy obsahu, které SEO plugin hodnotí. Když to plugin neumí říct
     * (Yoast to má jen v zastaralé třídě), veřejné typy bez příloh.
     *
     * @return array<int, string>
     */
    private static function post_types($plugin)
    {
        $types = array();

        if ($plugin === 'rank-math' && self::rank_math_can('get_accessible_post_types')) {
            $types = array_values((array) \RankMath\Helper::get_accessible_post_types());
        }

        if ($types === array()) {
            $types = array_values(get_post_types(array('public' => true)));
        }

        return array_values(array_diff(array_map('strval', $types), array('attachment')));
    }

    private static function rank_math_can($method)
    {
        return class_exists('\\RankMath\\Helper') && method_exists('\\RankMath\\Helper', $method);
    }

    private static function rank_math_module($module)
    {
        return self::rank_math_can('is_module_active') && \RankMath\Helper::is_module_active($module);
    }

    /**
     * Mapa webu: modul SEO pluginu, jinak mapa WordPressu (od 5.5).
     * WordPress svoji mapu sám vypne u webu skrytého před vyhledávači.
     *
     * @return array{enabled: bool, url: string, source: string}
     */
    private static function sitemap($plugin)
    {
        if ($plugin === 'rank-math' && self::rank_math_module('sitemap')) {
            return array('enabled' => true, 'url' => home_url('/sitemap_index.xml'), 'source' => 'rank-math');
        }

        if ($plugin === 'yoast' && class_exists('WPSEO_Options') && WPSEO_Options::get('enable_xml_sitemap')) {
            return array('enabled' => true, 'url' => home_url('/sitemap_index.xml'), 'source' => 'yoast');
        }

        if (function_exists('wp_sitemaps_get_server') && wp_sitemaps_get_server()->sitemaps_enabled()) {
            return array('enabled' => true, 'url' => home_url('/wp-sitemap.xml'), 'source' => 'wordpress');
        }

        return array('enabled' => false, 'url' => '', 'source' => '');
    }

    /** Má plugin nastavený výchozí obrázek pro sdílení celého webu? */
    private static function og_default($plugin)
    {
        if ($plugin === 'rank-math') {
            $titles = get_option('rank-math-options-titles');

            return is_array($titles) && !empty($titles['open_graph_image']);
        }

        if ($plugin === 'yoast') {
            $social = get_option('wpseo_social');

            return is_array($social) && !empty($social['og_default_image']);
        }

        return false;
    }

    /**
     * Vnitřní dotaz: jeden řádek na publikovanou stránku s jejím skóre,
     * klíčovým slovem, popisem, obrázkem pro sdílení a příznakem noindex.
     * Obrázek pro sdílení je vlastní z SEO pluginu, nebo náhledový obrázek
     * (plugin ho pak použije sám).
     *
     * @param array $config řádek z PLUGINS
     * @param array<int, string> $types
     * @return string SQL s již dosazenými hodnotami
     */
    private static function pages_sql($config, $types)
    {
        global $wpdb;

        $score = "NULLIF(MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END), '')";

        if ($config['zero_is_unscored']) {
            $score = "NULLIF(" . $score . ", '0')";
        }

        $noindex = $config['robots'] === 'rank_math_robots'
            ? "MAX(CASE WHEN pm.meta_key = %s AND pm.meta_value LIKE '%%noindex%%' THEN 1 ELSE 0 END)"
            : "MAX(CASE WHEN pm.meta_key = %s AND pm.meta_value = '1' THEN 1 ELSE 0 END)";

        $type_placeholders = implode(', ', array_fill(0, count($types), '%s'));

        $sql = "SELECT p.ID AS id, p.post_title AS title, p.post_type AS type,
                    CAST(" . $score . " AS UNSIGNED) AS score,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS keyword,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS description,
                    " . $noindex . " AS noindex,
                    MAX(CASE WHEN pm.meta_key IN (%s, '_thumbnail_id') AND pm.meta_value NOT IN ('', '0') THEN 1 ELSE 0 END) AS og_image
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN (%s, %s, %s, %s, %s, '_thumbnail_id')
                WHERE p.post_status = 'publish' AND p.post_type IN (" . $type_placeholders . ")
                GROUP BY p.ID, p.post_title, p.post_type";

        $keys = array($config['score'], $config['keyword'], $config['description'], $config['robots'], $config['og_image']);

        return $wpdb->prepare($sql, array_merge($keys, $keys, $types));
    }

    /** @return array<string, mixed> */
    private static function totals($config, $types)
    {
        global $wpdb;

        $good = (int) $config['good'];
        $ok = (int) $config['ok'];

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(t.noindex), 0) AS noindex,
                    COALESCE(SUM(t.noindex = 0 AND t.score >= {$good}), 0) AS good,
                    COALESCE(SUM(t.noindex = 0 AND t.score >= {$ok} AND t.score < {$good}), 0) AS ok,
                    COALESCE(SUM(t.noindex = 0 AND t.score < {$ok}), 0) AS bad,
                    COALESCE(SUM(t.noindex = 0 AND t.score IS NULL), 0) AS unscored,
                    ROUND(AVG(CASE WHEN t.noindex = 0 THEN t.score END)) AS average,
                    COALESCE(SUM(t.noindex = 0 AND (t.keyword IS NULL OR t.keyword = '')), 0) AS no_keyword,
                    COALESCE(SUM(t.noindex = 0 AND (t.description IS NULL OR t.description = '')), 0) AS no_description,
                    COALESCE(SUM(t.noindex = 0 AND t.og_image = 1), 0) AS og_image
             FROM (" . self::pages_sql($config, $types) . ") t",
            ARRAY_A
        );

        return is_array($row) ? $row : array(
            'total' => 0, 'noindex' => 0, 'good' => 0, 'ok' => 0, 'bad' => 0, 'unscored' => 0,
            'average' => null, 'no_keyword' => 0, 'no_description' => 0, 'og_image' => 0,
        );
    }

    /**
     * Průměrně a slabě hodnocené stránky (bez noindex), od nejhoršího,
     * a co jim chybí: klíčové slovo, meta popis, delší text, alt u obrázků
     * v textu. Odkaz na úpravu se skládá ručně — `get_edit_post_link()` bez
     * přihlášeného uživatele (REST požadavek hubu) vrací null.
     *
     * @return array<int, array>
     */
    private static function weak($config, $types)
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT t.id, t.title, t.type, t.score, t.keyword, t.description FROM (" . self::pages_sql($config, $types) . ") t
             WHERE t.noindex = 0 AND t.score IS NOT NULL AND t.score < " . (int) $config['good'] . "
             ORDER BY t.score ASC, t.id DESC LIMIT " . self::WEAK_LIMIT,
            ARRAY_A
        );

        $items = array();

        foreach ((array) $rows as $row) {
            $id = (int) $row['id'];
            $type = get_post_type_object((string) $row['type']);
            $text = self::text_stats((string) get_post_field('post_content', $id));
            $keyword = trim((string) $row['keyword']);
            $issues = array();

            if ($keyword === '') {
                $issues[] = 'keyword';
            }

            if (trim((string) $row['description']) === '') {
                $issues[] = 'description';
            }

            if ($text['words'] !== null && $text['words'] < self::SHORT_TEXT_WORDS) {
                $issues[] = 'short_text';
            }

            if ($text['images_without_alt'] > 0) {
                $issues[] = 'image_alt';
            }

            $items[] = array(
                'id' => $id,
                'title' => $row['title'] !== '' ? wp_strip_all_tags((string) $row['title']) : '(bez názvu)',
                'type' => (string) $row['type'],
                'type_label' => $type ? (string) $type->labels->singular_name : (string) $row['type'],
                'score' => (int) $row['score'],
                // Rank Math drží víc klíčových slov oddělených čárkou — hlavní je první.
                'keyword' => $keyword !== '' ? trim(explode(',', $keyword)[0]) : '',
                'issues' => $issues,
                'words' => $text['words'],
                'images_without_alt' => $text['images_without_alt'],
                'url' => (string) get_permalink($id),
                'edit_url' => admin_url('post.php?post=' . $id . '&action=edit'),
            );
        }

        return $items;
    }

    /**
     * Počet slov a obrázků bez alt textu v obsahu stránky.
     *
     * Značky shortcodů se jen vymažou, text uvnitř zůstává (Divi, WPBakery
     * mají text uvnitř shortcodů). Elementor ukládá do obsahu vykreslenou
     * kopii, takže počítá taky. Prázdný obsah = text je jinde (jiný builder)
     * → `words` null, „krátký text" se nehlásí.
     *
     * @return array{words: ?int, images_without_alt: int}
     */
    private static function text_stats($content)
    {
        $missing_alt = 0;

        if (preg_match_all('/<img\b[^>]*>/i', $content, $images)) {
            foreach ($images[0] as $image) {
                if (!preg_match('/\balt\s*=\s*(["\'])\s*[^"\'\s][^"\']*\1/i', $image)) {
                    $missing_alt++;
                }
            }
        }

        $text = preg_replace('/\[\/?[a-zA-Z0-9_-]+[^\]]*\]/', ' ', $content);
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $words = preg_split('/[\s\x{00A0}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $count = is_array($words) ? count($words) : 0;

        return array('words' => $count > 0 ? $count : null, 'images_without_alt' => $missing_alt);
    }

    /**
     * Obrázky v knihovně médií a kolik z nich má alt text. Alt vložený
     * přímo do textu stránky se tu nepočítá (ten hlídá seznam stránek).
     *
     * @return array{total: int, with_alt: int}
     */
    private static function image_alts()
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total, COALESCE(SUM(TRIM(COALESCE(pm.meta_value, '')) <> ''), 0) AS with_alt
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'",
            ARRAY_A
        );

        return array(
            'total' => is_array($row) ? (int) $row['total'] : 0,
            'with_alt' => is_array($row) ? (int) $row['with_alt'] : 0,
        );
    }

    /** Existuje tabulka (s prefixem webu)? */
    private static function table_exists($table)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    /**
     * Nefunkční odkazy (404) za posledních `NOT_FOUND_DAYS` dní —
     * z 404 Monitoru v Rank Math, nebo z pluginu Redirection.
     * `source` '' = web 404 nezaznamenává.
     *
     * Rank Math v jednoduchém režimu drží u adresy celkový počet a čas
     * posledního zásahu, počet u adresy tak může zahrnovat i starší zásahy.
     *
     * @return array{source: string, days: int, hits: int, urls: int, top: array<int, array{url: string, hits: int}>}
     */
    private static function not_found()
    {
        global $wpdb;

        $result = array('source' => '', 'days' => self::NOT_FOUND_DAYS, 'hits' => 0, 'urls' => 0, 'top' => array());
        $since = wp_date('Y-m-d H:i:s', time() - self::NOT_FOUND_DAYS * DAY_IN_SECONDS);

        if (self::rank_math_module('404-monitor') && self::table_exists($wpdb->prefix . 'rank_math_404_logs')) {
            $table = $wpdb->prefix . 'rank_math_404_logs';
            $result['source'] = 'rank-math';
            $totals = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT uri) AS urls, COALESCE(SUM(times), 0) AS hits FROM {$table} WHERE accessed >= %s", $since), ARRAY_A);
            $top = $wpdb->get_results($wpdb->prepare("SELECT uri AS url, SUM(times) AS hits FROM {$table} WHERE accessed >= %s GROUP BY uri ORDER BY hits DESC LIMIT " . self::NOT_FOUND_TOP, $since), ARRAY_A);
        } elseif (defined('REDIRECTION_VERSION') && self::table_exists($wpdb->prefix . 'redirection_404')) {
            $table = $wpdb->prefix . 'redirection_404';
            $result['source'] = 'redirection';
            $totals = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT url) AS urls, COUNT(*) AS hits FROM {$table} WHERE created >= %s", $since), ARRAY_A);
            $top = $wpdb->get_results($wpdb->prepare("SELECT url, COUNT(*) AS hits FROM {$table} WHERE created >= %s GROUP BY url ORDER BY hits DESC LIMIT " . self::NOT_FOUND_TOP, $since), ARRAY_A);
        } else {
            return $result;
        }

        $result['hits'] = is_array($totals) ? (int) $totals['hits'] : 0;
        $result['urls'] = is_array($totals) ? (int) $totals['urls'] : 0;

        foreach ((array) $top as $row) {
            $result['top'][] = array('url' => mb_substr((string) $row['url'], 0, 200), 'hits' => (int) $row['hits']);
        }

        return $result;
    }

    /**
     * Aktivní přesměrování — modul Přesměrování v Rank Math, nebo plugin
     * Redirection. `source` '' = web přesměrování takhle nespravuje.
     *
     * @return array{source: string, active: int}
     */
    private static function redirects()
    {
        global $wpdb;

        if (self::rank_math_module('redirections') && self::table_exists($wpdb->prefix . 'rank_math_redirections')) {
            return array('source' => 'rank-math', 'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_redirections WHERE status = 'active'"));
        }

        if (defined('REDIRECTION_VERSION') && self::table_exists($wpdb->prefix . 'redirection_items')) {
            return array('source' => 'redirection', 'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_items WHERE status = 'enabled'"));
        }

        return array('source' => '', 'active' => 0);
    }
}
