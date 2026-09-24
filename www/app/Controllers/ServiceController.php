<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\UserRepository;
use App\Core\Events\EventLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Monitor\DbSupport;
use App\Core\Monitor\PhpSupport;
use App\Core\Monitor\PluginDirectory;
use App\Core\Service\ServiceChecklists;
use App\Core\Service\ServiceSchedule;

/**
 * Detail webu — záložka Servis (návrh `detail-webu-servis.html`):
 * plán servisu, nadcházející termíny, historie provedených servisů,
 * zápis nového a úprava zapsaného (text i checklist úkolů z Nastavení →
 * Servis). Hlavičku záložek sdílí se `SiteController::header()`.
 */
final class ServiceController extends Controller
{
    use SiteHeaderTrait;

    public function show(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $today = date('Y-m-d');
        $service = $this->kernel->service();
        $plan = $service->plan((int) $id);
        $values = $this->planValues($plan);
        $upcoming = [];

        if ($values['first_date'] !== '') {
            foreach (ServiceSchedule::upcoming($values['first_date'], $values['frequency'], $today) as $i => $date) {
                $days = ServiceSchedule::daysUntil($date, $today);
                $upcoming[] = ['date' => get_czech_date($date), 'countdown' => ServiceSchedule::countdown($date, $today), 'tone' => $days < 0 ? 'error' : ($i === 0 ? 'active' : '')];
            }
        }

        $next = $plan !== null && $plan['next_date'] !== null && (int) $plan['is_active'] === 1 ? (string) $plan['next_date'] : null;
        $kind = ServiceSchedule::KINDS[$values['kind']];
        $stats = $service->yearStats((int) $id);

        $logs = [];

        foreach ($service->logs((int) $id, 12) as $log) {
            $logKind = ServiceSchedule::KINDS[(string) $log['kind']] ?? ServiceSchedule::KINDS['small'];
            $checklist = ServiceChecklists::decode($log['checklist'] ?? null);
            $logs[] = $log + [
                'text' => ServiceChecklists::text((string) $log['description'], $checklist),
                'date' => get_czech_date((string) $log['performed_on']),
                'kindLabel' => $logKind['label'],
                'icon' => $logKind['icon'],
                'time' => $log['minutes'] !== null ? self::minutesLabel((int) $log['minutes']) : '—',
                'done' => $log['status'] === 'done',
                'progress' => ServiceChecklists::progress($checklist),
                'editUrl' => get_url('weby/' . (int) $id . '/servis/' . (int) $log['id'] . '/upravit'),
            ];
        }

        return $this->view('sites/service', $this->header($site, 'servis') + [
            'values' => $values,
            'kinds' => ServiceSchedule::KINDS,
            'frequencies' => ServiceSchedule::FREQUENCIES,
            'upcoming' => $upcoming,
            'next' => $next !== null ? [
                'date' => get_czech_date($next),
                'countdown' => ServiceSchedule::countdown($next, $today),
                'overdue' => ServiceSchedule::daysUntil($next, $today) < 0,
                'note' => $kind['label'] . ' · ' . ServiceSchedule::countdown($next, $today) . ' · ' . $kind['note'],
            ] : null,
            'planNote' => $plan === null ? 'Plán zatím není nastavený.' : ((int) $plan['is_active'] === 1
                ? $kind['label'] . ' · ' . mb_strtolower(ServiceSchedule::FREQUENCIES[$values['frequency']]['label']) . ($values['first_date'] !== '' ? ' · od ' . get_czech_date($values['first_date']) : '')
                : 'Plán je vypnutý — termíny se nehlídají.'),
            'summary' => [
                'kind' => $kind['label'],
                'frequency' => ServiceSchedule::FREQUENCIES[$values['frequency']]['label'],
                'year' => $stats['count'] . ' · celkem ' . self::minutesLabel($stats['minutes']),
            ],
            'logs' => $logs,
            'logsNote' => 'Posledních 12 měsíců · ' . get_count(count($logs), 'servis', 'servisy', 'servisů') . ' · zapisuje se do reportu',
            'prefill' => $this->request()->string('predvyplnit'),
        ]);
    }

