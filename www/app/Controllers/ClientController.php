<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Clients\Ares;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Sites\SiteStatus;

/**
 * Klienti (návrh `klienti.html`, `detail-klienta*.html`, `novy-klient*.html`).
 *
 * Klient = firma + hlavní kontaktní osoba + weby v péči. Formulář nového
 * klienta i úprava jdou přes jednu metodu `readForm()`; tlačítko „Načíst
 * z ARESu" je obyčejný submit s `_action=ares`, takže funguje bez
 * JavaScriptu — formulář se vrátí předvyplněný.
 */
final class ClientController extends Controller
{
    public function index(): Response
    {
        $request = $this->request();
        $q = $request->string('q');
        $filter = in_array($request->string('filtr'), ['multi', 'person'], true) ? $request->string('filtr') : '';

        $rows = [];

        foreach ($this->kernel->clients()->all(['q' => $q, 'filter' => $filter]) as $client) {
            $rows[] = $client + [
                'contactName' => trim((string) $client['first_name'] . ' ' . (string) $client['last_name']),
                'siteCountLabel' => get_count((int) $client['site_count'], 'web', 'weby', 'webů'),
                'city' => self::city((string) $client['address']),
            ];
        }

        $all = $this->kernel->clients()->all();
        $counts = [
            'all' => count($all),
            'multi' => count(array_filter($all, static fn (array $c): bool => (int) $c['site_count'] > 1)),
            'person' => count(array_filter($all, static fn (array $c): bool => (string) $c['vat_id'] === '')),
        ];

        return $this->view('clients/index', [
            'title' => 'Klienti',
            'clients' => $rows,
            'q' => $q,
            'filter' => $filter,
            'counts' => $counts,
            'meta' => get_count($counts['all'], 'klient', 'klienti', 'klientů') . ' · '
                . get_count($this->kernel->sites()->countActive(), 'web', 'weby', 'webů'),
        ]);
    }

    public function createForm(): Response
    {
        return $this->formPage(null, self::emptyValues(), []);
    }

    public function store(): Response
    {
        [$values, $errors] = $this->readForm();

        if ($this->request()->string('_action') === 'ares') {
            return $this->formPage(null, $this->withAres($values), []);
        }

        if ($errors !== []) {
            return $this->formPage(null, $values, $errors, 422);
        }

        $clients = $this->kernel->clients();
        $id = $clients->create(self::clientColumns($values));
        $clients->savePrimaryContact($id, self::contactColumns($values));
        $this->kernel->sites()->assignToClient($values['sites'], $id);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_CLIENT, true, 'Založen klient ' . $values['name']);

        $siteCount = count($values['sites']);
        $this->kernel->flash('Klient uložen. ' . ($siteCount > 0
            ? 'Má přiřazený ' . get_count($siteCount, 'web', 'weby', 'webů') . '. Reporty nastavte v detailu webu.'
            : 'Přidejte mu web v sekci Weby.'));

