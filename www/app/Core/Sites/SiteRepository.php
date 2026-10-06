<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Core\Db\Connection;
use App\Core\Security\Secrets;

/**
 * Evidence webů v monitoringu (tabulka `sites`).
 *
 * Řádek webu nese i provozní stav (poslední kontrola, stav pluginu, SSL) —
 * jsou to sloupce, které čte každý seznam, a joinovat je z tabulek kontrol
 * by bylo dražší než je držet tady. Kdo je plní, je napsáno u sloupců
 * v migraci.
 *
 * API klíč se ukládá šifrovaný `app_key` a ven jde jen přes `apiKey()` —
 * do šablony nikdy, tam patří `api_key_hint`.
 */
final class SiteRepository
{
    public const INTERVALS = [5 => 'každých 5 min', 15 => 'každých 15 min', 30 => 'každých 30 min', 60 => 'každou hodinu'];

    /** WordPress s pluginem MEDIAGRAFIK Monitor — API klíč, pluginy, aktualizace, zabezpečení. */
    public const PLATFORM_WORDPRESS = 'wordpress';

    /** Jiný systém (Shoptet, Webnode, statické HTML…) — hlídá se jen dostupnost, SSL a doména. */
    public const PLATFORM_OTHER = 'other';

    /** Sloupec `project_hosting`: první hosting ze služeb projektu webu („Wedos NoLimit"). */
    private const PROJECT_HOSTING = "(SELECT CONCAT_WS(' ', ps.provider, NULLIF(ps.plan, '')) FROM project_services ps
        WHERE ps.project_id = s.project_id AND ps.kind = 'hosting' ORDER BY ps.id LIMIT 1) AS project_hosting";

    public function __construct(
        private readonly Connection $db,
        private readonly Secrets $secrets,
    ) {
    }

    /**
     * Adresa webu v jednotném tvaru: `https://domena.cz` — bez lomítka,
     * cesty, dotazu i velkých písmen v doméně. Bez schématu se doplní https.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        // parse_url spolkne i „host s mezerami“ — doména smí mít jen písmena,
        // číslice, tečky a pomlčky.
        if (!is_array($parts) || empty($parts['host']) || preg_match('/^[a-z0-9.-]+$/i', $parts['host']) !== 1) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        // Web v podadresáři (klient.cz/blog) je legitimní — cesta zůstává,
        // jen bez koncového lomítka.
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme . '://' . $host . $port . $path;
    }

    /** Web s pluginem MEDIAGRAFIK Monitor? Řádek bez sloupce (starší data) je WordPress. @param array<string, mixed> $site */
    public static function isWordpress(array $site): bool
    {
        return ($site['platform'] ?? self::PLATFORM_WORDPRESS) === self::PLATFORM_WORDPRESS;
    }

    /** Na čem web běží, do seznamů: „WordPress", „Shoptet", „jiný systém". @param array<string, mixed> $site */
    public static function platformLabel(array $site): string
    {
        if (self::isWordpress($site)) {
            return 'WordPress';
        }

        return (string) ($site['platform_name'] ?? '') !== '' ? (string) $site['platform_name'] : 'jiný systém';
    }

    /**
     * Hosting webu do přehledu a reportu: z projektu (`project_hosting`
     * z `find()`), u webů z doby před projekty stará poznámka.
     *
     * @param array<string, mixed> $site
     */
    public static function hostingLabel(array $site, string $empty = '—'): string
    {
        foreach ([$site['project_hosting'] ?? null, $site['hosting_note'] ?? null] as $value) {
            if (trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $empty;
    }

    /** Doména pro zobrazení: `kavarnadobra.cz`. */
    public static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    /**
     * Web s klientem, projektem a hostingem projektu (`project_hosting` —
     * první hosting z jeho služeb; přehled a report ho ukazují místo staré
     * poznámky `hosting_note`).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT s.*, c.name AS client_name, c.email AS client_email, p.name AS project_name,
                    ' . self::PROJECT_HOSTING . '
             FROM sites s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN projects p ON p.id = s.project_id
             WHERE s.id = :id',
            ['id' => $id],
        );
    }

    /** Web včetně sloupců poslední snapshoty (prefix `snap_`). @return array<string, mixed>|null */
    public function findWithSnapshot(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT s.*, c.name AS client_name, c.email AS client_email, p.name AS project_name,
                    ' . self::PROJECT_HOSTING . ', ' . self::snapshotColumns() . '
             FROM sites s
             LEFT JOIN clients c ON c.id = s.client_id
             LEFT JOIN projects p ON p.id = s.project_id
             LEFT JOIN site_snapshots ss ON ss.site_id = s.id
             WHERE s.id = :id',
            ['id' => $id],
        );
    }

    /** @return array<string, mixed>|null */
    public function findByUrl(string $url): ?array
    {
        return $this->db->selectOne('SELECT * FROM sites WHERE url = :url', ['url' => $url]);
    }

    /**
     * Seznam webů pro výpisy — s klientem a snapshotou, bez odebraných.
     *
     * @param array{q?: string, client?: ?int, project?: ?int} $filter
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filter = []): array
    {
        $conditions = ['s.removed_at IS NULL'];
        $params = [];

        if (($filter['q'] ?? '') !== '') {
            // Bez emulace prepared statements musí být každý zástupný symbol jen jednou.
            $conditions[] = '(s.name LIKE :q1 OR s.url LIKE :q2 OR c.name LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $filter['q'] . '%';
        }

        if (($filter['client'] ?? null) !== null) {
            $conditions[] = 's.client_id = :client_id';
            $params['client_id'] = (int) $filter['client'];
        }

        if (($filter['project'] ?? null) !== null) {
            $conditions[] = 's.project_id = :project_id';
            $params['project_id'] = (int) $filter['project'];
        }

        return $this->db->select(
            'SELECT s.*, c.name AS client_name, ' . self::snapshotColumns() . '
             FROM sites s
             LEFT JOIN clients c ON c.id = s.client_id
             LEFT JOIN site_snapshots ss ON ss.site_id = s.id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY s.name',
            $params,
        );
    }

    /** Weby jednoho klienta. @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->all(['client' => $clientId]);
    }

    /** Weby jednoho projektu. @return array<int, array<string, mixed>> */
    public function forProject(int $projectId): array
    {
        return $this->all(['project' => $projectId]);
    }

    /** Weby bez klienta — nabídka při zakládání klienta. @return array<int, array<string, mixed>> */
    public function unassigned(): array
    {
        return $this->db->select(
            'SELECT * FROM sites WHERE removed_at IS NULL AND client_id IS NULL ORDER BY created_at DESC',
        );
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM sites WHERE removed_at IS NULL');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('sites', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('sites', $data + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * Odebrání z monitoringu — jen značka; data zůstávají 12 měsíců v archivu.
     *
     * Přístupy z trezoru zůstávají u projektu (hosting a e-maily žijí dál).
     * Když studio končí s celým projektem, smaže se projekt a s ním i trezor.
     */
    public function remove(int $id): void
    {
        $this->update($id, ['removed_at' => date('Y-m-d H:i:s')]);
    }

    public function setApiKey(int $id, string $key): void
    {
        $this->update($id, [
            'api_key' => $this->secrets->encrypt($key),
            'api_key_hint' => ApiKey::hint($key),
            // Nový klíč = plugin ho ještě nemá; stav se zjistí první kontrolou.
            'api_status' => 'unknown',
            'api_failures' => 0,
        ]);
    }

    /**
     * Web přestává být WordPress: bez API klíče ho monitor přestane volat
     * a data z pluginu (snapshot, pluginy) by jen strašila zastaralými
     * verzemi. Historie, uptime, servis a reporty zůstávají.
     */
    public function clearPluginData(int $id): void
    {
        $this->update($id, [
            'api_key' => null,
            'api_key_hint' => '',
            'api_status' => 'unknown',
            'api_failures' => 0,
            'last_snapshot_at' => null,
            'snapshot_error' => null,
        ]);
        $this->db->delete('site_snapshots', ['site_id' => $id]);
        $this->db->delete('site_plugins', ['site_id' => $id]);
    }

    /** Čitelný klíč — jen pro volání pluginu, nikdy do šablony. @param array<string, mixed> $site */
    public function apiKey(array $site): ?string
    {
        $stored = (string) ($site['api_key'] ?? '');

        return $stored === '' ? null : $this->secrets->decrypt($stored);
    }

    /**
     * Web podle otisku API klíče (SHA-256) — tak se prokazuje plugin, který
     * klíč sám nezná, jen jeho otisk (knihovna pluginů). Klíče jsou tu
     * šifrované, takže se porovnávají po jednom; webů jsou desítky.
     *
     * @return array<string, mixed>|null
     */
    public function findByKeyHash(string $hash): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return null;
        }

        foreach ($this->db->select("SELECT * FROM sites WHERE removed_at IS NULL AND api_key IS NOT NULL AND api_key <> ''") as $site) {
            $key = $this->apiKey($site);

            if ($key !== null && hash_equals(hash('sha256', $key), $hash)) {
                return $site;
            }
        }

        return null;
    }

    /** Přiřazení webů klientovi (formulář klienta). @param array<int, int> $siteIds */
    public function assignToClient(array $siteIds, int $clientId): void
    {
        foreach ($siteIds as $siteId) {
            $this->update((int) $siteId, ['client_id' => $clientId]);
        }
    }

    /** Sloupce snapshoty s prefixem `snap_`, aby se v seznamu nepletly se sloupci webu. */
    private static function snapshotColumns(): string
    {
        $columns = [
            'fetched_at', 'plugin_version', 'wp_version', 'wp_update_version', 'php_version', 'db_type',
            'db_version', 'db_size_mb', 'theme_name', 'theme_version', 'theme_is_child', 'plugins_total',
            'plugins_active', 'plugins_updates', 'security_updates', 'last_backup_at', 'security_checked_at',
            'security_missing', 'security_partial', 'plugins_abandoned', 'plugins_closed', 'plugins_insecure',
            'seo_plugin', 'seo_average', 'seo_bad', 'seo_indexable', 'seo_checked_at',
        ];

        return implode(', ', array_map(static fn (string $c): string => 'ss.' . $c . ' AS snap_' . $c, $columns));
    }
}
