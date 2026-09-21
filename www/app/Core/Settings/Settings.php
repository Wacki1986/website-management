<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Db\Connection;
use App\Core\Security\Secrets;

/**
 * Nastavení správy — klíč–hodnota v databázi.
 *
 * Tajemství (API token cPanelu) jdou přes `Secrets`: v databázi leží
 * šifrovaně, ven se vydávají jen na použití — do šablon jde nanejvýš
 * poslední 4 znaky (`secretHint`).
 */
final class Settings
{
    /** @var array<string, ?string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Connection $db,
        private readonly Secrets $secrets,
    ) {
    }

    public function get(string $key, string $default = ''): string
    {
        $this->load();

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (:key, :value, :now)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            ['key' => $key, 'value' => $value, 'now' => date('Y-m-d H:i:s')],
        );

        $this->cache = null;
    }

    /** Uloží tajemství šifrovaně + vedle něj poslední 4 znaky pro zobrazení. */
    public function setSecret(string $key, string $value): void
    {
        $this->set($key, $this->secrets->encrypt($value));
        $this->set($key . '_hint', mb_substr($value, -4));
    }

    /** Čitelná hodnota tajemství — jen pro použití, nikdy do šablony. */
    public function secret(string $key): ?string
    {
        $stored = $this->get($key);

        return $stored === '' ? null : $this->secrets->decrypt($stored);
    }

    /** „••••jAgP" — poslední 4 znaky uloženého tajemství (prázdné = není). */
    public function secretHint(string $key): string
    {
        return $this->get($key . '_hint');
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $this->cache = [];

        foreach ($this->db->select('SELECT `key`, `value` FROM settings') as $row) {
            $this->cache[(string) $row['key']] = $row['value'] !== null ? (string) $row['value'] : null;
        }
    }
}