        return $this->request()->string('_action') === 'save_and_site'
            ? $this->redirect('weby/pridat?klient=' . $id)
            : $this->redirect('klienti/' . $id);
    }

    public function detail(string $id): Response
    {
        $client = $this->clientOr404((int) $id);
        $contacts = $this->kernel->clients()->contacts((int) $id);
        $primary = $contacts[0] ?? null;

        $sites = [];

        $clientSites = $this->kernel->sites()->forClient((int) $id);
        $plans = $this->kernel->service()->plansFor(array_map(static fn (array $s): int => (int) $s['id'], $clientSites));
        $reportSettings = $this->kernel->reports()->settingsFor(array_map(static fn (array $s): int => (int) $s['id'], $clientSites));
        $lastReports = $this->kernel->reports()->latestSentFor(array_map(static fn (array $s): int => (int) $s['id'], $clientSites));
        $lastReport = null;

        foreach ($lastReports as $r) {
            if ($lastReport === null || (string) $r['sent_at'] > (string) $lastReport['sent_at']) {
                $lastReport = $r;
            }
        }

        foreach ($clientSites as $site) {
            $sites[] = $site + [
                'state' => SiteStatus::of($site),
                'host' => \App\Core\Sites\SiteRepository::host((string) $site['url']),
                'service' => \App\Core\Service\ServiceSchedule::cell($plans[(int) $site['id']] ?? null, date('Y-m-d')),
                'report' => SiteController::reportCell($reportSettings[(int) $site['id']] ?? null),
            ];
        }

        // „Poslední komunikace" = události u webů klienta (reporty, servis, alerty).
        $siteIds = array_map(static fn (array $s): int => (int) $s['id'], $sites);
        $recent = $this->kernel->events()->recent(6, $siteIds);

        return $this->view('clients/detail', [
            'title' => (string) $client['name'],
            'client' => $client,
            'primary' => $primary,
            'contacts' => array_slice($contacts, 1),
            'sites' => $sites,
            'lastReport' => $lastReport !== null ? get_czech_date((string) $lastReport['sent_at']) . ' · ' . (string) $lastReport['period_label'] : '—',
            'recent' => $recent,
            'unassigned' => $this->kernel->sites()->unassigned(),
            'meta' => [
                'contact' => $primary !== null ? trim($primary['first_name'] . ' ' . $primary['last_name'] . ($primary['role'] !== '' ? ' · ' . $primary['role'] : '')) : '',
                'sites' => get_count(count($sites), 'web v péči', 'weby v péči', 'webů v péči'),
                'since' => $client['since'] !== null ? 'spolupráce od ' . date('n/Y', (int) strtotime((string) $client['since'])) : '',
            ],
        ]);
    }

    public function editForm(string $id): Response
    {
        $client = $this->clientOr404((int) $id);
        $primary = $this->kernel->clients()->primaryContact((int) $id) ?? [];

        $values = self::emptyValues();

        foreach (['name', 'company_id', 'vat_id', 'address', 'billing_note', 'note', 'since'] as $key) {
            $values[$key] = (string) ($client[$key] ?? '');
        }

        foreach (['first_name', 'last_name', 'role', 'email', 'phone'] as $key) {
            $values[$key] = (string) ($primary[$key] ?? '');
        }

        return $this->formPage($client, $values, []);
    }

    public function update(string $id): Response
    {
        $client = $this->clientOr404((int) $id);
        [$values, $errors] = $this->readForm();

        if ($this->request()->string('_action') === 'ares') {
            return $this->formPage($client, $this->withAres($values), []);
        }

        if ($errors !== []) {
            return $this->formPage($client, $values, $errors, 422);
        }

        $clients = $this->kernel->clients();
        $clients->update((int) $id, self::clientColumns($values));
        $clients->savePrimaryContact((int) $id, self::contactColumns($values));
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_CLIENT, true, 'Upraven klient ' . $values['name']);

        return $this->redirectWithFlash('klienti/' . $id, 'Údaje klienta jsou uložené.');
    }

    /** Archivace: weby zůstanou v monitoringu bez klienta. */
    public function archive(string $id): Response
    {
        $client = $this->clientOr404((int) $id);
        $this->kernel->clients()->archive((int) $id);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_CLIENT, true, 'Odebrán klient ' . $client['name']);

        return $this->redirectWithFlash('klienti', 'Klient ' . $client['name'] . ' je odebraný, jeho weby zůstávají v monitoringu.');
    }

    /** Přiřazení dosud nepřiřazených webů (karta Weby klienta → Přiřadit web). */
    public function assignSites(string $id): Response
    {
        $this->clientOr404((int) $id);
        $siteIds = array_map('intval', (array) $this->request()->input('sites', []));
        $this->kernel->sites()->assignToClient($siteIds, (int) $id);

        return $this->redirectWithFlash('klienti/' . $id, $siteIds === [] ? 'Nic nevybráno.' : 'Weby jsou přiřazené.');
    }

    public function addContact(string $id): Response
    {
        $this->clientOr404((int) $id);
        $request = $this->request();
        $contact = [
            'first_name' => mb_substr($request->string('first_name'), 0, 100),
            'last_name' => mb_substr($request->string('last_name'), 0, 100),
            'role' => mb_substr($request->string('role'), 0, 100),
            'email' => mb_strtolower(mb_substr($request->string('email'), 0, 190)),
            'phone' => mb_substr($request->string('phone'), 0, 50),
        ];

        if ($contact['last_name'] === '' && $contact['email'] === '') {
            return $this->redirectWithFlash('klienti/' . $id, 'Kontakt potřebuje aspoň příjmení nebo e-mail.', 'error');
        }

        if ($contact['email'] !== '' && filter_var($contact['email'], FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirectWithFlash('klienti/' . $id, 'E-mail kontaktu nemá platný tvar.', 'error');
        }

        $this->kernel->clients()->addContact((int) $id, $contact);

        return $this->redirectWithFlash('klienti/' . $id, 'Kontakt je přidaný.');
    }

    public function removeContact(string $id, string $contactId): Response
    {
        $this->clientOr404((int) $id);
        $this->kernel->clients()->removeContact((int) $id, (int) $contactId);

        return $this->redirectWithFlash('klienti/' . $id, 'Kontakt je odebraný.');
    }

    // -----------------------------------------------------------------
    // Formulář (společný pro založení i úpravu)
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function emptyValues(): array
    {
        return [
            'name' => '', 'company_id' => '', 'vat_id' => '', 'address' => '', 'billing_note' => '', 'note' => '', 'since' => '',
            'first_name' => '', 'last_name' => '', 'role' => '', 'email' => '', 'phone' => '',
            'sites' => [],
        ];
    }

    /**
     * Přečte formulář a ověří povinná pole (jen název firmy, příjmení a e-mail).
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function readForm(): array
    {
        $request = $this->request();
        $values = self::emptyValues();

        foreach (array_keys($values) as $key) {
            if ($key === 'sites') {
                continue;
            }

            $values[$key] = mb_substr($request->string($key), 0, $key === 'note' ? 5000 : 255);
        }

        $values['company_id'] = Ares::normalizeIco($values['company_id']);
        $values['email'] = mb_strtolower($values['email']);
        $values['sites'] = array_values(array_unique(array_map('intval', (array) $request->input('sites', []))));

        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Bez názvu firmy klienta nezaložíme.';
        }

        if ($values['last_name'] === '') {
            $errors['last_name'] = 'Doplňte příjmení kontaktní osoby.';
        }

        if ($values['email'] === '') {
            $errors['email'] = 'E-mail je povinný — chodí na něj reporty.';
        } elseif (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'E-mail nemá platný tvar.';
        }

        if ($values['since'] !== '' && \DateTime::createFromFormat('Y-m-d', $values['since']) === false) {
            $errors['since'] = 'Datum zadejte ve tvaru RRRR-MM-DD.';
        }

        return [$values, $errors];
    }

    /**
     * Načtení z ARESu do rozepsaného formuláře — ručně vyplněné údaje se
     * nepřepisují, doplní se jen prázdná pole.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withAres(array $values): array
    {
        $found = $values['company_id'] !== '' ? $this->kernel->ares()->lookup($values['company_id']) : null;

        if ($found === null) {
            $this->kernel->flash($values['company_id'] === '' ? 'Zadejte IČO.' : 'ARES firmu s tímhle IČO nezná, nebo neodpověděl.', 'error');

            return $values;
        }

        foreach (['name', 'vat_id', 'address'] as $key) {
            if ($values[$key] === '' && $found[$key] !== '') {
                $values[$key] = $found[$key];
            }
        }

        $this->kernel->flash('Údaje z ARESu jsou načtené — zkontrolujte je a uložte.');

        return $values;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function clientColumns(array $values): array
    {
        return [
            'name' => $values['name'],
            'company_id' => $values['company_id'],
            'vat_id' => $values['vat_id'],
            'address' => $values['address'],
            'billing_note' => $values['billing_note'],
            'since' => $values['since'] !== '' ? $values['since'] : null,
            'note' => $values['note'] !== '' ? $values['note'] : null,
        ];
    }

    /** @param array<string, mixed> $values @return array{first_name: string, last_name: string, role: string, email: string, phone: string} */
    private static function contactColumns(array $values): array
    {
        return [
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'role' => $values['role'],
            'email' => $values['email'],
            'phone' => $values['phone'],
        ];
    }

    /**
     * @param array<string, mixed>|null $client null = nový klient
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function formPage(?array $client, array $values, array $errors, int $status = 200): Response
    {
        // Kontrolní seznam v bočním panelu (návrh: task-list).
        $tasks = [
            ['label' => 'Firma a fakturační údaje', 'done' => $values['name'] !== ''],
            ['label' => 'Kontaktní osoba', 'done' => $values['last_name'] !== ''],
            ['label' => 'E-mail pro reporty', 'done' => filter_var($values['email'], FILTER_VALIDATE_EMAIL) !== false],
            ['label' => 'Přiřazený web', 'done' => $values['sites'] !== [] || ($client !== null && $this->kernel->sites()->forClient((int) $client['id']) !== [])],
        ];

        return $this->view('clients/form', [
            'title' => $client === null ? 'Přidání nového klienta' : 'Úprava klienta',
            'client' => $client,
            'values' => $values,
            'errors' => $errors,
            'unassigned' => $client === null ? $this->kernel->sites()->unassigned() : [],
            'tasks' => $tasks,
            'previewName' => $values['name'] !== '' ? $values['name'] : 'Název firmy',
            'previewContact' => trim($values['first_name'] . ' ' . $values['last_name']) !== '' ? trim($values['first_name'] . ' ' . $values['last_name']) : 'kontaktní osoba',
        ], $status);
    }

    /** @return array<string, mixed> */
    private function clientOr404(int $id): array
    {
        $client = $this->kernel->clients()->find($id);

        if ($client === null || $client['archived_at'] !== null) {
            throw HttpException::notFound('Klient neexistuje.');
        }

        return $client;
    }

    /** Město z adresy „Zámecká 1, 460 01 Liberec" → „Liberec". */
    private static function city(string $address): string
    {
        $parts = array_map('trim', explode(',', $address));
        $last = (string) end($parts);

        return trim((string) preg_replace('/^\d{3}\s?\d{2}\s*/', '', $last));
    }
}
