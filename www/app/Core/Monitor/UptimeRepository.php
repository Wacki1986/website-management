<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Db\Connection;

/**
 * Kontroly dostupnosti: surové řádky (`uptime_checks`) a denní součty
 * (`uptime_days`), ze kterých se kreslí pásek 30 dní a počítá uptime %
 * v reportu.
 *
 * Denní součty vznikají hned při zápisu kontroly (UPSERT), ne až
 * nočním přepočtem — pásek je tak vždy aktuální a surové řádky se dají
 * po retenci klidně smazat.
 */
final class UptimeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{ok: bool, status: int, ms: int, error: ?string} $result
     * @param int $intervalMin kolik minut výpadku jedna neúspěšná kontrola představuje
     */
    public function record(int $siteId, array $result, string $source, int $intervalMin, ?string $now = null): void
    {
        $now ??= date('Y-m-d H:i:s');

        $this->db->insert('uptime_checks', [
            'site_id' => $siteId,
            'checked_at' => $now,
            'ok' => $result['ok'] ? 1 : 0,
            'status_code' => $result['status'],
            'response_ms' => $result['ms'],
            'error' => $result['error'] !== null ? mb_substr($result['error'], 0, 255) : null,
            'source' => $source === 'manual' ? 'manual' : 'cron',
        ]);

        $this->db->execute(
            'INSERT INTO uptime_days (site_id, day, checks, failed, downtime_min, sum_ms)
             VALUES (:site_id, :day, 1, :failed, :downtime, :ms)
             ON DUPLICATE KEY UPDATE checks = checks + 1, failed = failed + VALUES(failed),
                 downtime_min = downtime_min + VALUES(downtime_min), sum_ms = sum_ms + VALUES(sum_ms)',
            [
                'site_id' => $siteId,
                'day' => substr($now, 0, 10),
                'failed' => $result['ok'] ? 0 : 1,
                // Ruční kontrola nepřidává výpadek: neběží v pravidelném rytmu.
                'downtime' => $result['ok'] || $source === 'manual' ? 0 : $intervalMin,
                'ms' => $result['ok'] ? $result['ms'] : 0,
            ],
        );
    }

    /**
     * Posledních N dní pro pásek — každý den má řádek, i bez kontrol.
     *
     * @return array<int, array{day: string, checks: int, failed: int, downtime_min: int, sum_ms: int, tone: string}>
     */
    public function days(int $siteId, int $days = 30, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $from = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days'));

        $rows = [];

        foreach ($this->db->select(
            'SELECT * FROM uptime_days WHERE site_id = :site_id AND day BETWEEN :from AND :to ORDER BY day',
            ['site_id' => $siteId, 'from' => $from, 'to' => $today],
        ) as $row) {
            $rows[(string) $row['day']] = $row;
        }

        $result = [];

        for ($i = 0; $i < $days; $i++) {
            $day = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
            $row = $rows[$day] ?? null;
            $downtime = (int) ($row['downtime_min'] ?? 0);
            $failed = (int) ($row['failed'] ?? 0);

            $result[] = [
                'day' => $day,
                'checks' => (int) ($row['checks'] ?? 0),
                'failed' => $failed,
                'downtime_min' => $downtime,
                'sum_ms' => (int) ($row['sum_ms'] ?? 0),
                // STAVY.md: 100 % · výpadek do 30 min · nad 30 min.
                'tone' => $failed === 0 ? ($row === null ? 'none' : 'ok') : ($downtime > 30 ? 'error' : 'warning'),
            ];
        }

        return $result;
    }

    /**
     * Souhrn za období — uptime %, výpadky v minutách, průměrná odezva.
     *
     * @return array{percent: ?float, checks: int, failed: int, downtime_min: int, avg_ms: ?int, bad_days: int}
     */
    public function stats(int $siteId, string $from, string $to): array
    {
        $row = $this->db->selectOne(
            'SELECT COALESCE(SUM(checks), 0) AS checks, COALESCE(SUM(failed), 0) AS failed,
                    COALESCE(SUM(downtime_min), 0) AS downtime_min, COALESCE(SUM(sum_ms), 0) AS sum_ms,
                    COALESCE(SUM(failed > 0), 0) AS bad_days
             FROM uptime_days WHERE site_id = :site_id AND day BETWEEN :from AND :to',
            ['site_id' => $siteId, 'from' => $from, 'to' => $to],
        ) ?? [];

        $checks = (int) ($row['checks'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);
        $okChecks = $checks - $failed;

        return [
            'percent' => $checks > 0 ? round($okChecks / $checks * 100, 2) : null,
            'checks' => $checks,
            'failed' => $failed,
            'downtime_min' => (int) ($row['downtime_min'] ?? 0),
            'avg_ms' => $okChecks > 0 ? (int) round((int) $row['sum_ms'] / $okChecks) : null,
            'bad_days' => (int) ($row['bad_days'] ?? 0),
        ];
    }

    /**
     * Uptime % za posledních N dní pro víc webů najednou (seznam, dashboard).
     *
     * @param array<int, int> $siteIds
     * @return array<int, float> id webu => procenta (weby bez kontrol chybí)
     */
    public function percentsFor(array $siteIds, int $days = 30, ?string $today = null): array
    {
        if ($siteIds === []) {
            return [];
        }

        $today ??= date('Y-m-d');
        $from = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days'));
        $result = [];

        foreach ($this->db->select(
            'SELECT site_id, SUM(checks) AS checks, SUM(failed) AS failed FROM uptime_days
             WHERE site_id IN (' . implode(', ', array_map('intval', $siteIds)) . ') AND day BETWEEN :from AND :to
             GROUP BY site_id',
            ['from' => $from, 'to' => $today],
        ) as $row) {
            $checks = (int) $row['checks'];

            if ($checks > 0) {
                $result[(int) $row['site_id']] = round(($checks - (int) $row['failed']) / $checks * 100, 2);
            }
        }

        return $result;
    }

    /** Kolik kontrol dnes proběhlo (Nastavení → „Kontrol dnes"). */
    public function countToday(?string $today = null): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(SUM(checks), 0) FROM uptime_days WHERE day = :day', ['day' => $today ?? date('Y-m-d')]);
    }

    /** Poslední kontroly webu (ladění, detail). @return array<int, array<string, mixed>> */
    public function latest(int $siteId, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT * FROM uptime_checks WHERE site_id = :site_id ORDER BY checked_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['site_id' => $siteId],
        );
    }

    /** Retence surových kontrol; denní součty zůstávají. */
    public function purgeOlderThan(int $months): int
    {
        return $this->db->execute(
            'DELETE FROM uptime_checks WHERE checked_at < :before',
            ['before' => date('Y-m-d H:i:s', strtotime('-' . max(1, $months) . ' months'))],
        );
    }
}
