<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\UserRepository;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Reports\ReportRepository;
use App\Core\Reports\ReportSchedule;
use App\Core\Sites\SiteRepository;

/**
 * Klientské reporty: nastavení u webu (karta v Nastavení webu), záložka
 * Reporty s historií, fronta napříč weby (`reporty-*.html`) a náhled
 * před odesláním (`nahled-reportu*.html`).
 *
 * „Upravit šablonu" z návrhu je v2 — v1 tlačítko není.
 */
final class ReportController extends Controller
{
    use SiteHeaderTrait;

    public const NOTE_MAX = 400;

    // -----------------------------------------------------------------
    // Web: záložka Reporty a nastavení
    // -----------------------------------------------------------------

    public function site(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $reports = $this->kernel->reports();
        $settings = $reports->settings((int) $id);
        $recipients = $reports->recipients((int) $id);
        $rows = [];

        foreach ($reports->forSite((int) $id) as $report) {
            $rows[] = self::row($report);
        }

        return $this->view('sites/reports', $this->header($site, 'reporty') + [
            'rows' => $rows,
            'settingsNote' => self::settingsNote($settings, $recipients),
            'hasRecipients' => $recipients !== [],
            'reportsUrl' => get_url('reporty'),
        ]);
    }

    /** Karta „Klientské reporty" v Nastavení webu — uložení plánu. */
    public function saveSettings(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $request = $this->request();
        $reports = $this->kernel->reports();
        $current = $reports->settings((int) $id);

        $reports->saveSettings((int) $id, [
            'is_active' => $request->bool('is_active'),
            'frequency' => $request->string('frequency'),
            'send_day' => $request->int('send_day', 1) ?? 1,
            'send_hour' => $request->int('send_hour', 6) ?? 6,
            'requires_approval' => $request->bool('requires_approval'),
            'sections' => $current['sections'] ?? ReportRepository::defaultSections(),
        ], date('Y-m-d H:i:s'));

        $saved = $reports->settings((int) $id);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_REPORT, true,
            'Nastavení reportů: ' . ($saved !== null && (int) $saved['is_active'] === 1 ? ReportSchedule::describe((string) $saved['frequency'], (int) $saved['send_day'], (int) $saved['send_hour']) : 'vypnuto'));

        $warning = $saved !== null && (int) $saved['is_active'] === 1 && $reports->recipients((int) $id) === [] ? ' Doplňte adresu příjemce, jinak report nemá kam odejít.' : '';