    public function savePlan(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $request = $this->request();
        $first = $request->string('first_date');

        if ($first !== '' && \DateTime::createFromFormat('Y-m-d', $first) === false) {
            return $this->redirectWithFlash('weby/' . $id . '/servis', 'Datum prvního servisu zadejte ve tvaru RRRR-MM-DD.', 'error');
        }

        $plan = [
            'is_active' => $request->bool('is_active'),
            'kind' => $request->string('kind'),
            'frequency' => $request->string('frequency'),
            'first_date' => $first !== '' ? $first : null,
        ];

        if ($plan['is_active'] && $plan['first_date'] === null) {
            return $this->redirectWithFlash('weby/' . $id . '/servis', 'Zapnutý plán potřebuje datum prvního servisu.', 'error');
        }

        $this->kernel->service()->savePlan((int) $id, $plan, date('Y-m-d'));
        $saved = $this->kernel->service()->plan((int) $id);
        $this->kernel->alertEngine()->afterServicePlan($site, $saved);
        $this->kernel->events()->record((int) $id, EventLog::KIND_SERVICE, 'ok',
            $plan['is_active'] ? 'Plán servisu: ' . ServiceSchedule::KINDS[$saved['kind']]['label'] . ' ' . mb_strtolower(ServiceSchedule::FREQUENCIES[$saved['frequency']]['label']) . ($saved['next_date'] !== null ? ', příští ' . get_czech_date((string) $saved['next_date']) : '') : 'Plán servisu vypnut',
            [], $this->actorName());
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_SERVICE, true, 'Uložen plán servisu');

