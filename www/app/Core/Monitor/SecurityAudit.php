<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Db\Connection;

/**
 * Bezpečnostní kontrola webu — pět opatření z návrhu (záložka Zabezpečení).
 *
 * Tři zjišťuje plugin zevnitř (bezpečnostní plugin, dvoufázové ověření,
 * přejmenovaný /wp-content), dvě hub zvenku (`OutsideProbe`: veřejný
 * wp-login.php, Basic auth na administraci). Tady se obojí sloučí do
 * jednoho seznamu, spočítá skóre a uloží k snapshotě.
 *
 * Stavy: ok = nasazeno, warning = s výhradou, error = chybí,
 * unknown = nezjištěno (plugin neodpověděl). Nezjištěno se nikdy
 * nepřevlékne za „chybí".
 */
final class SecurityAudit
{
    /** @var array<string, array{label: string, icon: string}> pořadí = pořadí v tabulce */
    public const CHECKS = [
        'security_plugin' => ['label' => 'Bezpečnostní plugin', 'icon' => 'shield'],
        'login_url' => ['label' => 'Přihlašovací adresa', 'icon' => 'globe'],
        'two_factor' => ['label' => 'Dvoufázové ověření', 'icon' => 'users'],
        'content_dir' => ['label' => 'Složka /wp-content', 'icon' => 'database'],
        'basic_auth' => ['label' => 'Basic AUTH', 'icon' => 'settings'],
    ];

    public const STATUS_LABELS = [
        'ok' => 'Nasazeno',
        'warning' => 'S výhradou',
        'error' => 'Chybí',
        'unknown' => 'Nezjištěno',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly OutsideProbe $probe,
    ) {
    }

    /**
     * Provede kontrolu a uloží ji ke snapshotě webu.
     *
     * @param array<string, mixed> $site
     * @param array<string, mixed>|null $pluginSecurity blok `security` z odpovědi pluginu (null = plugin neodpověděl)
     * @return array{checks: array<int, array<string, string>>, deployed: int, partial: int, missing: int, unknown: int, tone: string, checked_at: string}
     */
    public function run(array $site, ?array $pluginSecurity, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $inside = [];

        foreach ((array) ($pluginSecurity['checks'] ?? []) as $check) {
            if (is_array($check) && isset($check['id'])) {
                $inside[(string) $check['id']] = $check;
            }
        }

        $outside = [
            'login_url' => $this->probe->loginPage((string) $site['url']),
            'basic_auth' => $this->probe->adminBasicAuth((string) $site['url']),
        ];

        $result = self::merge($inside, $outside, $now);

        $this->db->execute(
            'UPDATE site_snapshots SET security_json = :json, security_checked_at = :at, security_missing = :missing, security_partial = :partial
             WHERE site_id = :id',
            [
                'json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'at' => $now,
                'missing' => $result['missing'],
                'partial' => $result['partial'] + $result['unknown'],
                'id' => (int) $site['id'],
            ],
        );

        return $result;
    }

    /**
     * Sloučení vnitřních a vnějších kontrol do jednoho seznamu (čistá
     * funkce — testuje se bez sítě i databáze).
     *
     * @param array<string, array<string, mixed>> $inside  id => {status, value, note}
     * @param array<string, array<string, string>> $outside id => {status, value, note}
     * @return array{checks: array<int, array<string, string>>, deployed: int, partial: int, missing: int, unknown: int, tone: string, checked_at: string}
     */
    public static function merge(array $inside, array $outside, string $checkedAt): array
    {
        $checks = [];
        $counts = ['ok' => 0, 'warning' => 0, 'error' => 0, 'unknown' => 0];

        foreach (self::CHECKS as $id => $meta) {
            $source = $outside[$id] ?? $inside[$id] ?? null;
            $status = (string) ($source['status'] ?? 'unknown');

            if (!isset($counts[$status])) {
                $status = 'unknown';
            }

            $checks[] = [
                'id' => $id,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'status' => $status,
                'statusLabel' => self::STATUS_LABELS[$status],
                'value' => (string) ($source['value'] ?? '—'),
                'note' => (string) ($source['note'] ?? ($source === null ? 'Plugin tuhle kontrolu neposlal' : '')),
            ];
            $counts[$status]++;

            // Slug přihlášení z pluginu doplní hodnotu vnější kontroly.
            if ($id === 'login_url' && isset($inside['login_url']['value']) && $status === 'ok') {
                $checks[count($checks) - 1]['value'] = (string) $inside['login_url']['value'];
            }
        }

        $tone = match (true) {
            $counts['error'] > 0 => 'error',
            $counts['warning'] > 0 || $counts['unknown'] > 0 => 'warning',
            default => 'ok',
        };

        return [
            'checks' => $checks,
            'deployed' => $counts['ok'],
            'partial' => $counts['warning'],
            'missing' => $counts['error'],
            'unknown' => $counts['unknown'],
            'tone' => $tone,
            'checked_at' => $checkedAt,
        ];
    }

    /** Uložený výsledek ke snapshotě, nebo null. @return array<string, mixed>|null */
    public function stored(int $siteId): ?array
    {
        $json = $this->db->scalar('SELECT security_json FROM site_snapshots WHERE site_id = :id', ['id' => $siteId]);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) && isset($decoded['checks']) ? $decoded : null;
    }
}
