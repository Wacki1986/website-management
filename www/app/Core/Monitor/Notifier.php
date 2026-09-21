<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Log\Logger;
use App\Core\Notifications\EmailMessage;
use App\Core\Notifications\Mailer;
use App\Core\Notifications\PushNotifier;
use App\Core\Sites\SiteRepository;
use Throwable;

/**
 * Upozornění studia: e-mail na adresy z Nastavení a push na telefon.
 *
 * Nikdy nic nehází — volá se z cronu i z obsluhy tlačítka a selhání pošty
 * nesmí zastavit kontrolu dalších webů. Push má vlastní tlumení
 * (`PushNotifier`), e-mail se posílá na každý nový alert.
 */
final class Notifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly PushNotifier $push,
        private readonly MonitorSettings $settings,
        private readonly Logger $logger,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $alert
     */
    public function alertOpened(array $site, array $alert): void
    {
        $group = self::pushGroup((string) $alert['type']);
        $path = 'weby/' . (int) $site['id'];

        $this->push->send($group, $site['name'] . ': ' . $alert['title'], (string) $alert['body'], $path, onceKey: 'alert-' . (int) $alert['id']);

        $message = EmailMessage::make($alert['title'])
            ->pill($alert['severity'] === 'error' ? 'Kritický alert' : 'Upozornění', $alert['severity'] === 'error' ? 'danger' : 'warning')
            ->paragraph((string) $alert['body'])
            ->infoBox([
                'Web' => (string) $site['name'] . ' · ' . SiteRepository::host((string) $site['url']),
                'Klient' => (string) ($site['client_name'] ?? '—'),
                'Od' => get_when((string) $alert['opened_at']),
                'Pravidlo' => (string) $alert['rule_label'],
            ])
            ->button('Otevřít detail webu', rtrim($this->appUrl, '/') . '/' . $path)
            ->footerReason('Tenhle e-mail chodí na adresy z Nastavení → Monitoring.');

        $this->mail(($alert['severity'] === 'error' ? '🔴 ' : '🟠 ') . $site['name'] . ' — ' . $alert['title'], $message);
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $alert
     */
    public function alertResolved(array $site, array $alert, string $note): void
    {
        $this->push->send(self::pushGroup((string) $alert['type']), $site['name'] . ': vyřešeno', $note, 'weby/' . (int) $site['id'], onceKey: 'resolved-' . (int) $alert['id']);

        $message = EmailMessage::make('Vyřešeno: ' . $alert['title'])
            ->pill('Vyřešeno', 'success')
            ->paragraph($note)
            ->infoBox(['Web' => (string) $site['name'], 'Trvalo' => get_duration((string) $alert['opened_at'], date('Y-m-d H:i:s'))])
            ->button('Otevřít detail webu', rtrim($this->appUrl, '/') . '/weby/' . (int) $site['id']);

        $this->mail('🟢 ' . $site['name'] . ' — vyřešeno: ' . $alert['title'], $message);
    }

    /**
     * Ranní souhrn čekajících aktualizací.
     *
     * @param array<int, array{name: string, url: string, id: int, plugins: int, core: ?string}> $sites
     */
    public function updatesDigest(array $sites): void
    {
        if ($sites === []) {
            return;
        }

        $total = array_sum(array_map(static fn (array $s): int => $s['plugins'] + ($s['core'] !== null ? 1 : 0), $sites));
        $this->push->send('updates', 'Čekající aktualizace', get_count($total, 'aktualizace', 'aktualizace', 'aktualizací') . ' na ' . get_count(count($sites), 'webu', 'webech', 'webech'), 'weby?stav=attention', onceKey: 'digest-' . date('Y-m-d'), onceMinutes: 1440);

        $rows = [];

        foreach ($sites as $site) {
            $rows[$site['name']] = get_count($site['plugins'], 'plugin', 'pluginy', 'pluginů') . ($site['core'] !== null ? ' · WordPress → ' . $site['core'] : '');
        }

        $message = EmailMessage::make('Ranní souhrn aktualizací')
            ->paragraph(get_count($total, 'aktualizace čeká', 'aktualizace čekají', 'aktualizací čeká') . ' na ' . get_count(count($sites), 'webu', 'webech', 'webech') . '.')
            ->infoBox($rows)
            ->button('Otevřít weby', rtrim($this->appUrl, '/') . '/weby?stav=attention')
            ->footerReason('Souhrn chodí jednou denně za weby, které mají zapnuté hlídání aktualizací.');

        $this->mail('Čekající aktualizace · ' . get_count($total, 'aktualizace', 'aktualizace', 'aktualizací'), $message);
    }

    /**
     * Report čeká na schválení (den před termínem) — push + e-mail s odkazem
     * na náhled, aby ho někdo ze studia zkontroloval a odeslal.
     *
     * @param array<string, mixed> $site
     * @param array<string, mixed> $report
     */
    public function reportPendingApproval(array $site, array $report): void
    {
        $path = 'reporty/' . (int) $report['id'] . '/nahled';
        $when = get_when((string) $report['scheduled_for']);

        $this->push->send('reports', 'Report čeká na schválení', $site['name'] . ' · ' . $report['period_label'] . ' · odchází ' . $when, $path, onceKey: 'report-pending-' . (int) $report['id']);

        $message = EmailMessage::make('Report čeká na schválení')
            ->pill('Ke kontrole', 'warning')
            ->paragraph('Klientský report webu ' . $site['name'] . ' za období ' . $report['period_label'] . ' je připravený. Web má zapnuté schvalování, takže neodejde, dokud ho v aplikaci nezkontrolujete a neodešlete.')
            ->infoBox([
                'Web' => (string) $site['name'] . ' · ' . SiteRepository::host((string) $site['url']),
                'Období' => (string) $report['period_label'],
                'Plánované odeslání' => $when,
            ])
            ->button('Otevřít náhled reportu', rtrim($this->appUrl, '/') . '/' . $path)
            ->footerReason('Tenhle e-mail chodí na adresy z Nastavení → Monitoring.');

        $this->mail('📄 ' . $site['name'] . ' — report ke schválení', $message);
    }

    /**
     * Report se nepodařilo doručit (adresa neexistuje, SMTP odmítlo).
     *
     * @param array<string, mixed> $site
     * @param array<string, mixed> $report
     */
    public function reportFailed(array $site, array $report, string $error): void
    {
        $path = 'reporty/' . (int) $report['id'] . '/nahled';
        $this->push->send('reports', 'Report se nepodařilo doručit', $site['name'] . ': ' . $error, $path, onceKey: 'report-failed-' . (int) $report['id']);

        $message = EmailMessage::make('Report se nepodařilo doručit')
            ->pill('Nedoručeno', 'danger')
            ->paragraph('Report webu ' . $site['name'] . ' za období ' . $report['period_label'] . ' neodešel: ' . $error)
            ->paragraph('Zkontrolujte adresy příjemců u webu a odešlete report znovu z náhledu.')
            ->button('Otevřít náhled reportu', rtrim($this->appUrl, '/') . '/' . $path)
            ->footerReason('Tenhle e-mail chodí na adresy z Nastavení → Monitoring.');

        $this->mail('🔴 ' . $site['name'] . ' — report nedoručen', $message);
    }

    public function cronFailed(string $error): void
    {
        $this->push->send('ops', 'Monitor selhal', $error, 'nastaveni/monitoring', onceKey: 'cron-' . date('Y-m-d-H'));
        $this->mail('Monitor selhal', EmailMessage::make('Monitor selhal')->paragraph($error)->button('Nastavení monitoru', rtrim($this->appUrl, '/') . '/nastaveni/monitoring'));
    }

    private function mail(string $subject, EmailMessage $message): void
    {
        foreach ($this->settings->alertEmails() as $email) {
            try {
                if (!$this->mailer->sendMessage($email, $subject, $message)) {
                    $this->logger->warning('E-mail s alertem neodešel', ['to' => $email, 'error' => $this->mailer->lastError()]);
                }
            } catch (Throwable $e) {
                $this->logger->warning('E-mail s alertem selhal', ['to' => $email, 'error' => $e->getMessage()]);
            }
        }
    }

    private static function pushGroup(string $type): string
    {
        return match ($type) {
            'ssl_expiring', 'ssl_expired', 'domain_expiring' => 'ssl',
            'updates' => 'updates',
            default => 'sites',
        };
    }
}
