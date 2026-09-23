<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Db\Connection;

/**
 * Plán servisu (`service_plans`, jeden na web) a historie provedených
 * servisů (`service_logs`).
 *
 * Termíny počítá `ServiceSchedule`; tady se jen ukládá `next_date`, aby
 * seznam webů a dashboard nemusely nic přepočítávat.
 */
final class ServiceRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function plan(int $siteId): ?array
    {
        return $this->db->selectOne('SELECT * FROM service_plans WHERE site_id = :id', ['id' => $siteId]);
    }

    /**
     * Uložení plánu — `next_date` se přepočítá z prvního data a dneška.
     *
     * @param array{is_active: bool, kind: string, frequency: string, first_date: ?string} $plan
     */
    public function savePlan(int $siteId, array $plan, string $today): void
    {
        $kind = isset(ServiceSchedule::KINDS[$plan['kind']]) ? $plan['kind'] : 'small';
        $frequency = isset(ServiceSchedule::FREQUENCIES[$plan['frequency']]) ? $plan['frequency'] : 'monthly';
        $first = $plan['first_date'];
        $next = $first !== null ? ServiceSchedule::next($first, $frequency, $today) : null;

        $this->db->execute(
            'INSERT INTO service_plans (site_id, is_active, kind, frequency, first_date, next_date, updated_at)
             VALUES (:site_id, :active, :kind, :frequency, :first, :next, :now)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), kind = VALUES(kind), frequency = VALUES(frequency),
                 first_date = VALUES(first_date), next_date = VALUES(next_date), updated_at = VALUES(updated_at)',
            [
                'site_id' => $siteId,
                'active' => $plan['is_active'] ? 1 : 0,
                'kind' => $kind,
                'frequency' => $frequency,
                'first' => $first,
                'next' => $next,
                'now' => date('Y-m-d H:i:s'),
            ],
        );
    }

    public function setNextDate(int $siteId, ?string $nextDate): void
    {
        $this->db->update('service_plans', ['next_date' => $nextDate, 'updated_at' => date('Y-m-d H:i:s')], ['site_id' => $siteId]);
    }

    /**
     * Další termíny pro víc webů najednou (seznam webů, klient, dashboard).
     *
     * @param array<int, int> $siteIds
     * @return array<int, array{next_date: ?string, kind: string, is_active: int}>
     */
    public function plansFor(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $plans = [];

        foreach ($this->db->select(
            'SELECT site_id, next_date, kind, is_active FROM service_plans WHERE site_id IN (' . implode(', ', array_map('intval', $siteIds)) . ')',
        ) as $row) {
            $plans[(int) $row['site_id']] = ['next_date' => $row['next_date'], 'kind' => (string) $row['kind'], 'is_active' => (int) $row['is_active']];
        }

        return $plans;
    }

    /** Aktivní plány po termínu (pro pravidlo „nezapsaný servis"). @return array<int, array<string, mixed>> */
    public function overdue(string $before): array
    {
        return $this->db->select(
            'SELECT s.*, p.next_date, p.kind, p.frequency, p.first_date, p.is_active
             FROM service_plans p JOIN sites s ON s.id = p.site_id
             WHERE p.is_active = 1 AND p.next_date IS NOT NULL AND p.next_date < :before AND s.removed_at IS NULL',
            ['before' => $before],
        );
    }

    /** Všechny aktivní plány s termínem i řádkem webu (denní pravidlo). @return array<int, array<string, mixed>> */
    public function activePlans(): array
    {
        return $this->db->select(
            'SELECT s.*, p.next_date, p.kind, p.frequency, p.first_date, p.is_active
             FROM service_plans p JOIN sites s ON s.id = p.site_id
             WHERE p.is_active = 1 AND p.next_date IS NOT NULL AND s.removed_at IS NULL',
        );
    }

    /** Termíny v nejbližších dnech napříč weby (dashboard). @return array<int, array<string, mixed>> */
    public function upcomingAcross(string $from, string $to): array
    {
        return $this->db->select(
            'SELECT p.*, s.name AS site_name FROM service_plans p JOIN sites s ON s.id = p.site_id
             WHERE p.is_active = 1 AND p.next_date BETWEEN :from AND :to AND s.removed_at IS NULL
             ORDER BY p.next_date',
            ['from' => $from, 'to' => $to],
        );
    }

    // -----------------------------------------------------------------
    // Historie
    // -----------------------------------------------------------------

    /**
     * @param array{performed_on: string, kind: string, description: string, minutes: ?int, status: string, user_name: string, checklist?: array<int, array{label: string, done: bool}>} $log
     */
    public function addLog(int $siteId, array $log): int
    {
        return $this->db->insert('service_logs', self::logColumns($log) + [
            'site_id' => $siteId,
            'user_name' => mb_substr($log['user_name'], 0, 100),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Úprava zápisu. Autor zůstává původní, `updated_at` říká, že se na
     * zápis sahalo — plán servisu se úpravou neposouvá.
     *
     * @param array{performed_on: string, kind: string, description: string, minutes: ?int, status: string, checklist?: array<int, array{label: string, done: bool}>} $log
     */
    public function updateLog(int $siteId, int $logId, array $log): void
    {
        $this->db->update('service_logs', self::logColumns($log) + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $logId, 'site_id' => $siteId]);
    }

    /** @param array<string, mixed> $log @return array<string, mixed> */
    private static function logColumns(array $log): array
    {
        return [
            'performed_on' => $log['performed_on'],
            'kind' => isset(ServiceSchedule::KINDS[$log['kind']]) ? $log['kind'] : 'small',
            'description' => $log['description'],
            'checklist' => ServiceChecklists::encode($log['checklist'] ?? []),
            'minutes' => $log['minutes'],
            'status' => $log['status'] === 'skipped' ? 'skipped' : 'done',
        ];
    }

    /** @return array<string, mixed>|null */
    public function findLog(int $siteId, int $logId): ?array
    {
        return $this->db->selectOne('SELECT * FROM service_logs WHERE id = :id AND site_id = :site_id', ['id' => $logId, 'site_id' => $siteId]);
    }

    public function removeLog(int $siteId, int $logId): void
    {
        $this->db->delete('service_logs', ['id' => $logId, 'site_id' => $siteId]);
    }

    /** Posledních N měsíců, nejnovější první. @return array<int, array<string, mixed>> */
    public function logs(int $siteId, int $months = 12, ?string $today = null): array
    {
        return $this->db->select(
            'SELECT * FROM service_logs WHERE site_id = :site_id AND performed_on >= :from ORDER BY performed_on DESC, id DESC',
            ['site_id' => $siteId, 'from' => date('Y-m-d', strtotime(($today ?? date('Y-m-d')) . ' -' . $months . ' months'))],
        );
    }

    /** Servisy v období — pro klientský report. @return array<int, array<string, mixed>> */
    public function logsBetween(int $siteId, string $from, string $to): array
    {
        return $this->db->select(
            "SELECT * FROM service_logs WHERE site_id = :site_id AND status = 'done' AND performed_on BETWEEN :from AND :to ORDER BY performed_on",
            ['site_id' => $siteId, 'from' => $from, 'to' => $to],
        );
    }

    /** Letošní statistika: počet a minuty. @return array{count: int, minutes: int} */
    public function yearStats(int $siteId, ?string $year = null): array
    {
        $year ??= date('Y');
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c, COALESCE(SUM(minutes), 0) AS m FROM service_logs
             WHERE site_id = :site_id AND status = 'done' AND performed_on BETWEEN :from AND :to",
            ['site_id' => $siteId, 'from' => $year . '-01-01', 'to' => $year . '-12-31'],
        ) ?? [];

        return ['count' => (int) ($row['c'] ?? 0), 'minutes' => (int) ($row['m'] ?? 0)];
    }

    /** Poslední provedený servis webu. @return array<string, mixed>|null */
    public function lastDone(int $siteId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM service_logs WHERE site_id = :site_id AND status = 'done' ORDER BY performed_on DESC, id DESC LIMIT 1",
            ['site_id' => $siteId],
        );
    }
}
