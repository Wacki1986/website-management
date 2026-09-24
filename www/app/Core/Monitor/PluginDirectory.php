<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Db\Connection;

/**
 * Pluginy v adresáři wordpress.org: kdy naposledy vyšly a jestli je
 * adresář nestáhl — stejná data, ze kterých Wordfence hlásí „Plugin
 * Abandoned" nebo „removed from wordpress.org".
 *
 * Ptá se správa, ne plugin na webu: jeden dotaz na plugin platí pro
 * všechny weby, kde je nainstalovaný. Výsledek se drží v tabulce
 * `plugin_directory` a obnovuje jednou týdně (krok cronu). Placené
 * a vlastní pluginy v adresáři nejsou (`missing`) — ty se nehodnotí.
 *
 * Opuštěný = poslední vydání starší než práh z Nastavení → Alerty
 * (`rule_abandoned_months`, výchozí 24 měsíců jako Wordfence).
 */
final class PluginDirectory
{
    public const API = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=%s';

    /** Po kolika dnech se údaje o pluginu ověří znovu. */
    public const REFRESH_DAYS = 7;

    /** Kolik pluginů nejvýš ověřit za jeden průchod cronu. */
    public const PER_RUN = 40;

    /** Vlastní plugin studia v adresáři není a hodnotit ho nemá smysl. */
    private const SKIP = ['mediagrafik-monitor'];

    /**
     * Slug z cesty pluginu v SQL — stejně jako `slug()`: složka, nebo název
     * souboru bez `.php` u pluginů bez složky (Hello Dolly).
     */
    private const SLUG_SQL = "IF(LOCATE('/', sp.file) > 0, SUBSTRING_INDEX(sp.file, '/', 1), SUBSTRING_INDEX(sp.file, '.php', 1))";

    /** @var (callable(string): ?string)|null stahování — v testech podvržené */
    private $fetcher;

    public function __construct(
        private readonly Connection $db,
        private readonly MonitorSettings $settings,
        ?callable $fetcher = null,
        private readonly int $timeout = 8,
    ) {
        $this->fetcher = $fetcher;
    }

    /** `woocommerce/woocommerce.php` → `woocommerce`, `hello.php` → `hello`. */
    public static function slug(string $file): string
    {
        return str_contains($file, '/') ? strstr($file, '/', true) : preg_replace('/\.php$/', '', $file);
    }

    /** Práh „opuštěného" pluginu v měsících. */
    public function months(): int
    {
        return $this->settings->int('rule_abandoned_months');
    }

    /** Pluginy vydané před tímto dnem jsou opuštěné. */
    public function staleBefore(?int $now = null): string
    {
        return date('Y-m-d', strtotime('-' . $this->months() . ' months', $now ?? time()));
    }

