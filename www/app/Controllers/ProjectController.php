<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Projects\Credentials;
use App\Core\Projects\ProjectServices;
use App\Core\Sites\SiteRepository;
use App\Core\Sites\SiteStatus;

/**
 * Projekty — klient → projekt → služby. Návrh je zatím nemá, obrazovky
 * se skládají z tabulek a karet jako Klienti.
 *
 * Projekt teď nese weby; domény, hosting, e-maily a přístupy přibudou
 * v dalších etapách (`dev/docs/02-todo.md`). Web se do projektu přidává
 * tlačítkem „Přidat web" (předvyplní projekt), přesouvá v Nastavení webu.
 */
final class ProjectController extends Controller
{
    /** Pořadí závažnosti pro stav projektu — vyhrává nejhorší web. */
    private const LEVEL_RANK = ['problem' => 3, 'attention' => 2, 'unknown' => 1, 'ok' => 0];

    public function index(): Response
    {
        $request = $this->request();
        $q = $request->string('q');
        $filter = in_array($request->string('filtr'), ['bez-klienta', 'bez-webu'], true) ? $request->string('filtr') : '';

        $sitesByProject = [];

        foreach ($this->kernel->sites()->all() as $site) {
            if ($site['project_id'] !== null) {
                $sitesByProject[(int) $site['project_id']][] = $site;
            }
        }

        // Nejbližší obnova projektu: služby jsou seřazené podle data, první vyhrává.
        $today = date('Y-m-d');
        $days = $this->kernel->monitorSettings()->int('rule_renewal_days');
        $nextRenewal = [];

        foreach ($this->kernel->projectServices()->all() as $service) {
            if ($service['renews_on'] !== null && !isset($nextRenewal[(int) $service['project_id']])) {
                $nextRenewal[(int) $service['project_id']] = ProjectServices::renewalState($service, $today, $days);
            }
        }

        $all = $this->kernel->projects()->all();
        $counts = [
            'all' => count($all),
            'bez-klienta' => count(array_filter($all, static fn (array $p): bool => $p['client_id'] === null)),
            'bez-webu' => count(array_filter($all, static fn (array $p): bool => (int) $p['site_count'] === 0)),
        ];

        $rows = [];

        foreach ($this->kernel->projects()->all(['q' => $q]) as $project) {
            if (($filter === 'bez-klienta' && $project['client_id'] !== null) || ($filter === 'bez-webu' && (int) $project['site_count'] > 0)) {
                continue;
            }

            $sites = $sitesByProject[(int) $project['id']] ?? [];
            $hosts = array_map(static fn (array $s): string => SiteRepository::host((string) $s['url']), $sites);

            $rows[] = $project + [
                'state' => self::worstState($sites),
                'hosts' => $hosts === [] ? '—' : $hosts[0] . (count($hosts) > 1 ? ' +' . (count($hosts) - 1) : ''),
                'firstSite' => $sites[0] ?? null,
                'renewal' => $nextRenewal[(int) $project['id']] ?? null,
            ];
        }

        return $this->view('projects/index', [
            'title' => 'Projekty',
            'projects' => $rows,
            'q' => $q,
            'filter' => $filter,
            'counts' => $counts,
            'meta' => get_count($counts['all'], 'projekt', 'projekty', 'projektů') . ' · '
                . get_count($this->kernel->clients()->countActive(), 'klient', 'klienti', 'klientů'),
            'tabsHtml' => self::listTabs('projekty'),
        ]);
    }

    /** Záložky nad seznamem projektů a přehledem obnov — každá vlastní URL. */
    public static function listTabs(string $active): string
    {
        return get_tabs([
            ['key' => 'projekty', 'label' => 'Projekty', 'url' => get_url('projekty')],
            ['key' => 'obnovy', 'label' => 'Obnovy a fakturace', 'url' => get_url('projekty/obnovy')],
        ], $active);
    }

    public function createForm(): Response
    {
        return $this->formPage(null, ['name' => '', 'client_id' => $this->request()->int('klient'), 'note' => ''], []);
    }

    public function store(): Response
    {
        [$values, $errors] = $this->readForm();

        if ($errors !== []) {
            return $this->formPage(null, $values, $errors, 422);
        }

        $id = $this->kernel->projects()->create(self::columns($values));
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_PROJECT, true, 'Založen projekt ' . $values['name']);

