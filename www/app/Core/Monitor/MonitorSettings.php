<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Settings\Settings;

/**
 * Nastavení monitoru a prahů alertů (Nastavení → Monitoring, Alerty a prahy).
 *
 * Tenká vrstva nad `Settings`: výchozí hodnoty na jednom místě, typované
 * čtení a zápis s ořezem na rozumné meze. Klíče v tabulce `settings`
 * odpovídají názvům polí ve formuláři.
 */
final class MonitorSettings
{
    /** @var array<string, int> klíč => výchozí hodnota */
    public const DEFAULTS = [
        'monitor_interval_min' => 15,
        'monitor_timeout_s' => 10,
        'monitor_fail_threshold' => 3,
        'monitor_history_months' => 12,
        'monitor_snapshot_hours' => 6,
        'monitor_digest_hour' => 7,
        'rule_updates_on' => 1,
        'rule_updates_max' => 10,
        'rule_ssl_on' => 1,
        'rule_ssl_days' => 30,
        'rule_backup_on' => 1,
        'rule_backup_hours' => 48,
        'rule_service_on' => 1,
        'rule_service_hours' => 24,
        'rule_domain_on' => 0,
        'rule_domain_days' => 90,
        'rule_abandoned_on' => 1,
        'rule_abandoned_months' => 24,
        'rule_seo_hidden_on' => 1,
        'rule_seo_low_on' => 1,
        'rule_seo_low_score' => 50,
    ];

    /** @var array<string, array{0: int, 1: int}> meze hodnot */
    private const LIMITS = [
        'monitor_interval_min' => [5, 60],
        'monitor_timeout_s' => [3, 60],
        'monitor_fail_threshold' => [1, 10],
        'monitor_history_months' => [1, 60],
        'monitor_snapshot_hours' => [1, 48],
        'monitor_digest_hour' => [0, 23],
        'rule_updates_max' => [1, 100],
        'rule_ssl_days' => [1, 90],
        'rule_backup_hours' => [6, 720],
        'rule_service_hours' => [1, 720],
        'rule_domain_days' => [7, 365],
        'rule_abandoned_months' => [6, 120],
        'rule_seo_low_score' => [10, 100],
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function int(string $key): int
    {
        $raw = $this->settings->get($key);
        $default = self::DEFAULTS[$key] ?? 0;

        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return self::clamp($key, (int) $raw);
    }

    public function bool(string $key): bool
    {
        return $this->int($key) === 1;
    }

    /** @param array<string, mixed> $values klíč => hodnota z formuláře; neznámé klíče se ignorují */
    public function save(array $values): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = str_ends_with($key, '_on')
                ? (in_array($values[$key], ['1', 1, true, 'on'], true) ? 1 : 0)
                : self::clamp($key, (int) $values[$key]);

            $this->settings->set($key, (string) $value);
        }
    }

    /** Adresy studia pro alerty — čárkou oddělené, prázdné se přeskočí. @return array<int, string> */
    public function alertEmails(): array
    {
        $emails = [];

        foreach (preg_split('/[,;\s]+/', $this->settings->get('alert_emails')) ?: [] as $email) {
            $email = mb_strtolower(trim($email));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private static function clamp(string $key, int $value): int
    {
        if (str_ends_with($key, '_on')) {
            return $value === 1 ? 1 : 0;
        }

        [$min, $max] = self::LIMITS[$key] ?? [0, PHP_INT_MAX];

        return max($min, min($max, $value));
    }
}
