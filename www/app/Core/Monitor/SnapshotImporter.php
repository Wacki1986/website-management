<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Db\Connection;
use App\Core\Events\EventLog;
use App\Core\Sites\SiteRepository;

/**
 * Uložení odpovědi pluginu k webu: snapshot (poslední data), seznam
 * pluginů a rozdíly proti minule jako události do „Historie změn".
 *
 * Rozdíl verzí je jediný zdroj věty „aktualizovali jsme 11 doplňků"
 * v klientském reportu — proto se každá změna verze zapisuje jako událost
 * s detailem `from`/`to`, ne jen přepsáním sloupce.
 */
final class SnapshotImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly SiteRepository $sites,
        private readonly EventLog $events,
    ) {
    }

    /**
     * @param array<string, mixed> $site   řádek webu
     * @param array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string} $result
     * @return array{imported: bool, changes: array<int, string>}
     */
    public function import(array $site, array $result, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $siteId = (int) $site['id'];

        if (!$result['ok'] || !is_array($result['data'])) {
            return ['imported' => false, 'changes' => [$this->recordFailure($site, $result, $now)]];
        }

        $data = $result['data'];
        $previous = $this->db->selectOne('SELECT * FROM site_snapshots WHERE site_id = :id', ['id' => $siteId]);
        $changes = [];

        $row = self::columns($data) + [
            'site_id' => $siteId,
            'fetched_at' => $now,
            'plugin_version' => mb_substr($result['plugin_version'], 0, 20),
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $this->upsertSnapshot($row);

        // Změny jádra a PHP proti minulé snapshotě.
        if ($previous !== null) {
            $changes = array_merge($changes, $this->coreChanges($siteId, $previous, $row));
        }

        $changes = array_merge($changes, $this->importPlugins($siteId, $data['plugins']['items'] ?? [], $previous !== null, $now));
        $this->recountUpdates($siteId);

        $this->sites->update($siteId, [
            'api_status' => 'ok',
            'api_failures' => 0,
            'last_snapshot_at' => $now,
            'snapshot_error' => null,
        ]);

        // Plugin, který se ozval poprvé, je událost sama o sobě.
        if ($previous === null) {
            $this->events->record($siteId, EventLog::KIND_SYSTEM, 'ok', 'Plugin MEDIAGRAFIK Monitor poprvé odpověděl — data načtena');
            $changes[] = 'první načtení dat';
        }

        return ['imported' => true, 'changes' => $changes];
    }

    /**
     * Neúspěch: stav pluginu se u webu přepíše, ale snapshot zůstává —
     * v UI se ukazuje jako zastaralá data. `unreachable` stav nemění
     * (web je nejspíš celý dole; to hlásí uptime), jen počítá selhání.
     *
     * @param array<string, mixed> $site
     * @param array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string} $result
     */
    private function recordFailure(array $site, array $result, string $now): string
    {
        $siteId = (int) $site['id'];
        $failures = (int) ($site['api_failures'] ?? 0) + 1;
        $update = [
            'api_failures' => $failures,
            'snapshot_error' => mb_substr((string) ($result['error'] ?? 'neznámá chyba'), 0, 255),
        ];

        if ($result['code'] !== 'unreachable') {
            $update['api_status'] = $result['code'];
        }

        $this->sites->update($siteId, $update);

        // Třetí selhání v řadě = událost (a v P2 alert), ne každé.
        if ($failures === 3) {
            $this->events->record($siteId, EventLog::KIND_SYSTEM, 'error',
                'Plugin MEDIAGRAFIK Monitor neodpovídá: ' . (string) ($result['error'] ?? ''), ['code' => $result['code']]);
        }

        return 'plugin neodpověděl (' . $result['code'] . ')';
    }

    /**
     * Sloupce snapshoty z payloadu — chybějící klíč = výchozí hodnota,
     * nikdy chyba (plugin může být starší než hub).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function columns(array $data): array
    {
        $wp = (array) ($data['wordpress'] ?? []);
        $server = (array) ($data['server'] ?? []);
        $theme = (array) ($data['theme'] ?? []);
        $plugins = (array) ($data['plugins'] ?? []);
        $backup = (array) ($data['backup'] ?? []);
        $lastBackup = (string) ($backup['last_backup_at'] ?? '');

        return [
            'wp_version' => mb_substr((string) ($wp['version'] ?? ''), 0, 20),
            'wp_update_version' => !empty($wp['has_update']) && (string) ($wp['new_version'] ?? '') !== '' ? mb_substr((string) $wp['new_version'], 0, 20) : null,
            'php_version' => mb_substr((string) ($server['php_version'] ?? ''), 0, 20),
            'db_type' => mb_substr((string) ($server['db_type'] ?? ''), 0, 20),
            'db_version' => mb_substr((string) ($server['db_version'] ?? ''), 0, 30),
            'db_size_mb' => isset($server['db_size_mb']) && is_numeric($server['db_size_mb']) ? (int) $server['db_size_mb'] : null,
            'theme_name' => mb_substr((string) ($theme['name'] ?? ''), 0, 100),
            'theme_version' => mb_substr((string) ($theme['version'] ?? ''), 0, 20),
            'theme_is_child' => !empty($theme['is_child']) ? 1 : 0,
            'plugins_total' => (int) ($plugins['total'] ?? count((array) ($plugins['items'] ?? []))),
            'plugins_active' => (int) ($plugins['active'] ?? 0),
            'plugins_updates' => (int) ($plugins['updates'] ?? 0),
            'security_updates' => (int) ($plugins['security_updates'] ?? 0),
            'last_backup_at' => $lastBackup !== '' && strtotime($lastBackup) !== false ? date('Y-m-d H:i:s', (int) strtotime($lastBackup)) : null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function upsertSnapshot(array $row): void
    {
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $updates = array_map(static fn (string $c): string => '`' . $c . '` = VALUES(`' . $c . '`)', array_diff($columns, ['site_id']));

        $this->db->execute(
            'INSERT INTO site_snapshots (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            $row,
        );
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     * @return array<int, string>
     */
    private function coreChanges(int $siteId, array $previous, array $current): array
    {
        $changes = [];

        if ((string) $previous['wp_version'] !== '' && (string) $previous['wp_version'] !== (string) $current['wp_version']) {
            $this->events->record($siteId, EventLog::KIND_CORE, 'ok',
                'WordPress aktualizován ' . $previous['wp_version'] . ' → ' . $current['wp_version'],
                ['action' => 'updated', 'from' => $previous['wp_version'], 'to' => $current['wp_version']]);
            $changes[] = 'WordPress ' . $previous['wp_version'] . ' → ' . $current['wp_version'];
        }

        if ((string) $previous['php_version'] !== '' && (string) $previous['php_version'] !== (string) $current['php_version']) {
            $this->events->record($siteId, EventLog::KIND_SYSTEM, 'ok',
                'PHP změněno ' . $previous['php_version'] . ' → ' . $current['php_version'],
                ['action' => 'php', 'from' => $previous['php_version'], 'to' => $current['php_version']]);
            $changes[] = 'PHP ' . $previous['php_version'] . ' → ' . $current['php_version'];
        }

        if ((string) $previous['theme_name'] !== '' && (string) $previous['theme_name'] !== (string) $current['theme_name']) {
            $this->events->record($siteId, EventLog::KIND_SYSTEM, 'ok',
                'Šablona změněna: ' . $previous['theme_name'] . ' → ' . $current['theme_name'],
                ['action' => 'theme', 'from' => $previous['theme_name'], 'to' => $current['theme_name']]);
            $changes[] = 'šablona ' . $current['theme_name'];
        }

        return $changes;
    }

    /**
     * Seznam pluginů: nové, aktualizované, (de)aktivované, odstraněné.
     * Při prvním načtení se jen zapíšou — bez záplavy událostí „nový plugin".
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function importPlugins(int $siteId, array $items, bool $hadSnapshot, string $now): array
    {
        $known = [];

        foreach ($this->db->select('SELECT * FROM site_plugins WHERE site_id = :id', ['id' => $siteId]) as $row) {
            $known[(string) $row['file']] = $row;
        }

        $seen = [];
        $changes = [];

        foreach ($items as $item) {
            $file = mb_substr((string) ($item['file'] ?? ''), 0, 191);

            if ($file === '') {
                continue;
            }

            $seen[$file] = true;
            $name = mb_substr((string) ($item['name'] ?? $file), 0, 150);
            $version = mb_substr((string) ($item['version'] ?? ''), 0, 30);
            $isActive = !empty($item['is_active']) ? 1 : 0;
            $data = [
                'name' => $name,
                'author' => mb_substr((string) ($item['author'] ?? ''), 0, 150),
                'version' => $version,
                'new_version' => !empty($item['has_update']) && (string) ($item['new_version'] ?? '') !== '' ? mb_substr((string) $item['new_version'], 0, 30) : null,
                'is_active' => $isActive,
                'has_update' => !empty($item['has_update']) ? 1 : 0,
                'last_seen_at' => $now,
            ];

            $old = $known[$file] ?? null;

            if ($old === null) {
                $this->db->insert('site_plugins', $data + ['site_id' => $siteId, 'file' => $file, 'first_seen_at' => $now]);

                if ($hadSnapshot) {
                    $this->events->record($siteId, EventLog::KIND_PLUGIN, 'ok', $name . ' nainstalován (' . $version . ')',
                        ['action' => 'installed', 'plugin' => $name, 'to' => $version]);
                    $changes[] = $name . ' nainstalován';
                }

                continue;
            }

            if ((string) $old['version'] !== $version && (string) $old['version'] !== '') {
                $data['version_changed_at'] = $now;
                $this->events->record($siteId, EventLog::KIND_PLUGIN, 'ok',
                    $name . ' aktualizován ' . $old['version'] . ' → ' . $version,
                    ['action' => 'updated', 'plugin' => $name, 'from' => $old['version'], 'to' => $version]);
                $changes[] = $name . ' ' . $old['version'] . ' → ' . $version;
            }

            if ((int) $old['is_active'] !== $isActive) {
                $this->events->record($siteId, EventLog::KIND_PLUGIN, $isActive ? 'ok' : 'warning',
                    $name . ($isActive ? ' aktivován' : ' deaktivován'),
                    ['action' => $isActive ? 'activated' : 'deactivated', 'plugin' => $name]);
                $changes[] = $name . ($isActive ? ' aktivován' : ' deaktivován');
            }

            $this->db->update('site_plugins', $data, ['id' => (int) $old['id']]);
        }

        foreach ($known as $file => $old) {
            if (!isset($seen[$file])) {
                $this->db->delete('site_plugins', ['id' => (int) $old['id']]);
                $this->events->record($siteId, EventLog::KIND_PLUGIN, 'ok', $old['name'] . ' odstraněn',
                    ['action' => 'removed', 'plugin' => $old['name']]);
                $changes[] = $old['name'] . ' odstraněn';
            }
        }

        return $changes;
    }

    /**
     * Počet čekajících aktualizací pluginů v snapshotě — bez pluginů, jejichž
     * aktualizace se nesledují, a bez „aktualizací" na stejnou či starší
     * verzi (zbytek mezipaměti WordPressu u pluginu < 1.3.1). Z tohohle čísla
     * čtou záložka, seznam webů, dashboard, alert i report.
     *
     * @return int nový počet
     */
    public function recountUpdates(int $siteId): int
    {
        $count = 0;

        foreach ($this->db->select('SELECT version, new_version FROM site_plugins WHERE site_id = :id AND has_update = 1 AND updates_ignored = 0', ['id' => $siteId]) as $plugin) {
            if (version_compare((string) $plugin['new_version'], (string) $plugin['version'], '>')) {
                $count++;
            }
        }

        $this->db->update('site_snapshots', ['plugins_updates' => $count], ['site_id' => $siteId]);

        return $count;
    }

    /** Sledovat / nesledovat aktualizace pluginu; vrací nový počet čekajících aktualizací. */
    public function setUpdatesIgnored(int $siteId, string $file, bool $ignored): int
    {
        $this->db->update('site_plugins', ['updates_ignored' => $ignored ? 1 : 0], ['site_id' => $siteId, 'file' => $file]);

        return $this->recountUpdates($siteId);
    }

    /** Uložená snapshot webu. @return array<string, mixed>|null */
    public function snapshot(int $siteId): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM site_snapshots WHERE site_id = :id', ['id' => $siteId]);

        if ($row === null) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true);
        $row['data'] = is_array($payload) ? $payload : [];

        return $row;
    }

    /** Pluginy webu pro záložku Pluginy — neaktivní nahoře? Ne: abecedně, neaktivní se barví. @return array<int, array<string, mixed>> */
    public function plugins(int $siteId, string $search = ''): array
    {
        $params = ['id' => $siteId];
        $where = '';

        if ($search !== '') {
            $where = ' AND (name LIKE :q1 OR author LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $search . '%';
        }

        return $this->db->select('SELECT * FROM site_plugins WHERE site_id = :id' . $where . ' ORDER BY name', $params);
    }
}