        return $this->redirectWithFlash('weby/' . $id . '/nastaveni', 'Nastavení reportů je uložené.' . $warning, $warning !== '' ? 'warning' : 'success');
    }

    public function addRecipient(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $email = mb_strtolower(trim($this->request()->string('email')));
        $label = $this->request()->string('label') === 'kopie' ? 'kopie' : 'klient';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirectWithFlash('weby/' . $id . '/nastaveni', 'Zadejte platnou e-mailovou adresu.', 'error');
        }

        $this->kernel->reports()->addRecipient((int) $id, $email, $label);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_REPORT, true, 'Přidán adresát reportu ' . $email);

        return $this->redirectWithFlash('weby/' . $id . '/nastaveni', 'Adresa ' . $email . ' je přidaná.');
    }

    public function removeRecipient(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $this->kernel->reports()->removeRecipient((int) $id, $this->request()->int('id') ?? 0);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_REPORT, true, 'Odebrán adresát reportu');

        return $this->redirectWithFlash('weby/' . $id . '/nastaveni', 'Adresa je odebraná.');
    }

    /**
     * „Náhled reportu" u naplánovaného: koncept za nadcházející období
     * vznikne on-the-fly (nebo se otevře už existující) a přesměruje se
     * do náhledu.
     */
    public function previewForSite(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $reports = $this->kernel->reports();
        $settings = $reports->settings((int) $id);
        $now = date('Y-m-d H:i:s');

        if ($settings !== null && (int) $settings['is_active'] === 1 && $settings['frequency'] !== 'manual' && $settings['next_send_at'] !== null) {
            $period = ReportSchedule::period((string) $settings['frequency'], (string) $settings['next_send_at']);
            $kind = 'scheduled';
            $scheduledFor = (string) $settings['next_send_at'];
        } else {
            $period = ReportSchedule::period('manual', $now);
            $kind = 'manual';
            $scheduledFor = null;
        }

        $existing = $reports->openFor((int) $id, $period['from'], $period['to']);
        $reportId = $existing !== null ? (int) $existing['id']
            : $this->kernel->reportSender()->draft($site, $kind, $period['from'], $period['to'], $period['label'], $scheduledFor, 'draft', $this->actorName());

        return $this->redirect('reporty/' . $reportId . '/nahled');
    }

    /** „Odeslat report teď" — ruční report za posledních 30 dní, napřed náhled. */
    public function sendNow(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $period = ReportSchedule::period('manual', date('Y-m-d H:i:s'));
        $existing = $this->kernel->reports()->openFor((int) $id, $period['from'], $period['to']);
        $reportId = $existing !== null ? (int) $existing['id']
            : $this->kernel->reportSender()->draft($site, 'manual', $period['from'], $period['to'], $period['label'], null, 'draft', $this->actorName());

        return $this->redirect('reporty/' . $reportId . '/nahled');
    }

    // -----------------------------------------------------------------
    // Fronta
    // -----------------------------------------------------------------

    public function index(): Response
    {
        $request = $this->request();
        $status = in_array($request->string('stav'), ['pending', 'sent', 'failed', 'all'], true) ? $request->string('stav') : 'pending';
        $q = $request->string('q');
        $period = preg_match('/^\d{4}-\d{2}$/', $request->string('obdobi')) === 1 ? $request->string('obdobi') : '';
        $reports = $this->kernel->reports();
        $now = date('Y-m-d H:i:s');
        $counts = $reports->counts($now);

        // Naplánované = aktivní nastavení s termínem, u kterých ještě
        // report za to období nevznikl, + rozpracované a čekající reporty.
        $scheduled = [];

        if ($status === 'pending' || $status === 'all') {
            foreach ($reports->activeSettings() as $settings) {
                if ($settings['next_send_at'] === null || $settings['frequency'] === 'manual') {
                    continue;
                }

                if ($q !== '' && mb_stripos((string) $settings['site_name'] . ' ' . (string) ($settings['client_name'] ?? ''), $q) === false) {
                    continue;
                }

                if ($period !== '' && !str_starts_with((string) $settings['next_send_at'], $period)) {
                    continue;
                }

                $per = ReportSchedule::period((string) $settings['frequency'], (string) $settings['next_send_at']);

                if ($reports->openFor((int) $settings['site_id'], $per['from'], $per['to']) !== null) {
                    continue;
                }

                $scheduled[] = self::scheduledRow($settings, $per, $now);
            }
        }

        $rows = [];

        foreach ($reports->all(['status' => $status, 'q' => $q, 'period' => $period]) as $report) {
            $rows[] = self::row($report, $now);
        }

        // Skupiny podle návrhu: Odejde do týdne / Později / Čeká na
        // schválení / Odesláno / Nedoručeno.
        $groups = [];

        foreach (array_merge($scheduled, $rows) as $row) {
            $groups[$row['group']][] = $row;
        }

        $order = ['Čeká na schválení' => 0, 'Rozpracované' => 1, 'Odejde do týdne' => 2, 'Později' => 3, 'Nedoručeno' => 4, 'Odesláno' => 5];
        uksort($groups, static fn (string $a, string $b): int => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));

        $scheduledCount = count($scheduled) + $counts['pending'] + $counts['drafts'];
        $pendingNext = $counts['pending_next'] !== null ? get_when((string) $counts['pending_next']) : null;

        return $this->view('reports/index', [
            'title' => 'Reporty',
            'groups' => $groups,
            'status' => $status,
            'q' => $q,
            'period' => $period,
            'periods' => $reports->periods(),
            'counts' => $counts + [
                'scheduled' => $scheduledCount,
                'sitesWithReport' => $reports->countActive(),
                'openRate' => $counts['sent_90d'] > 0 ? (int) round($counts['opened_90d'] / $counts['sent_90d'] * 100) : null,
            ],
            'pendingNote' => $counts['pending'] > 0 ? ($pendingNext !== null ? 'odchází ' . $pendingNext : 'čeká na kontrolu') : 'nic nečeká',
            'failedNote' => $counts['failed'] > 0 ? mb_substr($counts['failed_error'], 0, 60) : 'všechno doručeno',
            'meta' => get_count($scheduledCount, 'naplánovaný', 'naplánované', 'naplánovaných') . ' · '
                . get_count($counts['sent_month'], 'odeslaný v tomto měsíci', 'odeslané v tomto měsíci', 'odeslaných v tomto měsíci') . ' · '
                . get_count($counts['failed'], 'nedoručený', 'nedoručené', 'nedoručených'),
            'shown' => count($scheduled) + count($rows),
            'canSendScheduled' => $reports->pendingApproval(date('Y-m-d H:i:s', strtotime('+7 days'))) !== [],
        ]);
    }

    /** „Odeslat naplánované" — všechny čekající na schválení s termínem do týdne. */
    public function sendScheduled(): Response
    {
        $sender = $this->kernel->reportSender();
        $sent = 0;
        $failed = 0;

        foreach ($this->kernel->reports()->pendingApproval(date('Y-m-d H:i:s', strtotime('+7 days'))) as $report) {
            $result = $sender->send($report, $this->actorName());
            $result['ok'] ? $sent++ : $failed++;
        }

        $this->kernel->audit()->record(null, '', AuditLog::ACTION_REPORT, $failed === 0, 'Hromadné odeslání naplánovaných reportů: ' . $sent . ' odesláno, ' . $failed . ' selhalo');

        if ($sent === 0 && $failed === 0) {
            return $this->redirectWithFlash('reporty', 'Ve frontě není žádný report ke schválení s termínem do týdne.', 'warning');
        }

        return $this->redirectWithFlash('reporty', get_count($sent, 'report odeslán', 'reporty odeslány', 'reportů odesláno') . ($failed > 0 ? ', ' . get_count($failed, 'selhal', 'selhaly', 'selhalo') . ' — viz Problémy' : '') . '.', $failed > 0 ? 'warning' : 'success');
    }

    // -----------------------------------------------------------------
    // Náhled a odeslání
    // -----------------------------------------------------------------

    public function preview(string $id): Response
    {
        $report = $this->reportOr404((int) $id);
        $mobile = $this->request()->string('zobrazeni') === 'mobil';
        $sender = $this->kernel->reportSender();
        $options = $sender->options($report['summary'], (string) $report['note'], $report['sections'], '', $mobile);
        $sentState = in_array($report['status'], ['sent', 'partial', 'failed'], true);
        $me = $this->kernel->auth()->current();

        $sections = [];

        foreach (ReportRepository::SECTIONS as $key => $section) {
            $sections[] = $section + ['key' => $key, 'on' => in_array($key, $report['sections'], true)];
        }

        $mail = $this->kernel->mailSettings()->current();

        return $this->view('reports/preview', [
            'title' => 'Náhled reportu',
            'report' => $report,
            'row' => self::row($report),
            'mobile' => $mobile,
            'emailBody' => $this->kernel->reportRenderer()->body($report['summary'], $options),
            'subject' => (string) $report['summary']['subject'],
            'sections' => $sections,
            'noteMax' => self::NOTE_MAX,
            'quickNotes' => ['zrychlení webu' => 'Tento měsíc jsme navíc zrychlili načítání webu.', 'oprava formuláře' => 'Opravili jsme kontaktní formulář, zprávy zase chodí správně.', 'plánovaná odstávka' => 'V nejbližších týdnech plánujeme krátkou odstávku kvůli údržbě hostingu — dáme vědět předem.'],
            'sentState' => $sentState,
            'meta' => implode(' · ', array_filter([
                (string) ($report['client_name'] ?? $report['site_name']),
                $report['recipients'] !== [] ? implode(', ', $report['recipients']) : 'bez adresáta',
                $sentState ? (($report['sent_at'] ?? null) !== null ? 'odesláno ' . get_when((string) $report['sent_at']) : 'neodesláno') : ($report['scheduled_for'] !== null ? 'odchází ' . get_when((string) $report['scheduled_for']) : 'ruční odeslání'),
            ])),
            'summary' => [
                'Období' => (string) $report['period_label'] . ' (' . get_czech_date((string) $report['period_from']) . ' – ' . get_czech_date((string) $report['period_to']) . ')',
                'Příjemce' => $report['recipients'] !== [] ? implode(', ', $report['recipients']) : '— žádný —',
                'Odesílatel' => (string) $mail['from_address'] !== '' ? (string) $mail['from_address'] : 'nenastaveno (Nastavení → Odchozí pošta)',
                'Odchází' => $sentState
                    ? (($report['sent_at'] ?? null) !== null ? 'odesláno ' . get_when((string) $report['sent_at']) : 'neodesláno')
                    : ($report['scheduled_for'] !== null ? get_when((string) $report['scheduled_for']) : 'po kliknutí na Odeslat klientovi'),
            ],
            'testEmail' => $me !== null ? (string) ($me['email'] ?? '') : '',
            'siteUrl' => get_url('weby/' . (int) $report['site_id'] . '/reporty'),
        ]);
    }

    public function saveNote(string $id): Response
    {
        $report = $this->editableOr404((int) $id);
        $note = mb_substr(trim($this->request()->string('note')), 0, self::NOTE_MAX);
        $this->kernel->reports()->update((int) $id, ['note' => $note]);
        $this->kernel->reportSender()->rerender($this->reportOr404((int) $id));

        return $this->redirectWithFlash('reporty/' . $id . '/nahled', $note === '' ? 'Poznámka je vymazaná.' : 'Poznámka je uložená.');
    }

    public function saveSections(string $id): Response
    {
        $report = $this->editableOr404((int) $id);
        $chosen = (array) ($_POST['sections'] ?? []);
        $sections = array_values(array_intersect(array_keys(ReportRepository::SECTIONS), array_map('strval', $chosen)));
        $this->kernel->reports()->update((int) $id, ['sections' => $sections]);
        $this->kernel->reportSender()->rerender($this->reportOr404((int) $id));

        // Sekce se uloží i jako výchozí pro další reporty webu.
        $settings = $this->kernel->reports()->settings((int) $report['site_id']);

        if ($settings !== null) {
            $this->kernel->reports()->saveSettings((int) $report['site_id'], [
                'is_active' => (int) $settings['is_active'] === 1,
                'frequency' => (string) $settings['frequency'],
                'send_day' => (int) $settings['send_day'],
                'send_hour' => (int) $settings['send_hour'],
                'requires_approval' => (int) $settings['requires_approval'] === 1,
                'sections' => $sections,
            ], date('Y-m-d H:i:s'));
        }

        return $this->redirectWithFlash('reporty/' . $id . '/nahled', 'Sekce reportu jsou uložené.');
    }

    /** „Poslat sobě na zkoušku" — na e-mail přihlášeného, stav reportu se nemění. */
    public function sendTest(string $id): Response
    {
        $report = $this->reportOr404((int) $id);
        $me = $this->kernel->auth()->current();
        $email = (string) ($me['email'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirectWithFlash('reporty/' . $id . '/nahled', 'Váš účet nemá e-mail — doplňte ho v Nastavení → Účet.', 'error');
        }

        $result = $this->kernel->reportSender()->send($report, $this->actorName(), [$email]);

        if (!$result['ok']) {
            return $this->redirectWithFlash('reporty/' . $id . '/nahled', 'Zkušební e-mail neodešel: ' . implode('; ', $result['failed']), 'error');
        }

        return $this->redirectWithFlash('reporty/' . $id . '/nahled', 'Zkušební report odešel na ' . $email . '.');
    }

    public function send(string $id): Response
    {
        $report = $this->reportOr404((int) $id);

        if ($report['status'] === 'sent') {
            return $this->redirectWithFlash('reporty/' . $id . '/nahled', 'Tenhle report už klientovi odešel.', 'warning');
        }

        $result = $this->kernel->reportSender()->send($report, $this->actorName());
        $this->kernel->audit()->record((int) $report['site_id'], (string) $report['site_name'], AuditLog::ACTION_REPORT, $result['ok'],
            'Report ' . $report['period_label'] . ($result['ok'] ? ' odeslán' : ' neodešel: ' . implode('; ', $result['failed'])));

        if (!$result['ok']) {
            return $this->redirectWithFlash('reporty/' . $id . '/nahled', 'Report se nepodařilo doručit: ' . implode('; ', array_map(static fn (string $e, string $w): string => $e . ' (' . $w . ')', array_keys($result['failed']), $result['failed'])), 'error');
        }

        return $this->redirectWithFlash('reporty/' . $id . '/nahled', 'Report odešel na ' . implode(', ', $report['recipients']) . '. Kopie je uložená u webu v záložce Reporty.');
    }

    /** Uložené HTML odeslaného reportu — přesně to, co klient dostal. */
    public function show(string $id): Response
    {
        $report = $this->reportOr404((int) $id);

        return Response::html((string) $report['html'])->withHeader('X-Robots-Tag', 'noindex');
    }

    public function delete(string $id): Response
    {
        $report = $this->editableOr404((int) $id);
        $this->kernel->reports()->delete((int) $id);
        $this->kernel->audit()->record((int) $report['site_id'], (string) $report['site_name'], AuditLog::ACTION_REPORT, true, 'Zahozen koncept reportu ' . $report['period_label']);

        return $this->redirectWithFlash('reporty', 'Koncept reportu je zahozený.');
    }

    // -----------------------------------------------------------------

    /**
     * Řádek fronty/historie z reportu.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public static function row(array $report, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $status = (string) $report['status'];
        $opened = ($report['opened_at'] ?? null) !== null;

        [$tone, $label, $group] = match ($status) {
            'sent' => [$opened ? 'ok' : 'ok', $opened ? 'Otevřeno klientem' : 'Odesláno', 'Odesláno'],
            'partial' => ['warning', 'Částečně doručeno', 'Nedoručeno'],
            'failed' => ['error', 'Selhalo', 'Nedoručeno'],
            'pending_approval' => ['warning', 'Čeká na schválení', 'Čeká na schválení'],
            default => ['muted', 'Rozpracovaný', 'Rozpracované'],
        };

        $when = $status === 'sent' || $status === 'partial' || $status === 'failed'
            ? ['main' => get_when((string) ($report['sent_at'] ?? $report['created_at'])), 'sub' => $opened ? 'otevřen ' . get_when((string) $report['opened_at']) : ($status === 'sent' ? 'zatím neotevřen' : ''), 'tone' => '']
            : ($report['scheduled_for'] !== null
                ? ['main' => get_when((string) $report['scheduled_for']), 'sub' => get_countdown((string) $report['scheduled_for'], strtotime($now)), 'tone' => strtotime((string) $report['scheduled_for']) - strtotime($now) < 86400 ? 'warning' : '']
                : ['main' => 'ručně', 'sub' => 'po schválení', 'tone' => '']);

        return [
            'id' => (int) $report['id'],
            'siteId' => (int) $report['site_id'],
            'siteName' => (string) ($report['site_name'] ?? ''),
            'clientName' => (string) ($report['client_name'] ?? ''),
            'host' => isset($report['site_url']) ? SiteRepository::host((string) $report['site_url']) : '',
            'period' => (string) $report['period_label'],
            'summaryLine' => $status === 'failed' || $status === 'partial' ? 'odeslání selhalo – ' . (string) $report['error'] : (string) ($report['summary']['summaryLine'] ?? ''),
            'recipients' => implode(', ', $report['recipients']),
            'status' => $status,
            'tone' => $tone,
            'label' => $label,
            'group' => $group,
            'when' => $when,
            'date' => get_czech_date((string) ($report['sent_at'] ?? $report['scheduled_for'] ?? $report['created_at'])),
            'openedLabel' => $status === 'sent' ? ($opened ? 'otevřen ' . get_when((string) $report['opened_at']) : 'neotevřen') : '—',
            'previewUrl' => get_url('reporty/' . (int) $report['id'] . '/nahled'),
            'action' => $status === 'pending_approval' || $status === 'draft' ? 'Zkontrolovat' : 'Náhled',
            'isProblem' => $status === 'failed',
        ];
    }

    /**
     * Řádek pro naplánovaný report, který ještě nevznikl.
     *
     * @param array<string, mixed> $settings
     * @param array{from: string, to: string, label: string} $period
     * @return array<string, mixed>
     */
    private static function scheduledRow(array $settings, array $period, string $now): array
    {
        $at = (string) $settings['next_send_at'];
        $soon = strtotime($at) - strtotime($now) <= 7 * 86400;

        return [
            'id' => 0,
            'siteId' => (int) $settings['site_id'],
            'siteName' => (string) $settings['site_name'],
            'clientName' => (string) ($settings['client_name'] ?? ''),
            'host' => SiteRepository::host((string) $settings['url']),
            'period' => $period['label'],
            'summaryLine' => '',
            'recipients' => '',
            'status' => 'scheduled',
            'tone' => 'muted',
            'label' => 'Naplánováno',
            'group' => $soon ? 'Odejde do týdne' : 'Později',
            'when' => ['main' => get_when($at), 'sub' => get_countdown($at, strtotime($now)), 'tone' => ''],
            'date' => get_czech_date($at),
            'openedLabel' => '—',
            'previewUrl' => get_url('weby/' . (int) $settings['site_id'] . '/reporty/nahled'),
            'action' => 'Náhled',
            'isProblem' => false,
        ];
    }

    /**
     * Poznámka karty: „Měsíčně, 1. den v měsíci → info@…".
     *
     * @param array<string, mixed>|null $settings
     * @param array<int, array<string, mixed>> $recipients
     */
    public static function settingsNote(?array $settings, array $recipients): string
    {
        if ($settings === null || (int) $settings['is_active'] !== 1) {
            return 'Reporty jsou vypnuté — zapněte je v Nastavení webu.';
        }

        $to = array_map(static fn (array $r): string => (string) $r['email'], array_filter($recipients, static fn (array $r): bool => $r['label'] === 'klient'));

        return ReportSchedule::describe((string) $settings['frequency'], (int) $settings['send_day'], (int) $settings['send_hour'])
            . ' → ' . ($to !== [] ? implode(', ', $to) : 'bez adresáta');
    }

    private function actorName(): string
    {
        $user = $this->kernel->auth()->current();

        return $user !== null ? UserRepository::displayName($user) : '';
    }

    /** @return array<string, mixed> */
    private function reportOr404(int $id): array
    {
        $report = $this->kernel->reports()->find($id);

        if ($report === null) {
            throw HttpException::notFound('Report neexistuje.');
        }

        return $report;
    }

    /** Jen koncept nebo čekající — odeslaný se už neupravuje. @return array<string, mixed> */
    private function editableOr404(int $id): array
    {
        $report = $this->reportOr404($id);

        if (!in_array($report['status'], ['draft', 'pending_approval'], true)) {
            throw HttpException::forbidden('Odeslaný report se už neupravuje.');
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function siteOr404(int $id): array
    {
        $site = $this->kernel->sites()->findWithSnapshot($id);

        if ($site === null || $site['removed_at'] !== null) {
            throw HttpException::notFound('Web neexistuje nebo byl odebrán z monitoringu.');
        }

        return $site;
    }
}