        return $this->redirectWithFlash('projekty/' . $id, 'Projekt je založený. Přidejte do něj web, nebo sem přesuňte existující v jeho Nastavení.');
    }

    public function detail(string $id): Response
    {
        $project = $this->projectOr404((int) $id);
        $sites = SiteController::careRows($this->kernel, $this->kernel->sites()->forProject((int) $id));
        $today = date('Y-m-d');
        $days = $this->kernel->monitorSettings()->int('rule_renewal_days');
        $services = array_map(
            static fn (array $s): array => ProjectServiceController::row($s, $today, $days),
            $this->kernel->projectServices()->forProject((int) $id),
        );

        return $this->view('projects/detail', [
            'title' => (string) $project['name'],
            'project' => $project,
            'sites' => $sites,
            'services' => $services,
            'vault' => self::vaultSummary($this->kernel->credentials()->countsByKind((int) $id)),
            'state' => self::worstState($sites),
            'meta' => [
                'sites' => get_count(count($sites), 'web', 'weby', 'webů'),
                'created' => 'založeno ' . get_czech_date((string) $project['created_at']),
            ],
        ]);
    }

    public function editForm(string $id): Response
    {
        $project = $this->projectOr404((int) $id);

        return $this->formPage($project, [
            'name' => (string) $project['name'],
            'client_id' => $project['client_id'] !== null ? (int) $project['client_id'] : null,
            'note' => (string) ($project['note'] ?? ''),
        ], []);
    }

    public function update(string $id): Response
    {
        $project = $this->projectOr404((int) $id);
        [$values, $errors] = $this->readForm();

        if ($errors !== []) {
            return $this->formPage($project, $values, $errors, 422);
        }

        $this->kernel->projects()->update((int) $id, self::columns($values));
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_PROJECT, true, 'Upraven projekt ' . $values['name']);

        return $this->redirectWithFlash('projekty/' . $id, 'Projekt je uložený.');
    }

    /** Smazání jde jen u projektu bez webů v monitoringu — weby se nejdřív přesunou nebo odeberou. */
    public function delete(string $id): Response
    {
        $project = $this->projectOr404((int) $id);

        if (!$this->kernel->projects()->delete((int) $id)) {
            return $this->redirectWithFlash('projekty/' . $id, 'Projekt má weby v monitoringu. Přesuňte je do jiného projektu (Nastavení webu), nebo je odeberte.', 'error');
        }

        $this->kernel->audit()->record(null, '', AuditLog::ACTION_PROJECT, true, 'Smazán projekt ' . $project['name']);

        return $this->redirectWithFlash('projekty', 'Projekt ' . $project['name'] . ' je smazaný.');
    }

    /**
     * Souhrn trezoru do detailu projektu — jen počty po druzích, hesla
     * ani adresy se tu neukazují (trezor otevře až účet s 2FA).
     *
     * @param array<string, int> $counts druh => počet
     * @return array<int, array{label: string, icon: string, count: int}>
     */
    private static function vaultSummary(array $counts): array
    {
        $summary = [];

        foreach (Credentials::KINDS as $kind => $meta) {
            if (($counts[$kind] ?? 0) > 0) {
                $summary[] = ['label' => $meta['label'], 'icon' => $meta['icon'], 'count' => $counts[$kind]];
            }
        }

        return $summary;
    }

    /**
     * Stav projektu = stav jeho nejhoršího webu. Projekt bez webu má
     * vlastní šedý popisek — nic se u něj nehlídá.
     *
     * @param array<int, array<string, mixed>> $sites řádky z `SiteRepository::all()`
     * @return array{level: string, tone: string, label: string}
     */
    public static function worstState(array $sites): array
    {
        $worst = null;

        foreach ($sites as $site) {
            $state = $site['state'] ?? SiteStatus::of($site);

            if ($worst === null || self::LEVEL_RANK[$state['level']] > self::LEVEL_RANK[$worst['level']]) {
                $worst = $state;
            }
        }

        return $worst ?? ['level' => 'none', 'tone' => 'muted', 'label' => 'Bez webu'];
    }

    // -----------------------------------------------------------------
    // Formulář (společný pro založení i úpravu)
    // -----------------------------------------------------------------

    /** @return array{0: array{name: string, client_id: ?int, note: string}, 1: array<string, string>} */
    private function readForm(): array
    {
        $request = $this->request();
        $values = [
            'name' => mb_substr($request->string('name'), 0, 150),
            'client_id' => $request->int('client_id'),
            'note' => mb_substr($request->string('note'), 0, 5000),
        ];

        if ($values['client_id'] !== null && $this->kernel->clients()->find($values['client_id']) === null) {
            $values['client_id'] = null;
        }

        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Projekt potřebuje název — obvykle doména, např. pekarnanovak.cz.';
        }

        return [$values, $errors];
    }

    /** @param array{name: string, client_id: ?int, note: string} $values @return array<string, mixed> */
    private static function columns(array $values): array
    {
        return [
            'name' => $values['name'],
            'client_id' => $values['client_id'],
            'note' => $values['note'] !== '' ? $values['note'] : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $project null = nový projekt
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function formPage(?array $project, array $values, array $errors, int $status = 200): Response
    {
        return $this->view('projects/form', [
            'title' => $project === null ? 'Nový projekt' : 'Úprava projektu',
            'project' => $project,
            'values' => $values,
            'errors' => $errors,
            'clients' => $this->kernel->clients()->options(),
        ], $status);
    }

    /** @return array<string, mixed> */
    private function projectOr404(int $id): array
    {
        return $this->kernel->projects()->find($id) ?? throw HttpException::notFound('Projekt neexistuje.');
    }
}
