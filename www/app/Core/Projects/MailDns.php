<?php

declare(strict_types=1);

namespace App\Core\Projects;

use App\Core\Monitor\MailDnsCheck;
use App\Core\Monitor\MonitorSettings;
use App\Core\Monitor\Notifier;

/**
 * Krok cronu: DNS pošty u domén projektů jednou denně (zapojený
 * v `Kernel::monitor()` přes `addStep`).
 *
 * Upozorňuje jen na změnu — nový problém, jiné MX, přepsaný SPF nebo
 * DMARC (`MailDnsCheck::fingerprint`). Stav, který trvá, je vidět u domény
 * a e-mail by o něm chodil každý den zbytečně. První kontrola nové domény
 * je tichá: hlásí se změny, ne výchozí stav. Výpadek DNS (`ok = false`)
 * se neporovnává, ať jedna nepovedená odpověď nepošle dva e-maily.
 */
final class MailDns
{
    public function __construct(
        private readonly ProjectServices $services,
        private readonly MailDnsCheck $check,
        private readonly Notifier $notifier,
        private readonly MonitorSettings $settings,
    ) {
    }

    /** @return int počet zkontrolovaných domén */
    public function step(int $now, float $deadline): int
    {
        $stamp = date('Y-m-d H:i:s', $now);
        $changes = [];
        $checked = 0;

        foreach ($this->services->domainsForMailDns($stamp) as $service) {
            if (microtime(true) > $deadline - 5) {
                break;
            }

            $change = $this->checkOne($service, $stamp);
            $checked++;

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        if ($changes !== [] && $this->settings->bool('rule_maildns_on')) {
            $this->notifier->mailDnsChanged($changes);
        }

        return $checked;
    }

    /**
     * Kontrola jedné domény (i tlačítko „Zkontrolovat DNS teď").
     *
     * @param array<string, mixed> $service řádek domény (s `project_name`, když se má hlásit změna)
     * @return array{label: string, value: string}|null změna k nahlášení
     */
    public function checkOne(array $service, string $now): ?array
    {
        $previous = ProjectServices::mailDns($service);
        $result = $this->check->check((string) $service['name']);
        $this->services->saveMailDns((int) $service['id'], $result, $now);

        if ($previous === null || !($previous['ok'] ?? false) || !$result['ok'] || MailDnsCheck::fingerprint($previous) === MailDnsCheck::fingerprint($result)) {
            return null;
        }

        $summary = MailDnsCheck::summary($result);
        $mxChanged = MailDnsCheck::fingerprint(['mx' => $result['mx']]) !== MailDnsCheck::fingerprint(['mx' => $previous['mx'] ?? []]);

        return [
            'label' => ($service['project_name'] ?? '') . ' · ' . $service['name'],
            'value' => $summary['label'] . ($mxChanged ? ' · pošta se přesunula (MX: ' . ($result['mx'] !== [] ? implode(', ', $result['mx']) : 'žádné') . ')' : ''),
        ];
    }
}
