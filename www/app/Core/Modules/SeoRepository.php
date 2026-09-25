<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Db\Connection;

/**
 * Historie modulu SEO (`seo_days`) — jeden řádek na web a den, poslední
 * kontrola dne vyhrává. Z ní je trend na záložce SEO a věta „o 4 body
 * lépe než minule" v reportu. Poslední stav je ve `site_snapshots`
 * (sloupce `seo_*`), celá data v payloadu snímku pod klíčem `seo`.
 */
final class SeoRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string, mixed> $seo data z pluginu (`data.seo`) */
    public function record(int $siteId, array $seo, string $now): void
    {
        $scores = is_array($seo['scores'] ?? null) ? $seo['scores'] : [];

        $this->db->execute(
            'INSERT INTO seo_days (site_id, day, average, good, ok, bad, unscored)
             VALUES (:site, :day, :average, :good, :ok, :bad, :unscored)
             ON DUPLICATE KEY UPDATE average = VALUES(average), good = VALUES(good), ok = VALUES(ok),
                 bad = VALUES(bad), unscored = VALUES(unscored)',
            [
                'site' => $siteId,
                'day' => substr($now, 0, 10),
                'average' => SeoScore::average($scores['average'] ?? null),
                'good' => max(0, (int) ($scores['good'] ?? 0)),
                'ok' => max(0, (int) ($scores['ok'] ?? 0)),
                'bad' => max(0, (int) ($scores['bad'] ?? 0)),
                'unscored' => max(0, (int) ($scores['unscored'] ?? 0)),
            ],
        );
    }

    /**
     * Dny s hodnocením od `$from` do `$to` včetně, od nejstaršího.
     *
     * @return array<int, array{day: string, average: ?int, good: int, ok: int, bad: int, unscored: int}>
     */
    public function history(int $siteId, string $from, string $to): array
    {
        $rows = $this->db->select(
            'SELECT day, average, good, ok, bad, unscored FROM seo_days
             WHERE site_id = :site AND day BETWEEN :from AND :to ORDER BY day',
            ['site' => $siteId, 'from' => $from, 'to' => $to],
        );

        return array_map(static fn (array $row): array => [
            'day' => (string) $row['day'],
            'average' => $row['average'] !== null ? (int) $row['average'] : null,
            'good' => (int) $row['good'],
            'ok' => (int) $row['ok'],
            'bad' => (int) $row['bad'],
            'unscored' => (int) $row['unscored'],
        ], $rows);
    }

    /** Průměr k datu — poslední den s hodnocením v ten den nebo před ním. */
    public function averageOn(int $siteId, string $date): ?int
    {
        $value = $this->db->scalar(
            'SELECT average FROM seo_days WHERE site_id = :site AND day <= :day AND average IS NOT NULL
             ORDER BY day DESC LIMIT 1',
            ['site' => $siteId, 'day' => $date],
        );

        return $value !== null && $value !== false ? (int) $value : null;
    }
}
