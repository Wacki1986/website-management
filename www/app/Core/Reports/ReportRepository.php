<?php

declare(strict_types=1);

namespace App\Core\Reports;

use App\Core\Db\Connection;

/**
 * Nastavení reportů per web (`report_settings`), adresáti
 * (`report_recipients`) a jednotlivé reporty (`reports`) včetně uloženého
 * HTML — co klient dostal, jde kdykoli otevřít znovu.
 */
final class ReportRepository
{
    /**
     * Sekce reportu. Pořadí = pořadí v e-mailu (`ReportRenderer::body()`),
     * aby přepínače v náhledu šly shora dolů stejně jako list vedle nich.
     *
     * @var array<string, array{label: string, note: string, default: bool}>
     */
    public const SECTIONS = [
        'updates' => ['label' => 'Seznam aktualizací', 'note' => 'co jsme na webu udělali', 'default' => true],
        'services' => ['label' => 'Provedený servis', 'note' => 'co jsme na webu odpracovali', 'default' => true],
        'content' => ['label' => 'Obsah webu', 'note' => 'kdy naposledy přibyl obsah; když stojí, výzva ke spolupráci', 'default' => true],
        // Vykreslí se jen u webu se zapnutým modulem SEO (jinak souhrn nemá data).
        'seo' => ['label' => 'SEO webu', 'note' => 'hodnocení stránek z Rank Math / Yoastu a viditelnost pro vyhledávače', 'default' => true],
        'recommendations' => ['label' => 'Doporučení', 'note' => 'na co si dát pozor (PHP, certifikáty)', 'default' => true],
        'uptime_chart' => ['label' => 'Graf dostupnosti', 'note' => 'sloupce po dnech za celé období', 'default' => true],
        'technical' => ['label' => 'Technická příloha', 'note' => 'verze pluginů a PHP — jen pro techniky', 'default' => false],
        'cta' => ['label' => 'Výzva ke kontaktu', 'note' => 'tlačítko Napište nám', 'default' => true],
    ];

    public const STATUS_LABELS = [
        'draft' => 'Rozpracovaný',
        'pending_approval' => 'Čeká na schválení',
        'sent' => 'Odesláno',
        'partial' => 'Částečně doručeno',
        'failed' => 'Selhalo',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<int, string> výchozí zapnuté sekce */
    public static function defaultSections(): array
    {
        return array_keys(array_filter(self::SECTIONS, static fn (array $s): bool => $s['default']));
    }

    // -----------------------------------------------------------------
    // Nastavení
    // -----------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function settings(int $siteId): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM report_settings WHERE site_id = :id', ['id' => $siteId]);

        return $row !== null ? self::decodeSettings($row) : null;
    }

