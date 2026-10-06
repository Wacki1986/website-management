<?php

declare(strict_types=1);

namespace App\Core\Projects;

use App\Core\Monitor\DomainChecker;
use App\Core\Monitor\MonitorSettings;
use App\Core\Monitor\Notifier;

/**
 * Krok cronu pro služby projektů (zapojený v `Kernel::monitor()` přes
 * `addStep`): expirace domén přes RDAP jednou týdně a upozornění na
 * blížící se obnovy.
 *
 * Upozornění nejde přes alerty — ty patří k webům (`alerts.site_id`)
 * a doména bez webu by neměla kam. Chodí jako souhrn e-mailem a pushem,
 * jednou na každou obnovu (`notified_for`), v hodinu ranního souhrnu.
 */
final class Renewals
{
    public function __construct(
        private readonly ProjectServices $services,
        private readonly DomainChecker $domains,
        private readonly Notifier $notifier,
        private readonly MonitorSettings $settings,
    ) {
    }

    /** @return int počet ověřených domén */
    public function step(int $now, float $deadline): int
    {
        $stamp = date('Y-m-d H:i:s', $now);
        $checked = 0;

        foreach ($this->services->domainsToCheck($stamp) as $service) {
            if (microtime(true) > $deadline - 10) {
                break;
            }

            $this->services->saveExpiry((int) $service['id'], $this->domains->check((string) $service['name'], $now), $stamp);
            $checked++;
        }

        if ($this->settings->bool('rule_renewal_on') && (int) date('G', $now) >= $this->settings->int('monitor_digest_hour')) {
            $this->notify(date('Y-m-d', $now));
        }

        return $checked;
    }

    /** Souhrn obnov, o kterých se ještě nepsalo. */
    public function notify(string $today): void
    {
        $days = $this->settings->int('rule_renewal_days');
        $due = $this->services->dueForNotice($today, $days);
        $rows = [];

        foreach ($due as $service) {
            $kind = ProjectServices::KINDS[(string) $service['kind']]['label'] ?? '';
            $name = (string) $service['name'] !== '' ? (string) $service['name'] : (string) $service['provider'];
            $state = ProjectServices::renewalState($service, $today, $days);
            $billing = ProjectServices::billingState($service, $today, $days);
            $rows[] = [
                'label' => $service['project_name'] . ' · ' . $kind . ' ' . $name,
                'value' => $state['label'] . ($billing['due'] ? ' · k vyfakturování ' . ProjectServices::priceLabel($service) : ''),
            ];
        }

        $this->notifier->renewalsDigest($rows);
        $this->services->markNotified(array_map(static fn (array $s): int => (int) $s['id'], $due));
    }
}