    /**
     * Pluginy z webů, které adresář ještě nezná nebo je znal před víc než
     * `REFRESH_DAYS` dny — nejdřív nikdy neověřené.
     *
     * @return array<int, string> slugy
     */
    public function dueSlugs(?int $now = null, int $limit = self::PER_RUN): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT ' . self::SLUG_SQL . ' AS slug, pd.checked_at
             FROM site_plugins sp LEFT JOIN plugin_directory pd ON pd.slug = ' . self::SLUG_SQL . '
             WHERE pd.slug IS NULL OR pd.checked_at < :before
             ORDER BY pd.checked_at IS NOT NULL, pd.checked_at',
            ['before' => date('Y-m-d H:i:s', ($now ?? time()) - self::REFRESH_DAYS * 86400)],
        );
        $slugs = array_values(array_diff(array_unique(array_map(static fn (array $row): string => (string) $row['slug'], $rows)), self::SKIP, ['']));

        return array_slice($slugs, 0, $limit);
    }

    /**
     * Ověřit pluginy na wordpress.org a uložit. Plugin, na který služba
     * neodpověděla, se zkusí příště. Nakonec se přepočítají počty webů.
     *
     * @param array<int, string> $slugs
     * @return int kolik pluginů se podařilo ověřit
     */
    public function refresh(array $slugs, ?int $now = null, ?float $deadline = null): int
    {
        $checked = 0;

        foreach ($slugs as $slug) {
            if ($deadline !== null && microtime(true) + $this->timeout > $deadline) {
                break;
            }

            $info = self::parse((string) ($this->fetch(sprintf(self::API, rawurlencode($slug))) ?? ''));

            if ($info === null) {
                continue;
            }

            $this->db->execute(
                'INSERT INTO plugin_directory (slug, status, name, version, last_updated, tested, active_installs, closed_date, closed_reason, checked_at)
                 VALUES (:slug, :status, :name, :version, :last_updated, :tested, :active_installs, :closed_date, :closed_reason, :checked_at)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), name = VALUES(name), version = VALUES(version), last_updated = VALUES(last_updated),
                   tested = VALUES(tested), active_installs = VALUES(active_installs), closed_date = VALUES(closed_date),
                   closed_reason = VALUES(closed_reason), checked_at = VALUES(checked_at)',
                ['slug' => $slug, 'checked_at' => date('Y-m-d H:i:s', $now ?? time())] + $info,
            );
            $checked++;
        }

        if ($checked > 0) {
            $this->recount(null, $now);
        }

        return $checked;
    }

    /**
     * Odpověď API → sloupce `plugin_directory`; null = odpověď nedává
     * smysl (výpadek, HTML chyba) a plugin se zkusí příště.
     *
     * @return array{status: string, name: string, version: string, last_updated: ?string, tested: string, active_installs: ?int, closed_date: ?string, closed_reason: string}|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return null;
        }

        $empty = ['name' => '', 'version' => '', 'last_updated' => null, 'tested' => '', 'active_installs' => null, 'closed_date' => null, 'closed_reason' => ''];
        $error = (string) ($data['error'] ?? '');

        if ($error === 'closed' || !empty($data['closed'])) {
            $closed = strtotime((string) ($data['closed_date'] ?? ''));

            return ['status' => 'closed', 'name' => html_entity_decode((string) ($data['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'closed_date' => $closed !== false ? date('Y-m-d', $closed) : null,
                'closed_reason' => mb_substr((string) ($data['reason_text'] ?? $data['reason'] ?? ''), 0, 100)] + $empty;
        }

        if ($error !== '') {
            // „Plugin not found." — placený nebo vlastní plugin mimo adresář.
            return ['status' => 'missing'] + $empty;
        }

        if (!isset($data['slug'])) {
            return null;
        }

        $updated = strtotime(str_replace(' GMT', '', (string) ($data['last_updated'] ?? '')));

        return [
            'status' => 'found',
            'name' => mb_substr(html_entity_decode((string) ($data['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 200),
            'version' => mb_substr((string) ($data['version'] ?? ''), 0, 40),
            'last_updated' => $updated !== false ? date('Y-m-d', $updated) : null,
            'tested' => mb_substr((string) ($data['tested'] ?? ''), 0, 20),
            'active_installs' => isset($data['active_installs']) ? (int) $data['active_installs'] : null,
            'closed_date' => null,
            'closed_reason' => '',
        ];
    }

    /**
     * Údaje z adresáře k pluginům webu.
     *
     * @param array<int, string> $files cesty pluginů
     * @return array<string, array<string, mixed>> cesta => řádek `plugin_directory` (chybí = zatím neověřeno)
     */
    public function forFiles(array $files): array
    {
        $slugs = array_values(array_unique(array_map([self::class, 'slug'], $files)));

        if ($slugs === []) {
            return [];
        }

        $params = [];

        foreach ($slugs as $i => $slug) {
            $params['s' . $i] = $slug;
        }

        $rows = [];

        foreach ($this->db->select('SELECT * FROM plugin_directory WHERE slug IN (:' . implode(', :', array_keys($params)) . ')', $params) as $row) {
            $rows[(string) $row['slug']] = $row;
        }

        $byFile = [];

        foreach ($files as $file) {
            if (isset($rows[self::slug($file)])) {
                $byFile[$file] = $rows[self::slug($file)];
            }
        }

        return $byFile;
    }

    /**
     * Hodnocení pluginu pro výpis: stav, barva, sloupec „Vydáno" a vysvětlení.
     *
     * @param array<string, mixed>|null $row řádek `plugin_directory`
     * @return array{state: string, tone: string, label: string, released: string, title: string}
     *     state: ok | abandoned | closed | external | unknown
     */
    public function assess(?array $row, ?int $now = null): array
    {
        $now ??= time();

        if ($row === null) {
            return ['state' => 'unknown', 'tone' => '', 'label' => '', 'released' => '—', 'title' => 'Údaje z wordpress.org se ještě nenačetly (cron je doplní).'];
        }

        if ($row['status'] === 'missing') {
            return ['state' => 'external', 'tone' => '', 'label' => '', 'released' => 'mimo adresář', 'title' => 'Plugin není na wordpress.org (placený nebo vlastní) — stáří se nehodnotí.'];
        }

        if ($row['status'] === 'closed') {
            $reason = (string) $row['closed_reason'] !== '' ? ' (důvod: ' . $row['closed_reason'] . ')' : '';

            return ['state' => 'closed', 'tone' => 'error', 'label' => self::isSecurity($row) ? 'Stažen — bezpečnost' : 'Stažen z adresáře',
                'released' => $row['closed_date'] !== null ? 'staženo ' . get_czech_date((string) $row['closed_date']) : 'staženo',
                'title' => 'Plugin byl stažen z adresáře wordpress.org' . $reason . ' a už nedostane opravy — nahraďte ho jiným.'];
        }

        $updated = $row['last_updated'] !== null ? (string) $row['last_updated'] : null;
        $tested = (string) $row['tested'] !== '' ? ', testováno do WordPressu ' . $row['tested'] : '';

        if ($updated === null) {
            return ['state' => 'ok', 'tone' => '', 'label' => '', 'released' => '—', 'title' => 'Datum vydání wordpress.org neuvádí.'];
        }

        $released = self::ageLabel((int) floor(($now - strtotime($updated)) / 86400));

        if ($updated < $this->staleBefore($now)) {
            return ['state' => 'abandoned', 'tone' => 'warning', 'label' => 'Opuštěný', 'released' => $released,
                'title' => 'Poslední vydání ' . get_czech_date($updated) . $tested . ' — plugin se přes ' . $this->months() . ' měsíců nevyvíjí.'];
        }

        return ['state' => 'ok', 'tone' => '', 'label' => '', 'released' => $released, 'title' => 'Poslední vydání ' . get_czech_date($updated) . $tested . '.'];
    }

    /**
     * Opuštěné a stažené pluginy webu — pro alert, servis a report.
     *
     * @return array<int, array{name: string, state: string, security: bool, updated: ?string, reason: string}>
     */
    public function issues(int $siteId, ?int $now = null): array
    {
        $rows = $this->db->select(
            'SELECT sp.name, pd.status, pd.last_updated, pd.closed_reason FROM site_plugins sp
             JOIN plugin_directory pd ON pd.slug = ' . self::SLUG_SQL . '
             WHERE sp.site_id = :id AND (pd.status = \'closed\' OR (pd.status = \'found\' AND pd.last_updated < :before))
             ORDER BY pd.status = \'closed\' DESC, sp.name',
            ['id' => $siteId, 'before' => $this->staleBefore($now)],
        );

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'state' => $row['status'] === 'closed' ? 'closed' : 'abandoned',
            'security' => self::isSecurity($row),
            'updated' => $row['last_updated'] !== null ? (string) $row['last_updated'] : null,
            'reason' => (string) $row['closed_reason'],
        ], $rows);
    }

    /**
     * Věta pro klienta (poznámka servisu, report).
     *
     * @param array{name: string, state: string, security: bool, updated: ?string, reason: string} $issue
     */
    public static function issueText(array $issue): string
    {
        return match (true) {
            $issue['security'] => 'Plugin ' . $issue['name'] . ' byl stažen z adresáře WordPressu kvůli bezpečnostní chybě — doporučujeme ho co nejdřív odstranit nebo nahradit.',
            $issue['state'] === 'closed' => 'Plugin ' . $issue['name'] . ' byl stažen z adresáře WordPressu' . ($issue['reason'] !== '' ? ' (' . mb_strtolower($issue['reason']) . ')' : '') . ' a už nedostane opravy — doporučujeme ho nahradit.',
            default => 'Plugin ' . $issue['name'] . ' se dlouho nevyvíjí' . ($issue['updated'] !== null ? ' (poslední aktualizace ' . get_czech_date($issue['updated']) . ')' : '') . ' — doporučujeme ho nahradit.',
        };
    }

    /**
     * Počty opuštěných, stažených a kvůli bezpečnosti stažených pluginů
     * do `site_snapshots` — stav webu je čte spolu s ostatními údaji.
     */
    public function recount(?int $siteId = null, ?int $now = null): void
    {
        $count = static fn (string $condition): string => '(SELECT COUNT(*) FROM site_plugins sp JOIN plugin_directory pd ON pd.slug = ' . self::SLUG_SQL . '
             WHERE sp.site_id = ss.site_id AND ' . $condition . ')';

        $this->db->execute(
            'UPDATE site_snapshots ss SET
               plugins_abandoned = ' . $count("pd.status = 'found' AND pd.last_updated < :before") . ',
               plugins_closed = ' . $count("pd.status = 'closed'") . ',
               plugins_insecure = ' . $count("pd.status = 'closed' AND pd.closed_reason LIKE '%ecurity%'")
            . ($siteId !== null ? ' WHERE ss.site_id = :id' : ''),
            ['before' => $this->staleBefore($now)] + ($siteId !== null ? ['id' => $siteId] : []),
        );
    }

    /** @param array<string, mixed> $row */
    private static function isSecurity(array $row): bool
    {
        return ($row['status'] ?? '') === 'closed' && stripos((string) ($row['closed_reason'] ?? ''), 'security') !== false;
    }

    /** „před 3 měsíci", „před 2 roky" — sloupec Vydáno. */
    private static function ageLabel(int $days): string
    {
        return match (true) {
            $days < 1 => 'dnes',
            $days < 31 => 'před ' . get_count($days, 'dnem', 'dny', 'dny'),
            $days < 365 => 'před ' . get_count((int) floor($days / 30.4), 'měsícem', 'měsíci', 'měsíci'),
            default => 'před ' . get_count((int) floor($days / 365), 'rokem', 'roky', 'lety'),
        };
    }

    private function fetch(string $url): ?string
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'MEDIAGRAFIK-Sprava-webu/1.0',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // 404 vrací API i u pluginu mimo adresář — tělo s „error" je platná odpověď.
        return is_string($body) && in_array($status, [200, 404], true) ? $body : null;
    }
}
