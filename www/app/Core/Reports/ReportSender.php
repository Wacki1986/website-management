<?php

declare(strict_types=1);

namespace App\Core\Reports;

use App\Core\Events\EventLog;
use App\Core\Log\Logger;
use App\Core\Monitor\Notifier;
use App\Core\Notifications\MailSettings;
use App\Core\Notifications\Mailer;
use App\Core\Settings\Settings;
use App\Core\Sites\SiteRepository;
use Throwable;

/**
 * Životní cyklus reportu: koncept → (čeká na schválení) → odesláno /
 * částečně / selhalo. Cron volá `step()`: den před termínem připraví
 * reporty ke schválení, v termínu odešle ty bez schvalování.
 */
final class ReportSender
{
    /** Kolik hodin před termínem vzniká report ke schválení. */
    public const APPROVAL_LEAD_HOURS = 24;

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ReportBuilder $builder,
        private readonly ReportRenderer $renderer,
        private readonly SiteRepository $sites,
        private readonly Mailer $mailer,
        private readonly MailSettings $mailSettings,
        private readonly Settings $settings,
        private readonly EventLog $events,
        private readonly Notifier $notifier,
        private readonly Logger $logger,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Nový koncept za období — souhrn i HTML se spočítají hned, aby náhled
     * byl okamžitý. Adresáti a sekce se berou z nastavení webu.
     *
     * @param array<string, mixed> $site řádek se snapshotem
     */
    public function draft(array $site, string $kind, string $from, string $to, string $label, ?string $scheduledFor = null, string $status = 'draft', string $createdBy = '', ?string $today = null): int
    {
        $siteId = (int) $site['id'];
        $settings = $this->reports->settings($siteId);
        $sections = $settings['sections'] ?? ReportRepository::defaultSections();
        $summary = $this->builder->build($site, $from, $to, $label, $today);

        return $this->reports->create([
            'site_id' => $siteId,
            'kind' => $kind,
            'period_from' => $from,
            'period_to' => $to,
            'period_label' => $label,
            'recipients' => $this->reports->recipientEmails($siteId),
            'sections' => $sections,
            'summary' => $summary,
            'html' => $this->renderer->document($summary, $this->options($summary, '', $sections)),
            'status' => $status,
            'scheduled_for' => $scheduledFor,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Po změně poznámky nebo sekcí — přegenerovat HTML.
     *
     * Rozpracovaný report (koncept, čeká na schválení) dostane i čerstvý
     * souhrn: jinak by ukazoval data z chvíle, kdy vznikl — bez aktualizací
     * a servisu zapsaných mezitím a bez sekcí přidaných v novější verzi.
     * Odeslaný report se nepřepočítává, musí zůstat, jak ho klient dostal.
     *
     * @param array<string, mixed> $report
     */
    public function rerender(array $report, ?string $today = null): void
    {
        $summary = $report['summary'];
        $site = in_array($report['status'], ['draft', 'pending_approval'], true) ? $this->sites->findWithSnapshot((int) $report['site_id']) : null;

        if ($site !== null) {
            $summary = $this->builder->build($site, (string) $report['period_from'], (string) $report['period_to'], (string) $report['period_label'], $today);
        }

        $this->reports->update((int) $report['id'], [
            'summary' => $summary,
            'html' => $this->renderer->document($summary, $this->options($summary, (string) $report['note'], $report['sections'])),
        ]);
    }

    /**
     * Odeslání všem adresátům (nebo jen na zkoušku jedné adrese).
     *
     * @param array<string, mixed> $report
     * @param array<int, string>|null $onlyTo zkušební odeslání — stav reportu se nemění
     * @return array{ok: bool, sent: int, failed: array<string, string>}
     */
    public function send(array $report, string $who = '', ?array $onlyTo = null, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $site = $this->sites->findWithSnapshot((int) $report['site_id']);
        $test = $onlyTo !== null;
        $recipients = $test ? $onlyTo : $report['recipients'];
        $summary = $report['summary'];

        if ($site === null) {
            return ['ok' => false, 'sent' => 0, 'failed' => ['—' => 'Web už neexistuje.']];
        }

        // Rozpracovaný report odchází s čerstvým souhrnem — stejným, jaký
        // ukazuje náhled (ReportController::preview), ne s daty z chvíle,
        // kdy koncept vznikl.
        if (in_array($report['status'], ['draft', 'pending_approval'], true)) {
            $summary = $this->builder->build($site, (string) $report['period_from'], (string) $report['period_to'], (string) $report['period_label']);
        }

        if ($recipients === []) {
            if (!$test) {
                $this->reports->update((int) $report['id'], ['status' => 'failed', 'error' => 'Web nemá žádného adresáta reportu.']);
                $this->events->record((int) $site['id'], EventLog::KIND_REPORT, 'error', 'Report ' . $report['period_label'] . ' neodešel: žádný adresát', ['report_id' => (int) $report['id']], $who);
                $this->notifier->reportFailed($site, $report, 'Web nemá žádného adresáta reportu.');
            }

            return ['ok' => false, 'sent' => 0, 'failed' => ['—' => 'Web nemá žádného adresáta reportu.']];
        }

        $pixelUrl = $test ? '' : rtrim($this->appUrl, '/') . '/r/' . $report['token'] . '.gif';
        $options = $this->options($summary, (string) $report['note'], $report['sections'], $pixelUrl);
        $html = $this->renderer->document($summary, $options);
        $text = $this->renderer->text($summary, $options);
        $subject = ($test ? '[ZKOUŠKA] ' : '') . ReportTemplate::subject($summary, $options['texts'], $options['studio']['name']);

        $sent = 0;
        $failed = [];

        foreach ($recipients as $email) {
            try {
                if ($this->mailer->sendHtml($email, $subject, $html, $text)) {
                    $sent++;
                } else {
                    $failed[$email] = (string) ($this->mailer->lastError() ?? 'odeslání odmítnuto');
                }
            } catch (Throwable $e) {
                $failed[$email] = $e->getMessage();
            }
        }

        if ($test) {
            return ['ok' => $failed === [], 'sent' => $sent, 'failed' => $failed];
        }

        $status = $failed === [] ? 'sent' : ($sent > 0 ? 'partial' : 'failed');
        $error = $failed !== [] ? mb_substr(implode('; ', array_map(static fn (string $email, string $why): string => $email . ': ' . $why, array_keys($failed), $failed)), 0, 255) : '';

        $this->reports->update((int) $report['id'], [
            'status' => $status,
            'error' => $error,
            'summary' => $summary,
            'html' => $html,
            'sent_at' => $sent > 0 ? $now : null,
        ]);

        if ($sent > 0) {
            $this->reports->advanceSettings((int) $site['id'], $this->nextAfterSend((int) $site['id'], $now), $now);
            $this->events->record((int) $site['id'], EventLog::KIND_REPORT, $status === 'sent' ? 'ok' : 'warning',
                ($report['kind'] === 'manual' ? 'Report' : 'Pravidelný report') . ' ' . $report['period_label'] . ' odeslán' . ($status === 'partial' ? ' jen části adresátů' : '') . ' (' . implode(', ', array_diff($recipients, array_keys($failed))) . ')',
                ['report_id' => (int) $report['id'], 'recipients' => $recipients], $who);
        }

        if ($failed !== []) {
            $this->events->record((int) $site['id'], EventLog::KIND_REPORT, 'error', 'Report ' . $report['period_label'] . ' se nepodařilo doručit: ' . $error, ['report_id' => (int) $report['id']], $who);
            $this->notifier->reportFailed($site, $report, $error);
        }

        return ['ok' => $failed === [], 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Krok cronu: 1) den před termínem připravit reporty ke schválení,
     * 2) v termínu odeslat reporty bez schvalování a posunout termín.
     * Vrací počet odeslaných.
     */
    public function step(int $now): int
    {
        $nowText = date('Y-m-d H:i:s', $now);
        $today = date('Y-m-d', $now);
        $sent = 0;

        foreach ($this->reports->approachingApprovals($nowText, self::APPROVAL_LEAD_HOURS) as $settings) {
            $period = ReportSchedule::period((string) $settings['frequency'], (string) $settings['next_send_at']);

            if ($this->reports->openFor((int) $settings['site_id'], $period['from'], $period['to']) !== null) {
                continue;
            }

            $site = $this->sites->findWithSnapshot((int) $settings['site_id']);

            if ($site === null) {
                continue;
            }

            $id = $this->draft($site, 'scheduled', $period['from'], $period['to'], $period['label'], (string) $settings['next_send_at'], 'pending_approval', 'monitor', $today);
            $report = $this->reports->find($id);
            $this->events->record((int) $site['id'], EventLog::KIND_REPORT, 'warning', 'Report ' . $period['label'] . ' čeká na schválení, odchází ' . get_when((string) $settings['next_send_at'], $now), ['report_id' => $id]);

            if ($report !== null) {
                $this->notifier->reportPendingApproval($site, $report);
            }
        }

        foreach ($this->reports->dueSettings($nowText) as $settings) {
            $siteId = (int) $settings['site_id'];
            $period = ReportSchedule::period((string) $settings['frequency'], (string) $settings['next_send_at']);
            $next = ReportSchedule::nextSendAt((string) $settings['frequency'], (int) $settings['send_day'], (int) $settings['send_hour'], $nowText);

            if ((int) $settings['requires_approval'] === 1) {
                // Čeká se na člověka: report zůstane ve frontě, termín se
                // posune, aby cron nezakládal další.
                $site = $this->sites->findWithSnapshot($siteId);

                if ($site !== null && $this->reports->openFor($siteId, $period['from'], $period['to']) === null) {
                    $id = $this->draft($site, 'scheduled', $period['from'], $period['to'], $period['label'], (string) $settings['next_send_at'], 'pending_approval', 'monitor', $today);
                    $report = $this->reports->find($id);

                    if ($report !== null) {
                        $this->notifier->reportPendingApproval($site, $report);
                    }
                }

                $this->reports->advanceSettings($siteId, $next);

                continue;
            }

            $site = $this->sites->findWithSnapshot($siteId);

            if ($site === null) {
                $this->reports->advanceSettings($siteId, $next);

                continue;
            }

            try {
                $existing = $this->reports->openFor($siteId, $period['from'], $period['to']);
                $id = $existing !== null ? (int) $existing['id'] : $this->draft($site, 'scheduled', $period['from'], $period['to'], $period['label'], (string) $settings['next_send_at'], 'draft', 'monitor', $today);
                $report = $this->reports->find($id);

                if ($report !== null) {
                    $result = $this->send($report, 'monitor', null, $nowText);
                    $sent += $result['sent'] > 0 ? 1 : 0;
                }
            } catch (Throwable $e) {
                $this->logger->error('Report: odeslání selhalo', ['site' => $siteId, 'error' => $e->getMessage()]);
            }

            // Termín se posune vždy — i po selhání (to je vidět ve frontě jako Nedoručeno).
            $this->reports->advanceSettings($siteId, $next);
        }

        return $sent;
    }

    /** Po ručním odeslání: další termín podle nastavení (ruční režim žádný nemá). */
    private function nextAfterSend(int $siteId, string $now): ?string
    {
        $settings = $this->reports->settings($siteId);

        if ($settings === null || (int) $settings['is_active'] !== 1) {
            return null;
        }

        return ReportSchedule::nextSendAt((string) $settings['frequency'], (int) $settings['send_day'], (int) $settings['send_hour'], $now);
    }

    /**
     * Volby vykreslení sdílené náhledem i odesláním.
     *
     * @param array<string, mixed> $summary
     * @param array<int, string> $sections
     * @return array<string, mixed>
     */
    public function options(array $summary, string $note, array $sections, string $pixelUrl = '', bool $mobile = false): array
    {
        $mail = $this->mailSettings->current();
        $logo = $this->settings->get('mail_logo_url');

        return [
            'note' => $note,
            'sections' => $sections,
            'pixelUrl' => $pixelUrl,
            'mobile' => $mobile,
            'logoUrl' => $logo !== '' ? $logo : rtrim($this->appUrl, '/') . '/assets/img/logo-mediagrafik.svg',
            'studio' => [
                'name' => (string) ($mail['from_name'] !== '' ? $mail['from_name'] : 'MEDIAGRAFIK'),
                'email' => (string) $mail['from_address'],
                'phone' => $this->settings->get('report_phone'),
            ],
            'contactUrl' => (string) $mail['from_address'] !== '' ? 'mailto:' . $mail['from_address'] : '',
            'texts' => (new ReportTemplate($this->settings))->texts(),
        ];
    }
}
