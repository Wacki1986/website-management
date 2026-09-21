<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\UserRepository;
use App\Core\Http\Controller;
use App\Core\Http\Response;
use App\Core\Monitor\AlertRepository;

/**
 * Alerty (návrh `alerty*.html`): segmenty závažnosti, pilulky stavu,
 * seskupení podle dne, hromadné akce. Bez JavaScriptu fungují jednotlivé
 * akce v řádcích; hromadný výběr doplňuje `selection.js` (formulář
 * s checkboxy funguje i bez něj — zaškrtávátka jsou skutečná).
 */
final class AlertController extends Controller
{
    public function index(): Response
    {
        $request = $this->request();
        $status = in_array($request->string('stav'), ['open', 'resolved', 'ignored', 'all'], true) ? $request->string('stav') : 'open';
        $severity = in_array($request->string('zavaznost'), ['error', 'warning', 'info'], true) ? $request->string('zavaznost') : '';
        $siteId = $request->int('web');

        $alerts = $this->kernel->alerts();
        $counts = $alerts->counts();
        $rows = [];

        foreach ($alerts->all(['status' => $status, 'severity' => $severity, 'site' => $siteId]) as $alert) {
            $rows[] = $this->row($alert);
        }

        // Seskupení: Dnes / Včera / Tento týden / Starší (návrh `.table__group--caps`).
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row['group']][] = $row;
        }

        $oldest = $alerts->oldestOpen();

        return $this->view('alerts/index', [
            'title' => 'Alerty',
            'groups' => $groups,
            'status' => $status,
            'severity' => $severity,
            'siteId' => $siteId,
            'sites' => $this->siteOptions(),
            'counts' => $counts,
            'oldest' => $oldest !== null ? ['age' => get_duration((string) $oldest['opened_at'], date('Y-m-d H:i:s')), 'label' => $oldest['title'] . ' · ' . $oldest['site_name']] : null,
            'meta' => get_count($counts['open'], 'nevyřešený', 'nevyřešené', 'nevyřešených') . ' · '
                . get_count($counts['error'], 'kritický', 'kritické', 'kritických') . ' · '
                . get_count($counts['ignored'], 'ignorovaný', 'ignorované', 'ignorovaných'),
            'shown' => count($rows),
            'total' => $status === 'all' ? $counts['open'] + $counts['resolved'] + $counts['ignored'] : ($status === 'open' ? $counts['open'] : ($status === 'resolved' ? $counts['resolved'] : $counts['ignored'])),
        ]);
    }

    public function resolve(string $id): Response
    {
        $this->kernel->alertEngine()->resolve((int) $id, $this->actorName(), $this->request()->string('note'));
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_ALERT, true, 'Alert #' . (int) $id . ' označen jako vyřešený');

        return $this->redirectWithFlash($this->backTo(), 'Alert je označený jako vyřešený.');
    }

    public function ignore(string $id): Response
    {
        $this->kernel->alertEngine()->ignore((int) $id, $this->actorName());
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_ALERT, true, 'Alert #' . (int) $id . ' ignorován');

        return $this->redirectWithFlash($this->backTo(), 'Alert je ignorovaný.');
    }

    public function reopen(string $id): Response
    {
        $this->kernel->alertEngine()->reopen((int) $id, $this->actorName());

        return $this->redirectWithFlash($this->backTo(), 'Alert je zpátky mezi otevřenými.');
    }

    /** Hromadně: vyřešit / ignorovat vybrané (`ids[]`). */
    public function bulk(): Response
    {
        $request = $this->request();
        $ids = array_map('intval', (array) $request->input('ids', []));
        $action = $request->string('action');
        $who = $this->actorName();

        if ($ids === []) {
            return $this->redirectWithFlash('alerty', 'Nic není vybráno.', 'warning');
        }

        foreach ($ids as $id) {
            match ($action) {
                'ignore' => $this->kernel->alertEngine()->ignore($id, $who),
                default => $this->kernel->alertEngine()->resolve($id, $who),
            };
        }

        $this->kernel->audit()->record(null, '', AuditLog::ACTION_ALERT, true, ($action === 'ignore' ? 'Ignorováno ' : 'Vyřešeno ') . get_count(count($ids), 'alert', 'alerty', 'alertů'));

        return $this->redirectWithFlash('alerty', ($action === 'ignore' ? 'Ignorováno ' : 'Vyřešeno ') . get_count(count($ids), 'alert', 'alerty', 'alertů') . '.');
    }

    /** „Vyřešit vše nové" — všechny otevřené naráz. */
    public function resolveAllOpen(): Response
    {
        $ids = $this->kernel->alerts()->openIds();
        $who = $this->actorName();

        foreach ($ids as $id) {
            $this->kernel->alertEngine()->resolve($id, $who, 'Vyřešeno hromadně');
        }

        $this->kernel->audit()->record(null, '', AuditLog::ACTION_ALERT, true, 'Vyřešeny všechny otevřené alerty (' . count($ids) . ')');

        return $this->redirectWithFlash('alerty', $ids === [] ? 'Žádný otevřený alert.' : 'Vyřešeno ' . get_count(count($ids), 'alert', 'alerty', 'alertů') . '.');
    }

    // -----------------------------------------------------------------

    /** Řádek pro šablonu — hotové popisky, skupina podle dne. @param array<string, mixed> $alert @return array<string, mixed> */
    private function row(array $alert): array
    {
        $opened = strtotime((string) $alert['opened_at']);
        $today = strtotime('today');
        $group = match (true) {
            $opened >= $today => 'Dnes',
            $opened >= $today - 86400 => 'Včera',
            $opened >= $today - 6 * 86400 => 'Tento týden',
            default => 'Starší',
        };

        $isOpen = $alert['status'] === 'open';
        $duration = $isOpen ? get_duration((string) $alert['opened_at'], date('Y-m-d H:i:s')) : get_duration((string) $alert['opened_at'], (string) ($alert['resolved_at'] ?? $alert['opened_at']));

        return $alert + [
            'group' => $group,
            'when' => get_when((string) $alert['opened_at']),
            'durationLabel' => $isOpen ? 'trvá ' . $duration : 'trvalo ' . $duration,
            'icon' => AlertRepository::ICONS[(string) $alert['type']] ?? 'alert',
            'iconClass' => $alert['severity'] === 'error' ? 'icon--error' : ($alert['severity'] === 'warning' ? 'icon--warning' : 'icon--subtle'),
            'titleClass' => $alert['severity'] === 'error' ? 'text-error' : ($alert['severity'] === 'warning' ? 'text-warning' : ''),
            'stateTone' => $isOpen ? 'error' : ($alert['status'] === 'resolved' ? 'ok' : 'muted'),
            'stateLabel' => $isOpen ? 'Nový' : ($alert['status'] === 'resolved' ? 'Vyřešeno' : 'Ignorováno'),
            'resolvedNote' => $alert['status'] !== 'open'
                ? trim(((string) $alert['resolved_by'] !== '' ? ((string) $alert['resolved_by'] === 'monitor' ? 'monitor' : $alert['resolved_by']) . ' · ' : '') . get_when((string) ($alert['resolved_at'] ?? '')))
                : '',
            'occurrencesLabel' => (int) $alert['occurrences_30d'] > 1 ? (int) $alert['occurrences_30d'] . '. výskyt za 30 dní' : '',
            'host' => \App\Core\Sites\SiteRepository::host((string) $alert['site_url']),
        ];
    }

    /** @return array<int, string> */
    private function siteOptions(): array
    {
        $options = [];

        foreach ($this->kernel->sites()->all() as $site) {
            $options[(int) $site['id']] = (string) $site['name'];
        }

        return $options;
    }

    private function backTo(): string
    {
        $back = (string) ($this->request()->headers['referer'] ?? '');

        // Zpět na detail webu, když akce přišla odtamtud; jinak na seznam.
        return preg_match('#/weby/(\d+)#', $back, $m) === 1 ? 'weby/' . $m[1] : 'alerty';
    }

    private function actorName(): string
    {
        $user = $this->kernel->auth()->current();

        return $user !== null ? UserRepository::displayName($user) : '';
    }
}