        return $this->redirectWithFlash('weby/' . $id . '/servis', 'Plán je uložený.');
    }

    /** „Posunout o týden" — jen další termín, rytmus zůstává. */
    public function postpone(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $plan = $this->kernel->service()->plan((int) $id);

        if ($plan === null || $plan['next_date'] === null) {
            return $this->redirectWithFlash('weby/' . $id . '/servis', 'Není co posouvat — plán nemá termín.', 'error');
        }

        $next = ServiceSchedule::postpone((string) $plan['next_date']);
        $this->kernel->service()->setNextDate((int) $id, $next);
        $this->kernel->alertEngine()->afterServicePlan($site, $this->kernel->service()->plan((int) $id));
        $this->kernel->events()->record((int) $id, EventLog::KIND_SERVICE, 'ok', 'Servis posunut na ' . get_czech_date($next), [], $this->actorName());

        return $this->redirectWithFlash('weby/' . $id . '/servis', 'Termín posunut na ' . get_czech_date($next) . '.');
    }

    public function logForm(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $plan = $this->kernel->service()->plan((int) $id);
        $prefill = $this->request()->string('predvyplnit');

        $notes = [];

        // „Zapsat do servisu" ze Zabezpečení předvyplní chybějící opatření.
        if ($prefill === 'zabezpeceni') {
            $audit = $this->kernel->securityAudit()->stored((int) $id);
            $items = array_filter((array) ($audit['checks'] ?? []), static fn (array $c): bool => in_array($c['status'], ['error', 'warning'], true));
            $notes[] = $items !== [] ? 'Zabezpečení: ' . implode(', ', array_map(static fn (array $c): string => mb_strtolower($c['label']), $items)) : '';
        }

        // Zastaralé PHP nebo databáze na hostingu: servis je chvíle, kdy to
        // klientovi říct — poznámka jde do reportu. Smazat ji jde jako text.
        $snapshot = $this->kernel->snapshots()->snapshot((int) $id);

        if ($snapshot !== null) {
            $notes[] = PhpSupport::advice((string) $snapshot['php_version']);
            $notes[] = DbSupport::advice((string) $snapshot['db_type'], (string) $snapshot['db_version']);
        }

        // Opuštěné a z adresáře stažené pluginy — stejné věty jako v reportu.
        foreach ($this->kernel->pluginDirectory()->issues((int) $id) as $issue) {
            $notes[] = PluginDirectory::issueText($issue);
        }

        $description = implode("\n", array_filter($notes, static fn (?string $note): bool => $note !== null && $note !== ''));

        return $this->logView($site, null, [
            'performed_on' => date('Y-m-d'),
            'kind' => $plan !== null ? (string) $plan['kind'] : 'small',
            'description' => $description,
            'minutes' => null,
            'status' => 'done',
        ], $this->formChecklists(null, false), []);
    }

    public function storeLog(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        [$values, $errors] = $this->readLog(null);

        if ($errors !== []) {
            return $this->logView($site, null, $values, $this->formChecklists(null, true), $errors, 422);
        }

        $service = $this->kernel->service();
        $service->addLog((int) $id, $values + ['user_name' => $this->actorName()]);

        // Zapsaný servis posune plán na další termín.
        $plan = $service->plan((int) $id);

        if ($plan !== null && (int) $plan['is_active'] === 1 && $plan['first_date'] !== null && $values['status'] === 'done') {
            $service->setNextDate((int) $id, ServiceSchedule::afterPerformed((string) $plan['first_date'], (string) $plan['frequency'], $values['performed_on'], $plan['next_date'] !== null ? (string) $plan['next_date'] : null));
        }

        $this->kernel->alertEngine()->afterServicePlan($site, $service->plan((int) $id));

        $kindLabel = ServiceSchedule::KINDS[$values['kind']]['label'] ?? 'Servis';
        $this->kernel->events()->record((int) $id, EventLog::KIND_SERVICE, $values['status'] === 'done' ? 'ok' : 'warning',
            ($values['status'] === 'done' ? $kindLabel . ': ' : $kindLabel . ' přeskočen: ') . mb_substr(ServiceChecklists::text($values['description'], $values['checklist']), 0, 120),
            ['kind' => $values['kind'], 'minutes' => $values['minutes'], 'status' => $values['status'], 'performed_on' => $values['performed_on']], $this->actorName());
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_SERVICE, true, 'Zapsán servis ' . get_czech_date($values['performed_on']));

        return $this->redirectWithFlash('weby/' . $id . '/servis', 'Servis je zapsaný' . ($values['status'] === 'done' ? ' a objeví se v nejbližším reportu.' : '.'));
    }

    /** Úprava zápisu — dopsat text, doodškrtnout checklist, opravit datum či čas. */
    public function editForm(string $id, string $logId): Response
    {
        $site = $this->siteOr404((int) $id);
        $log = $this->logOr404((int) $id, (int) $logId);

        return $this->logView($site, $log, [
            'performed_on' => (string) $log['performed_on'],
            'kind' => (string) $log['kind'],
            'description' => (string) $log['description'],
            'minutes' => $log['minutes'] !== null ? (int) $log['minutes'] : null,
            'status' => (string) $log['status'],
        ], $this->formChecklists($log, false), []);
    }

    public function updateLog(string $id, string $logId): Response
    {
        $site = $this->siteOr404((int) $id);
        $log = $this->logOr404((int) $id, (int) $logId);
        [$values, $errors] = $this->readLog($log);

        if ($errors !== []) {
            return $this->logView($site, $log, $values, $this->formChecklists($log, true), $errors, 422);
        }

        // Plán se úpravou neposouvá: termín posunul už původní zápis
        // a úprava je oprava údajů, ne nový servis.
        $this->kernel->service()->updateLog((int) $id, (int) $logId, $values);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_SERVICE, true,
            'Upraven záznam servisu z ' . get_czech_date($values['performed_on']) . (($progress = ServiceChecklists::progress($values['checklist'])) !== '' ? ' · ' . $progress : ''));

        return $this->redirectWithFlash('weby/' . $id . '/servis', 'Záznam servisu je upravený.');
    }

    public function removeLog(string $id, string $logId): Response
    {
        $site = $this->siteOr404((int) $id);
        $log = $this->kernel->service()->findLog((int) $id, (int) $logId);

        if ($log === null) {
            throw HttpException::notFound('Záznam servisu neexistuje.');
        }

        $this->kernel->service()->removeLog((int) $id, (int) $logId);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_SERVICE, true, 'Smazán záznam servisu z ' . get_czech_date((string) $log['performed_on']));

        return $this->redirectWithFlash('weby/' . $id . '/servis', 'Záznam je smazaný.');
    }

    // -----------------------------------------------------------------

    /**
     * Formulář zápisu (nový i úprava).
     *
     * @param array<string, mixed>      $site
     * @param array<string, mixed>|null $log    upravovaný zápis, null = nový
     * @param array<string, mixed>      $values
     * @param array<string, array<int, array{label: string, done: bool}>> $checklists
     * @param array<string, string>     $errors
     */
    private function logView(array $site, ?array $log, array $values, array $checklists, array $errors, int $status = 200): Response
    {
        $base = 'weby/' . (int) $site['id'] . '/servis';

        return $this->view('sites/service-log-form', $this->header($site, 'servis') + [
            'kinds' => ServiceSchedule::KINDS,
            'values' => $values,
            'checklists' => $checklists,
            'errors' => $errors,
            'isEdit' => $log !== null,
            'formAction' => get_url($log !== null ? $base . '/' . (int) $log['id'] . '/upravit' : $base . '/zapsat'),
            'editedNote' => $log !== null
                ? 'Zapsáno ' . get_when((string) $log['created_at']) . ((string) $log['user_name'] !== '' ? ' · ' . $log['user_name'] : '')
                    . (($log['updated_at'] ?? null) !== null ? ' · naposledy upraveno ' . get_when((string) $log['updated_at']) : '')
                : '',
        ], $status);
    }

    /**
     * Pole formuláře zápisu + checklist druhu, který je vybraný.
     *
     * @param array<string, mixed>|null $log upravovaný zápis (kvůli jeho uloženému checklistu)
     * @return array{0: array{performed_on: string, kind: string, description: string, minutes: ?int, status: string, checklist: array<int, array{label: string, done: bool}>}, 1: array<string, string>}
     */
    private function readLog(?array $log): array
    {
        $request = $this->request();
        $kind = $request->string('kind');
        $kind = isset(ServiceSchedule::KINDS[$kind]) ? $kind : 'small';
        $values = [
            'performed_on' => $request->string('performed_on'),
            'kind' => $kind,
            'description' => mb_substr($request->string('description'), 0, 5000),
            'minutes' => $request->int('minutes'),
            'status' => $request->string('status') === 'skipped' ? 'skipped' : 'done',
            'checklist' => $this->postedChecklist($kind, $log),
        ];
        $errors = [];

        if (\DateTime::createFromFormat('Y-m-d', $values['performed_on']) === false) {
            $errors['performed_on'] = 'Zadejte datum servisu.';
        }

        if (ServiceChecklists::text($values['description'], $values['checklist']) === '') {
            $errors['description'] = 'Odškrtněte, co je hotové, nebo napište poznámku — klient to uvidí v reportu.';
        }

        return [$values, $errors];
    }

    /**
     * Checklisty pro formulář — jeden na každý druh servisu; stránka ukáže
     * jen ten, jehož druh je vybraný (CSS `:has`), takže přepnutí druhu
     * funguje bez skriptu i bez znovunačtení.
     *
     * Úkoly ze seznamu dostanou `index` (hodnota zaškrtávátka `done[druh][]`),
     * vlastní úkoly `index` v poli `extra[druh][]`.
     *
     * @param array<string, mixed>|null $log    upravovaný zápis
     * @param bool                      $posted vzít stav z odeslaného formuláře (po chybě)
     * @return array<string, array<int, array{label: string, done: bool, extra: bool, index: int}>>
     */
    private function formChecklists(?array $log, bool $posted): array
    {
        $checklists = [];

        foreach (array_keys(ServiceSchedule::KINDS) as $kind) {
            $saved = $log !== null && (string) $log['kind'] === $kind ? ServiceChecklists::decode($log['checklist'] ?? null) : null;
            $items = $posted ? $this->postedChecklist($kind, $log) : ($saved ?? ServiceChecklists::build($this->checklistLabels($kind, $log), []));
            $counters = ['list' => 0, 'extra' => 0];
            $checklists[$kind] = [];

            foreach ($items as $item) {
                $counter = $item['extra'] ? 'extra' : 'list';
                $checklists[$kind][] = $item + ['index' => $counters[$counter]++];
            }
        }

        return $checklists;
    }

    /**
     * Checklist druhu z odeslaného formuláře: úkoly ze seznamu podle
     * odškrtnutí + vlastní úkoly.
     *
     * @param array<string, mixed>|null $log
     * @return array<int, array{label: string, done: bool, extra: bool}>
     */
    private function postedChecklist(string $kind, ?array $log): array
    {
        $extra = $this->request()->input('extra', []);

        return array_merge(
            ServiceChecklists::build($this->checklistLabels($kind, $log), (array) ($this->postedDone()[$kind] ?? [])),
            ServiceChecklists::extras(is_array($extra) && is_array($extra[$kind] ?? null) ? $extra[$kind] : []),
        );
    }

    /**
     * Úkoly druhu: u upravovaného zápisu jeho uložená kopie (pozdější
     * změna seznamu v nastavení starý zápis nemění), jinak seznam
     * z Nastavení → Servis.
     *
     * @param array<string, mixed>|null $log
     * @return array<int, string>
     */
    private function checklistLabels(string $kind, ?array $log): array
    {
        $saved = $log !== null && (string) $log['kind'] === $kind ? ServiceChecklists::decode($log['checklist'] ?? null) : null;

        if ($saved === null) {
            return $this->kernel->serviceChecklists()->template($kind);
        }

        return array_column(array_filter($saved, static fn (array $item): bool => !$item['extra']), 'label');
    }

    /** Odškrtnuté úkoly z formuláře: `done[druh][] = index`. @return array<string, array<int, mixed>> */
    private function postedDone(): array
    {
        $done = $this->request()->input('done', []);

        return is_array($done) ? array_map(static fn (mixed $items): array => is_array($items) ? $items : [], $done) : [];
    }

    /** @return array<string, mixed> */
    private function logOr404(int $siteId, int $logId): array
    {
        $log = $this->kernel->service()->findLog($siteId, $logId);

        if ($log === null) {
            throw HttpException::notFound('Záznam servisu neexistuje.');
        }

        return $log;
    }

    /** @param array<string, mixed>|null $plan @return array{is_active: bool, kind: string, frequency: string, first_date: string} */
    private function planValues(?array $plan): array
    {
        return [
            'is_active' => $plan !== null && (int) $plan['is_active'] === 1,
            'kind' => $plan !== null && isset(ServiceSchedule::KINDS[(string) $plan['kind']]) ? (string) $plan['kind'] : 'small',
            'frequency' => $plan !== null && isset(ServiceSchedule::FREQUENCIES[(string) $plan['frequency']]) ? (string) $plan['frequency'] : 'monthly',
            'first_date' => $plan !== null && $plan['first_date'] !== null ? (string) $plan['first_date'] : '',
        ];
    }

    /** „1 h 50 min" z minut. */
    public static function minutesLabel(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60) . ' h' . ($minutes % 60 > 0 ? ' ' . ($minutes % 60) . ' min' : '') : $minutes . ' min';
    }

    private function actorName(): string
    {
        $user = $this->kernel->auth()->current();

        return $user !== null ? UserRepository::displayName($user) : '';
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
