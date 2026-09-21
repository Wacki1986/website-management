<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Db\Connection;

/**
 * Odběry web pushe — „na která zařízení má správa posílat upozornění".
 *
 * Jeden řádek = jeden prohlížeč na jednom zařízení. Endpoint vydává push
 * služba a je dlouhý (stovky znaků), proto se unikátnost hlídá přes jeho
 * SHA-256 (`endpoint_hash`) a ne přes TEXT sloupec.
 *
 * Zapíná se v Nastavení → Oznámení, a to **za zařízení, ne za účet**:
 * povolení dává prohlížeč a platí jen pro něj. Které události pípnou, je
 * naopak volba celé instalace (`settings.push_on_*`) — správci jsou dva
 * a chtějí vědět totéž.
 *
 * Mrtvé odběry uklízí `PushNotifier`: 404/410 od služby znamená smazat
 * hned, opakované jiné chyby smažou po `MAX_FAILURES`.
 */
final class PushSubscriptions
{
    public const MAX_FAILURES = 5;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Uloží nebo oživí odběr. Stejný endpoint od jiného uživatele
     * (sdílený telefon, odhlášení a přihlášení kolegy) se **přepíše** —
     * zařízení má dostávat zprávy toho, kdo je na něm přihlášený teď.
     */
    public function save(int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): int
    {
        $hash = hash('sha256', $endpoint);
        $now = date('Y-m-d H:i:s');
        $existing = $this->db->selectOne('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?', [$hash]);

        $data = [
            'user_id' => $userId,
            'p256dh' => mb_substr($p256dh, 0, 120),
            'auth' => mb_substr($auth, 0, 40),
            'user_agent' => mb_substr($userAgent, 0, 190),
            'failed_count' => 0,
            'last_success_at' => $now,
        ];

        if ($existing !== null) {
            $this->db->update('push_subscriptions', $data, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert('push_subscriptions', $data + [
            'endpoint_hash' => $hash,
            'endpoint' => $endpoint,
            'created_at' => $now,
        ]);
    }

    /** Odhlášení z prohlížeče — zná jen endpoint, ne ID. */
    public function removeByEndpoint(int $userId, string $endpoint): bool
    {
        return $this->db->delete('push_subscriptions', [
            'endpoint_hash' => hash('sha256', $endpoint),
            'user_id' => $userId,
        ]) > 0;
    }

    /** Odebrání ze seznamu v Nastavení — jen vlastní zařízení. */
    public function remove(int $userId, int $id): bool
    {
        return $this->db->delete('push_subscriptions', ['id' => $id, 'user_id' => $userId]) > 0;
    }

    /** Seznam do karty „Toto zařízení". @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT id, endpoint_hash, user_agent, failed_count, created_at, last_success_at
             FROM push_subscriptions WHERE user_id = ? ORDER BY created_at DESC',
            [$userId],
        );
    }

    /**
     * Zařízení všech aktivních účtů — příjemci každého pushe.
     *
     * Správa nemá role ani adresáty per událost: účty jsou dva a oba chtějí
     * vědět, když přijde zpráva na podporu nebo spadne instance. Odběry
     * pozastavených účtů se přeskočí — pozastavený kolega už zprávy dostávat
     * nemá, i kdyby mu odběr v telefonu zůstal.
     *
     * @return list<array{id: int, endpoint: string, p256dh: string, auth: string, failed_count: int}>
     */
    public function forActiveUsers(int $limit): array
    {
        $rows = $this->db->select(
            'SELECT s.id, s.endpoint, s.p256dh, s.auth, s.failed_count
             FROM push_subscriptions s
             JOIN users u ON u.id = s.user_id
             WHERE u.is_active = 1
             ORDER BY s.id ASC
             LIMIT ' . max(1, $limit),
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'endpoint' => (string) $row['endpoint'],
            'p256dh' => (string) $row['p256dh'],
            'auth' => (string) $row['auth'],
            'failed_count' => (int) $row['failed_count'],
        ], $rows);
    }

    public function markDelivered(int $id): void
    {
        $this->db->update('push_subscriptions', [
            'failed_count' => 0,
            'last_success_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /** Vrací true, když odběr po téhle chybě skončil (smazal se). */
    public function markFailed(int $id, int $previousFailures): bool
    {
        if ($previousFailures + 1 >= self::MAX_FAILURES) {
            $this->db->delete('push_subscriptions', ['id' => $id]);

            return true;
        }

        $this->db->update('push_subscriptions', ['failed_count' => $previousFailures + 1], ['id' => $id]);

        return false;
    }

    public function delete(int $id): void
    {
        $this->db->delete('push_subscriptions', ['id' => $id]);
    }

    public function count(): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM push_subscriptions')['c'] ?? 0);
    }

    /**
     * Čitelný název zařízení z user agentu — „Android · Chrome". Hrubý odhad
     * pro seznam v Nastavení, ne detekce prohlížeče; stačí, aby člověk poznal
     * svůj telefon od počítače.
     */
    public static function deviceLabel(string $userAgent): string
    {
        $system = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Zařízení',
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => '',
        };

        return $browser === '' ? $system : $system . ' · ' . $browser;
    }
}
