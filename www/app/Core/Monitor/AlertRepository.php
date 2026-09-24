<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Db\Connection;

/**
 * Alerty (tabulka `alerts`). Jeden otevřený na dvojici (web, typ) —
 * o to se stará `AlertEngine`, tady jsou jen dotazy a zápisy.
 */
final class AlertRepository
{
    public const TYPES = [
        'down' => 'Web nedostupný',
        'ssl_expiring' => 'SSL brzy vyprší',
        'ssl_expired' => 'SSL vypršel',
        'php_eol' => 'PHP bez podpory',
        'api_error' => 'Plugin neodpovídá',
        'updates' => 'Čekající aktualizace',
        'service_overdue' => 'Servis po termínu',
        'backup_old' => 'Stará záloha',
        'domain_expiring' => 'Expirace domény',
        'plugins_outdated' => 'Opuštěné pluginy',
    ];

    /** @var array<string, string> typ => ikona v seznamu */
    public const ICONS = [
        'down' => 'alert', 'ssl_expiring' => 'shield', 'ssl_expired' => 'alert', 'php_eol' => 'shield',
        'api_error' => 'plugin', 'updates' => 'shield', 'service_overdue' => 'clock', 'backup_old' => 'database',
        'domain_expiring' => 'globe', 'plugins_outdated' => 'plugin',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT a.*, s.name AS site_name, s.url AS site_url, s.icon AS site_icon, c.name AS client_name
             FROM alerts a JOIN sites s ON s.id = a.site_id LEFT JOIN clients c ON c.id = s.client_id
             WHERE a.id = :id',
            ['id' => $id],
        );
    }

    /** Otevřený alert daného typu u webu. @return array<string, mixed>|null */
    public function openOf(int $siteId, string $type): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM alerts WHERE site_id = :site_id AND type = :type AND status = 'open' ORDER BY id DESC LIMIT 1",
            ['site_id' => $siteId, 'type' => $type],
        );
    }

    /** @param array<string, mixed> $detail */
    public function open(int $siteId, string $type, string $severity, string $title, string $body, string $ruleLabel, array $detail = [], ?string $now = null): int
    {
        $now ??= date('Y-m-d H:i:s');

        $occurrences = 1 + (int) $this->db->scalar(
            'SELECT COUNT(*) FROM alerts WHERE site_id = :site_id AND type = :type AND opened_at >= :since',
            ['site_id' => $siteId, 'type' => $type, 'since' => date('Y-m-d H:i:s', strtotime($now . ' -30 days'))],
        );

        return $this->db->insert('alerts', [
            'site_id' => $siteId,
            'type' => $type,
            'severity' => in_array($severity, ['error', 'warning', 'info'], true) ? $severity : 'warning',
            'title' => mb_substr($title, 0, 200),
            'body' => mb_substr($body, 0, 1000),
            'rule_label' => mb_substr($ruleLabel, 0, 120),
            'status' => 'open',
            'opened_at' => $now,
            'occurrences_30d' => $occurrences,
            'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function resolve(int $id, string $who, string $note = '', ?string $now = null): void
    {
        $this->db->update('alerts', [
            'status' => 'resolved',
            'resolved_at' => $now ?? date('Y-m-d H:i:s'),
            'resolved_by' => mb_substr($who, 0, 100),
            'resolve_note' => mb_substr($note, 0, 255),
        ], ['id' => $id]);
    }

    public function ignore(int $id, string $who, ?string $now = null): void
    {
        $this->db->update('alerts', [
            'status' => 'ignored',
            'resolved_at' => $now ?? date('Y-m-d H:i:s'),
            'resolved_by' => mb_substr($who, 0, 100),
        ], ['id' => $id]);
    }

    public function reopen(int $id): void
    {
        $this->db->update('alerts', ['status' => 'open', 'resolved_at' => null, 'resolved_by' => '', 'resolve_note' => ''], ['id' => $id]);
    }

    public function markNotified(int $id, ?string $now = null): void
    {
        $this->db->update('alerts', ['notified_at' => $now ?? date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * Seznam pro stránku Alerty.
     *
     * @param array{status?: string, severity?: string, site?: ?int} $filter status: open|resolved|ignored|all
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filter = [], int $limit = 300): array
    {
        $conditions = [];
        $params = [];
        $status = $filter['status'] ?? 'open';

        if ($status !== 'all') {
            $conditions[] = 'a.status = :status';
            $params['status'] = $status;
        }

        if (($filter['severity'] ?? '') !== '') {
            $conditions[] = 'a.severity = :severity';
            $params['severity'] = $filter['severity'];
        }

        if (($filter['site'] ?? null) !== null) {
            $conditions[] = 'a.site_id = :site_id';
            $params['site_id'] = (int) $filter['site'];
        }

        return $this->db->select(
            'SELECT a.*, s.name AS site_name, s.url AS site_url, s.icon AS site_icon, c.name AS client_name
             FROM alerts a JOIN sites s ON s.id = a.site_id LEFT JOIN clients c ON c.id = s.client_id'
                . ($conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '')
                . ' ORDER BY a.opened_at DESC, a.id DESC LIMIT ' . max(1, $limit),
            $params,
        );
    }

    /** Otevřené alerty webu, nejzávažnější první. @return array<int, array<string, mixed>> */
    public function openForSite(int $siteId): array
    {
        return $this->db->select(
            "SELECT * FROM alerts WHERE site_id = :site_id AND status = 'open'
             ORDER BY FIELD(severity, 'error', 'warning', 'info'), opened_at",
            ['site_id' => $siteId],
        );
    }

    /** Počty pro hlavičku a segmenty. @return array{open: int, error: int, warning: int, info: int, ignored: int, today: int, resolved: int} */
    public function counts(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $row = $this->db->selectOne(
            "SELECT SUM(status = 'open') AS open_count,
                    SUM(status = 'open' AND severity = 'error') AS error_count,
                    SUM(status = 'open' AND severity = 'warning') AS warning_count,
                    SUM(status = 'open' AND severity = 'info') AS info_count,
                    SUM(status = 'ignored') AS ignored_count,
                    SUM(status = 'resolved') AS resolved_count,
                    SUM(status = 'open' AND opened_at >= :today) AS today_count
             FROM alerts",
            ['today' => $today . ' 00:00:00'],
        ) ?? [];

        return [
            'open' => (int) ($row['open_count'] ?? 0),
            'error' => (int) ($row['error_count'] ?? 0),
            'warning' => (int) ($row['warning_count'] ?? 0),
            'info' => (int) ($row['info_count'] ?? 0),
            'ignored' => (int) ($row['ignored_count'] ?? 0),
            'resolved' => (int) ($row['resolved_count'] ?? 0),
            'today' => (int) ($row['today_count'] ?? 0),
        ];
    }

    /** Nejdéle otevřený alert. @return array<string, mixed>|null */
    public function oldestOpen(): ?array
    {
        return $this->db->selectOne(
            "SELECT a.*, s.name AS site_name FROM alerts a JOIN sites s ON s.id = a.site_id
             WHERE a.status = 'open' ORDER BY a.opened_at LIMIT 1",
        );
    }

    /** Výpadky (`down`) v období — pro report. @return array<int, array<string, mixed>> */
    public function outagesBetween(int $siteId, string $from, string $to): array
    {
        return $this->db->select(
            "SELECT * FROM alerts WHERE site_id = :site_id AND type = 'down' AND opened_at BETWEEN :from AND :to ORDER BY opened_at",
            ['site_id' => $siteId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'],
        );
    }

    /** Poslední ukončený výpadek napříč weby (klidný stav dashboardu). @return array<string, mixed>|null */
    public function lastResolvedOutage(): ?array
    {
        return $this->db->selectOne(
            "SELECT a.*, s.name AS site_name FROM alerts a JOIN sites s ON s.id = a.site_id
             WHERE a.type = 'down' AND a.status = 'resolved' AND a.resolved_at IS NOT NULL ORDER BY a.resolved_at DESC LIMIT 1",
        );
    }

    /** Ids otevřených alertů podle filtru — pro „Vyřešit vše nové". @return array<int, int> */
    public function openIds(): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select("SELECT id FROM alerts WHERE status = 'open'"));
    }
}
