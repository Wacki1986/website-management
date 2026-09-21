<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\UserRepository;
use App\Core\Events\EventLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Service\ServiceSchedule;

/**
 * Detail webu — záložka Servis (návrh `detail-webu-servis.html`):
 * plán servisu, nadcházející termíny, historie provedených servisů
 * a zápis nového. Hlavičku záložek sdílí se `SiteController::header()`.
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
            $logs[] = $log + [
                'date' => get_czech_date((string) $log['performed_on']),
                'kindLabel' => $logKind['label'],
                'icon' => $logKind['icon'],
                'time' => $log['minutes'] !== null ? self::minutesLabel((int) $log['minutes']) : '—',
                'done' => $log['status'] === 'done',
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
                'estimate' => $kind['estimate'],
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

        $description = '';

        // „Zapsat do servisu" ze Zabezpečení předvyplní chybějící opatření.
        if ($prefill === 'zabezpeceni') {
            $audit = $this->kernel->securityAudit()->stored((int) $id);
            $items = array_filter((array) ($audit['checks'] ?? []), static fn (array $c): bool => in_array($c['status'], ['error', 'warning'], true));
            $description = $items !== [] ? 'Zabezpečení: ' . implode(', ', array_map(static fn (array $c): string => mb_strtolower($c['label']), $items)) : '';
        }

        return $this->view('sites/service-log-form', $this->header($site, 'servis') + [
            'kinds' => ServiceSchedule::KINDS,
            'values' => [
                'performed_on' => date('Y-m-d'),
                'kind' => $plan !== null ? (string) $plan['kind'] : 'small',
                'description' => $description,
                'minutes' => $plan !== null ? ServiceSchedule::KINDS[(string) $plan['kind']]['minutes'] : 50,
                'status' => 'done',
            ],
            'errors' => [],
        ]);
    }

    public function storeLog(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $request = $this->request();
        $values = [
            'performed_on' => $request->string('performed_on'),
            'kind' => $request->string('kind'),
            'description' => mb_substr($request->string('description'), 0, 5000),
            'minutes' => $request->int('minutes'),
            'status' => $request->string('status') === 'skipped' ? 'skipped' : 'done',
        ];
        $errors = [];

        if (\DateTime::createFromFormat('Y-m-d', $values['performed_on']) === false) {
            $errors['performed_on'] = 'Zadejte datum servisu.';
        }

        if ($values['description'] === '') {
            $errors['description'] = 'Napište, co jste udělali — klient to uvidí v reportu.';
        }

        if ($errors !== []) {
            return $this->view('sites/service-log-form', $this->header($site, 'servis') + [
                'kinds' => ServiceSchedule::KINDS,
                'values' => $values,
                'errors' => $errors,
            ], 422);
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
            $values['status'] === 'done' ? $kindLabel . ': ' . mb_substr($values['description'], 0, 120) : $kindLabel . ' přeskočen: ' . mb_substr($values['description'], 0, 120),
            ['kind' => $values['kind'], 'minutes' => $values['minutes'], 'status' => $values['status'], 'performed_on' => $values['performed_on']], $this->actorName());
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_SERVICE, true, 'Zapsán servis ' . get_czech_date($values['performed_on']));

        return $this->redirectWithFlash('weby/' . $id . '/servis', 'Servis je zapsaný' . ($values['status'] === 'done' ? ' a objeví se v nejbližším reportu.' : '.'));
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