    /**
     * Uloží nastavení a přepočítá další termín.
     *
     * @param array{is_active: bool, frequency: string, send_day: int, send_hour: int, requires_approval: bool, sections?: array<int, string>} $data
     */
    public function saveSettings(int $siteId, array $data, string $now): void
    {
        $frequency = isset(ReportSchedule::FREQUENCIES[$data['frequency']]) ? $data['frequency'] : 'monthly';
        $sendDay = $frequency === 'weekly' ? max(1, min(7, $data['send_day'])) : max(1, min(ReportSchedule::MAX_MONTH_DAY, $data['send_day']));
        $sendHour = max(0, min(23, $data['send_hour']));
        $sections = array_values(array_intersect(array_keys(self::SECTIONS), $data['sections'] ?? self::defaultSections()));
        $next = $data['is_active'] ? ReportSchedule::nextSendAt($frequency, $sendDay, $sendHour, $now) : null;

        $this->db->execute(
            'INSERT INTO report_settings (site_id, is_active, frequency, send_day, send_hour, requires_approval, sections, next_send_at, updated_at)
             VALUES (:site_id, :active, :frequency, :day, :hour, :approval, :sections, :next, :now)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), frequency = VALUES(frequency), send_day = VALUES(send_day),
                 send_hour = VALUES(send_hour), requires_approval = VALUES(requires_approval), sections = VALUES(sections),
                 next_send_at = VALUES(next_send_at), updated_at = VALUES(updated_at)',
            [
                'site_id' => $siteId,
                'active' => $data['is_active'] ? 1 : 0,
                'frequency' => $frequency,
                'day' => $sendDay,
                'hour' => $sendHour,
                'approval' => $data['requires_approval'] ? 1 : 0,
                'sections' => json_encode($sections) ?: '[]',
                'next' => $next,
                'now' => date('Y-m-d H:i:s'),
            ],
        );
    }

    public function advanceSettings(int $siteId, ?string $nextSendAt, ?string $lastSentAt = null): void
    {
        $data = ['next_send_at' => $nextSendAt, 'updated_at' => date('Y-m-d H:i:s')];

        if ($lastSentAt !== null) {
            $data['last_sent_at'] = $lastSentAt;
        }

        $this->db->update('report_settings', $data, ['site_id' => $siteId]);
    }

    /** Aktivní nastavení s termínem, který už nastal (cron). @return array<int, array<string, mixed>> */
    public function dueSettings(string $now): array
    {
        return array_map([self::class, 'decodeSettings'], $this->db->select(
            "SELECT rs.*, s.name AS site_name, s.url, s.client_id FROM report_settings rs JOIN sites s ON s.id = rs.site_id
             WHERE rs.is_active = 1 AND rs.frequency <> 'manual' AND rs.next_send_at IS NOT NULL AND rs.next_send_at <= :now AND s.removed_at IS NULL
             ORDER BY rs.next_send_at",
            ['now' => $now],
        ));
    }

    /** Nastavení se schvalováním, jejichž termín přijde do N hodin (příprava ke schválení). @return array<int, array<string, mixed>> */
    public function approachingApprovals(string $now, int $hours): array
    {
        return array_map([self::class, 'decodeSettings'], $this->db->select(
            "SELECT rs.*, s.name AS site_name, s.url, s.client_id FROM report_settings rs JOIN sites s ON s.id = rs.site_id
             WHERE rs.is_active = 1 AND rs.requires_approval = 1 AND rs.frequency <> 'manual' AND rs.next_send_at IS NOT NULL
               AND rs.next_send_at > :now AND rs.next_send_at <= :until AND s.removed_at IS NULL",
            ['now' => $now, 'until' => date('Y-m-d H:i:s', strtotime($now) + $hours * 3600)],
        ));
    }

    /** Všechna aktivní nastavení s webem (fronta). @return array<int, array<string, mixed>> */
    public function activeSettings(): array
    {
        return array_map([self::class, 'decodeSettings'], $this->db->select(
            'SELECT rs.*, s.name AS site_name, s.url, c.name AS client_name FROM report_settings rs
             JOIN sites s ON s.id = rs.site_id LEFT JOIN clients c ON c.id = s.client_id
             WHERE rs.is_active = 1 AND s.removed_at IS NULL ORDER BY rs.next_send_at IS NULL, rs.next_send_at, s.name',
        ));
    }

    /** @param array<int, int> $siteIds @return array<int, array<string, mixed>> id webu => nastavení */
    public function settingsFor(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $result = [];

        foreach ($this->db->select('SELECT * FROM report_settings WHERE site_id IN (' . implode(', ', array_map('intval', $siteIds)) . ')') as $row) {
            $result[(int) $row['site_id']] = self::decodeSettings($row);
        }

        return $result;
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM report_settings rs JOIN sites s ON s.id = rs.site_id WHERE rs.is_active = 1 AND s.removed_at IS NULL');
    }

    // -----------------------------------------------------------------
    // Adresáti
    // -----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function recipients(int $siteId): array
    {
        return $this->db->select('SELECT * FROM report_recipients WHERE site_id = :id ORDER BY label, id', ['id' => $siteId]);
    }

    /** @return array<int, string> jen adresy */
    public function recipientEmails(int $siteId): array
    {
        return array_map(static fn (array $r): string => (string) $r['email'], $this->recipients($siteId));
    }

    public function addRecipient(int $siteId, string $email, string $label): void
    {
        $this->db->execute(
            'INSERT INTO report_recipients (site_id, email, label, created_at) VALUES (:site_id, :email, :label, :now)
             ON DUPLICATE KEY UPDATE label = VALUES(label)',
            ['site_id' => $siteId, 'email' => mb_strtolower($email), 'label' => $label === 'kopie' ? 'kopie' : 'klient', 'now' => date('Y-m-d H:i:s')],
        );
    }

    public function removeRecipient(int $siteId, int $id): void
    {
        $this->db->delete('report_recipients', ['id' => $id, 'site_id' => $siteId]);
    }

    // -----------------------------------------------------------------
    // Reporty
    // -----------------------------------------------------------------

    /**
     * @param array{site_id: int, kind: string, period_from: string, period_to: string, period_label: string, note?: string,
     *     recipients: array<int, string>, sections: array<int, string>, summary: array<string, mixed>, html: string,
     *     status: string, scheduled_for?: ?string, created_by?: string} $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('reports', [
            'site_id' => $data['site_id'],
            'token' => bin2hex(random_bytes(16)),
            'kind' => $data['kind'],
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'period_label' => mb_substr($data['period_label'], 0, 60),
            'note' => $data['note'] ?? '',
            'recipients' => json_encode(array_values($data['recipients'])) ?: '[]',
            'sections' => json_encode(array_values($data['sections'])) ?: '[]',
            'summary_json' => json_encode($data['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'html' => $data['html'],
            'status' => $data['status'],
            'scheduled_for' => $data['scheduled_for'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => mb_substr($data['created_by'] ?? '', 0, 100),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        foreach (['recipients', 'sections'] as $jsonKey) {
            if (isset($data[$jsonKey]) && is_array($data[$jsonKey])) {
                $data[$jsonKey] = json_encode(array_values($data[$jsonKey])) ?: '[]';
            }
        }

        if (isset($data['summary']) && is_array($data['summary'])) {
            $data['summary_json'] = json_encode($data['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            unset($data['summary']);
        }

        $this->db->update('reports', $data, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('reports', ['id' => $id]);
    }

    /** Report i s webem a klientem. @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT r.*, s.name AS site_name, s.url AS site_url, s.icon AS site_icon, s.client_id, c.name AS client_name FROM reports r
             JOIN sites s ON s.id = r.site_id LEFT JOIN clients c ON c.id = s.client_id WHERE r.id = :id',
            ['id' => $id],
        );

        return $row !== null ? self::decodeReport($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        $row = $this->db->selectOne('SELECT id, site_id, status, opened_at, open_count FROM reports WHERE token = :token', ['token' => $token]);

        return $row;
    }

    /** Rozpracovaný nebo čekající report webu za dané období. @return array<string, mixed>|null */
    public function openFor(int $siteId, string $from, string $to): ?array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM reports WHERE site_id = :site_id AND period_from = :from AND period_to = :to AND status IN ('draft', 'pending_approval') ORDER BY id DESC LIMIT 1",
            ['site_id' => $siteId, 'from' => $from, 'to' => $to],
        );

        return $row !== null ? self::decodeReport($row) : null;
    }

    /** Historie reportů webu (záložka Reporty). @return array<int, array<string, mixed>> */
    public function forSite(int $siteId, int $limit = 24): array
    {
        return array_map([self::class, 'decodeReport'], $this->db->select(
            "SELECT * FROM reports WHERE site_id = :site_id AND kind <> 'test' ORDER BY COALESCE(sent_at, scheduled_for, created_at) DESC, id DESC LIMIT " . $limit,
            ['site_id' => $siteId],
        ));
    }

    /**
     * Fronta a historie napříč weby.
     *
     * @param array{status?: string, q?: string, period?: string} $filter status: pending|sent|failed|all
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filter = []): array
    {
        $where = ["r.kind <> 'test'"];
        $params = [];

        switch ($filter['status'] ?? 'all') {
            case 'pending':
                $where[] = "r.status IN ('draft', 'pending_approval')";
                break;
            case 'sent':
                $where[] = "r.status = 'sent'";
                break;
            case 'failed':
                $where[] = "r.status IN ('failed', 'partial')";
                break;
        }

        if (($filter['q'] ?? '') !== '') {
            $where[] = '(s.name LIKE :q1 OR c.name LIKE :q2 OR s.url LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $filter['q'] . '%';
        }

        if (($filter['period'] ?? '') !== '') {
            $where[] = "DATE_FORMAT(COALESCE(r.sent_at, r.scheduled_for, r.created_at), '%Y-%m') = :period";
            $params['period'] = $filter['period'];
        }

        return array_map([self::class, 'decodeReport'], $this->db->select(
            'SELECT r.*, s.name AS site_name, s.url AS site_url, s.icon AS site_icon, c.name AS client_name FROM reports r
             JOIN sites s ON s.id = r.site_id LEFT JOIN clients c ON c.id = s.client_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(r.sent_at, r.scheduled_for, r.created_at) DESC, r.id DESC LIMIT 300',
            $params,
        ));
    }

    /** Čekající na schválení (nejdřív nejbližší termín). @return array<int, array<string, mixed>> */
    public function pendingApproval(?string $until = null): array
    {
        return array_map([self::class, 'decodeReport'], $this->db->select(
            "SELECT r.*, s.name AS site_name, s.url AS site_url, s.icon AS site_icon FROM reports r JOIN sites s ON s.id = r.site_id
             WHERE r.status = 'pending_approval'" . ($until !== null ? ' AND (r.scheduled_for IS NULL OR r.scheduled_for <= :until)' : '') . '
             ORDER BY r.scheduled_for',
            $until !== null ? ['until' => $until] : [],
        ));
    }

    /**
     * Čísla pro metriky stránky Reporty.
     *
     * @return array{sent_month: int, sent_90d: int, opened_90d: int, pending: int, pending_next: ?string, failed: int, failed_error: string, drafts: int}
     */
    public function counts(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $monthStart = date('Y-m-01 00:00:00', strtotime($now));
        $since90 = date('Y-m-d H:i:s', strtotime($now) - 90 * 86400);

        $row = $this->db->selectOne(
            "SELECT
                SUM(status IN ('sent', 'partial') AND sent_at >= :month) AS sent_month,
                SUM(status IN ('sent', 'partial') AND sent_at >= :since) AS sent_90d,
                SUM(status IN ('sent', 'partial') AND sent_at >= :since2 AND opened_at IS NOT NULL) AS opened_90d,
                SUM(status = 'pending_approval') AS pending,
                MIN(CASE WHEN status = 'pending_approval' THEN scheduled_for END) AS pending_next,
                SUM(status IN ('failed', 'partial')) AS failed,
                SUM(status = 'draft') AS drafts
             FROM reports WHERE kind <> 'test'",
            ['month' => $monthStart, 'since' => $since90, 'since2' => $since90],
        ) ?? [];

        $failedError = (string) ($this->db->scalar("SELECT error FROM reports WHERE status IN ('failed', 'partial') ORDER BY id DESC LIMIT 1") ?? '');

        return [
            'sent_month' => (int) ($row['sent_month'] ?? 0),
            'sent_90d' => (int) ($row['sent_90d'] ?? 0),
            'opened_90d' => (int) ($row['opened_90d'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'pending_next' => $row['pending_next'] ?? null,
            'failed' => (int) ($row['failed'] ?? 0),
            'failed_error' => $failedError,
            'drafts' => (int) ($row['drafts'] ?? 0),
        ];
    }

    /** Poslední odeslaný report pro víc webů (seznamy). @param array<int, int> $siteIds @return array<int, array<string, mixed>> */
    public function latestSentFor(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $result = [];

        foreach ($this->db->select(
            "SELECT r.* FROM reports r JOIN (
                SELECT site_id, MAX(sent_at) AS sent_at FROM reports WHERE status IN ('sent', 'partial') AND site_id IN (" . implode(', ', array_map('intval', $siteIds)) . ') GROUP BY site_id
             ) last ON last.site_id = r.site_id AND last.sent_at = r.sent_at',
        ) as $row) {
            $result[(int) $row['site_id']] = self::decodeReport($row);
        }

        return $result;
    }

    /** Měsíce, ve kterých nějaký report je (filtr Období). @return array<int, string> YYYY-MM */
    public function periods(): array
    {
        return array_map(static fn (array $r): string => (string) $r['p'], $this->db->select(
            "SELECT DISTINCT DATE_FORMAT(COALESCE(sent_at, scheduled_for, created_at), '%Y-%m') AS p FROM reports WHERE kind <> 'test' ORDER BY p DESC LIMIT 24",
        ));
    }

    /** Sledovací pixel: první otevření zapíše čas, každé další jen počítadlo. */
    public function markOpened(string $token, ?string $now = null): bool
    {
        $now ??= date('Y-m-d H:i:s');
        $report = $this->findByToken($token);

        if ($report === null || !in_array($report['status'], ['sent', 'partial'], true)) {
            return false;
        }

        $this->db->execute(
            'UPDATE reports SET opened_at = COALESCE(opened_at, :now), open_count = open_count + 1 WHERE token = :token',
            ['now' => $now, 'token' => $token],
        );

        return true;
    }

    // -----------------------------------------------------------------

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function decodeSettings(array $row): array
    {
        $sections = json_decode((string) $row['sections'], true);
        $row['sections'] = is_array($sections) ? array_values(array_intersect(array_keys(self::SECTIONS), $sections)) : self::defaultSections();
        $row['is_active'] = (int) $row['is_active'];
        $row['requires_approval'] = (int) $row['requires_approval'];
        $row['send_day'] = (int) $row['send_day'];
        $row['send_hour'] = (int) $row['send_hour'];

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function decodeReport(array $row): array
    {
        $recipients = json_decode((string) $row['recipients'], true);
        $sections = json_decode((string) $row['sections'], true);
        $summary = json_decode((string) ($row['summary_json'] ?? '{}'), true);
        $row['recipients'] = is_array($recipients) ? $recipients : [];
        $row['sections'] = is_array($sections) ? $sections : [];
        $row['summary'] = is_array($summary) ? $summary : [];

        return $row;
    }
}
