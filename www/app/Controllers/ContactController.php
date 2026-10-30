<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Kernel;

/**
 * Kontaktní osoby (záložka Klienti → Kontaktní osoby).
 *
 * Osoba je v aplikaci jednou a ke klientům se jen připojuje — kdo jedná
 * za dvě firmy, má jeden záznam a změna telefonu platí u obou. Role
 * (jednatel, účetní…) se drží u každého klienta zvlášť. Návrh tyhle
 * obrazovky nemá; skládají se z tabulek a formulářů jako Klienti.
 *
 * Statické `readPerson()`, `personErrors()` a `pickData()` sdílí
 * `ClientController` pro výběr osoby ve formuláři klienta a v jeho
 * Dalších kontaktech.
 */
final class ContactController extends Controller
{
    public function index(): Response
    {
        $q = $this->request()->string('q');
        $rows = [];

        foreach ($this->kernel->contacts()->all($q) as $contact) {
            $primaryAt = array_column(array_filter($contact['clients'], static fn (array $c): bool => $c['is_primary'] === 1), 'name');
            $deleteNote = self::deleteNote($contact['clients']);

            foreach ($contact['clients'] as $i => $client) {
                $contact['clients'][$i] += [
                    'url' => get_url('klienti/' . $client['client_id']),
                    'note' => implode(', ', array_filter([$client['is_primary'] === 1 ? 'hlavní kontakt' : '', $client['role']])),
                ];
            }

            $rows[] = $contact + [
                'name' => self::fullName($contact),
                'url' => get_url('klienti/kontakty/' . (int) $contact['id']),
                'deleteUrl' => get_url('klienti/kontakty/' . (int) $contact['id'] . '/smazat'),
                'deleteBlocked' => $primaryAt !== [] ? 'Nejde smazat — je hlavním kontaktem u: ' . implode(', ', $primaryAt) : '',
                'deleteNote' => $deleteNote,
            ];
        }

        $total = $this->kernel->contacts()->count();

        return $this->view('clients/contacts', [
            'title' => 'Kontaktní osoby',
            'contacts' => $rows,
            'q' => $q,
            'total' => $total,
            'meta' => get_count($total, 'osoba', 'osoby', 'osob') . ' · '
                . get_count($this->kernel->clients()->countActive(), 'klient', 'klienti', 'klientů'),
            'tabsHtml' => ClientController::listTabs('kontakty'),
        ]);
    }

    public function createForm(): Response
    {
        $values = self::emptyValues();
        $values['client_id'] = (string) ($this->request()->int('klient') ?? '');

        return $this->formPage(null, $values, []);
    }

    public function store(): Response
    {
        [$values, $errors] = $this->readForm();
        $contacts = $this->kernel->contacts();
        $person = self::personColumns($values);
        $same = $errors === [] ? $contacts->findSame($person) : null;

        if ($same !== null) {
            $errors['last_name'] = 'Osoba se stejným jménem a e-mailem už v kontaktech je.';
        }

        if ($errors !== []) {
            return $this->formPage(null, $values, $errors, 422, $same);
        }

        $id = $contacts->create($person);
        $clientId = (int) $values['client_id'];

        if ($clientId > 0 && $this->activeClient($clientId) !== null) {
            $contacts->link($clientId, $id, $values['role']);
        }

        $this->kernel->audit()->record(null, '', AuditLog::ACTION_CLIENT, true, 'Přidána kontaktní osoba ' . self::fullName($person));

        return $this->redirectWithFlash('klienti/kontakty/' . $id, 'Osoba je uložená v kontaktech.');
    }

    public function editForm(string $id): Response
    {
        $contact = $this->contactOr404((int) $id);
        $values = self::emptyValues();

        foreach (['first_name', 'last_name', 'email', 'phone'] as $key) {
            $values[$key] = (string) $contact[$key];
        }

        foreach ($this->kernel->contacts()->clientsOf((int) $id) as $client) {
            $values['roles'][$client['client_id']] = $client['role'];
        }

        return $this->formPage($contact, $values, []);
    }

    public function update(string $id): Response
    {
        $contact = $this->contactOr404((int) $id);
        [$values, $errors] = $this->readForm();

        if ($errors !== []) {
            return $this->formPage($contact, $values, $errors, 422);
        }

        $contacts = $this->kernel->contacts();
        $contacts->update((int) $id, self::personColumns($values));
        $clientNames = [];

        foreach ($contacts->clientsOf((int) $id) as $client) {
            $clientNames[] = $client['name'];

            if (isset($values['roles'][$client['client_id']])) {
                $contacts->updateRole($client['client_id'], (int) $id, $values['roles'][$client['client_id']]);
            }
        }

        $this->kernel->audit()->record(null, '', AuditLog::ACTION_CLIENT, true, 'Upravena kontaktní osoba ' . self::fullName($values));

        return $this->redirectWithFlash('klienti/kontakty/' . $id, count($clientNames) > 1
            ? 'Osoba je uložená. Změna platí u všech jejích klientů: ' . implode(', ', $clientNames)
            : 'Osoba je uložená.');
    }

    public function delete(string $id): Response
    {
        $contact = $this->contactOr404((int) $id);
        $primaryAt = $this->kernel->contacts()->primaryAt((int) $id);

        if ($primaryAt !== [] || !$this->kernel->contacts()->delete((int) $id)) {
            return $this->redirectWithFlash('klienti/kontakty/' . $id,
                'Osobu nejde smazat — nejdřív vyberte jinou hlavní osobu u: ' . implode(', ', $primaryAt), 'error');
        }

        $this->kernel->audit()->record(null, '', AuditLog::ACTION_CLIENT, true, 'Smazána kontaktní osoba ' . self::fullName($contact));

        return $this->redirectWithFlash('klienti/kontakty', 'Osoba ' . self::fullName($contact) . ' je smazaná.');
    }

    // -----------------------------------------------------------------
    // Sdílené s ClientController
    // -----------------------------------------------------------------

    /**
     * Jméno, e-mail a telefon osoby z formuláře (pole `first_name`,
     * `last_name`, `email`, `phone`).
     *
     * @return array{first_name: string, last_name: string, email: string, phone: string}
     */
    public static function readPerson(Request $request): array
    {
        return [
            'first_name' => mb_substr($request->string('first_name'), 0, 100),
            'last_name' => mb_substr($request->string('last_name'), 0, 100),
            'email' => mb_strtolower(mb_substr($request->string('email'), 0, 190)),
            'phone' => mb_substr($request->string('phone'), 0, 50),
        ];
    }

    /**
     * Kontrola osoby. Hlavní kontakt klienta potřebuje příjmení i e-mail
     * (chodí na něj reporty), u ostatních stačí jedno z toho.
     *
     * @param array{first_name: string, last_name: string, email: string, phone: string} $person
     * @return array<string, string> pole => hláška
     */
    public static function personErrors(array $person, bool $primary): array
    {
        $errors = [];

        if ($primary && $person['last_name'] === '') {
            $errors['last_name'] = 'Doplňte příjmení kontaktní osoby.';
        }

        if ($primary && $person['email'] === '') {
            $errors['email'] = 'E-mail je povinný — chodí na něj reporty.';
        } elseif (!$primary && $person['last_name'] === '' && $person['email'] === '') {
            $errors['last_name'] = 'Doplňte aspoň příjmení, nebo e-mail.';
        }

        if ($person['email'] !== '' && filter_var($person['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'E-mail nemá platný tvar.';
        }

        return $errors;
    }

    /**
     * Nabídka osob do výběru (`select[data-contact-pick]`, `contact-pick.js`):
     * popisky „Příjmení Jméno · e-mail" (podle příjmení se v seznamu hledá
     * psaním) a JSON s údaji, kterými skript vyplní pole a ukáže, u koho
     * dalšího osoba je.
     *
     * @param array<int, int> $exclude id osob, které do nabídky nepatří (už u klienta jsou)
     * @return array{options: array<int, string>, json: string}
     */
    public static function pickData(Kernel $kernel, array $exclude = []): array
    {
        $options = [];
        $data = [];

        foreach ($kernel->contacts()->all() as $contact) {
            $id = (int) $contact['id'];

            if (in_array($id, $exclude, true)) {
                continue;
            }

            $name = trim($contact['last_name'] . ' ' . $contact['first_name']);
            $options[$id] = implode(' · ', array_filter([$name, (string) $contact['email']]));
            $data[$id] = [
                'first_name' => (string) $contact['first_name'],
                'last_name' => (string) $contact['last_name'],
                'email' => (string) $contact['email'],
                'phone' => (string) $contact['phone'],
                'clients' => (object) array_column($contact['clients'], 'name', 'client_id'),
            ];
        }

        return ['options' => $options, 'json' => (string) json_encode((object) $data, JSON_UNESCAPED_UNICODE)];
    }

    /** @param array<string, mixed> $contact */
    public static function fullName(array $contact): string
    {
        $name = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));

        return $name !== '' ? $name : (string) ($contact['email'] ?? '');
    }

    // -----------------------------------------------------------------
    // Formulář osoby
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function emptyValues(): array
    {
        return ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'client_id' => '', 'role' => '', 'roles' => []];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function readForm(): array
    {
        $request = $this->request();
        $values = self::readPerson($request) + [
            'client_id' => (string) ($request->int('client_id') ?? ''),
            'role' => mb_substr($request->string('role'), 0, 100),
            'roles' => [],
        ];

        foreach ((array) $request->input('roles', []) as $clientId => $role) {
            if (is_scalar($role)) {
                $values['roles'][(int) $clientId] = mb_substr(trim((string) $role), 0, 100);
            }
        }

        return [$values, self::personErrors(self::personColumns($values), false)];
    }

    /** @param array<string, mixed> $values @return array{first_name: string, last_name: string, email: string, phone: string} */
    private static function personColumns(array $values): array
    {
        return [
            'first_name' => (string) $values['first_name'],
            'last_name' => (string) $values['last_name'],
            'email' => (string) $values['email'],
            'phone' => (string) $values['phone'],
        ];
    }

    /**
     * @param array<string, mixed>|null $contact null = nová osoba
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @param int|null $sameId osoba se stejným jménem a e-mailem (odkaz v chybě)
     */
    private function formPage(?array $contact, array $values, array $errors, int $status = 200, ?int $sameId = null): Response
    {
        $clients = [];
        $primaryAt = [];

        if ($contact !== null) {
            foreach ($this->kernel->contacts()->clientsOf((int) $contact['id']) as $client) {
                $clients[] = $client + [
                    'url' => get_url('klienti/' . $client['client_id']),
                    'role' => $values['roles'][$client['client_id']] ?? $client['role'],
                ];

                if ($client['is_primary'] === 1) {
                    $primaryAt[] = $client['name'];
                }
            }
        }

        $name = self::fullName($values);

        return $this->view('clients/contact', [
            'title' => $contact === null ? 'Nová kontaktní osoba' : self::fullName($contact),
            'contact' => $contact,
            'values' => $values,
            'errors' => $errors,
            'clients' => $clients,
            'clientOptions' => $this->kernel->clients()->options(),
            'sameUrl' => $sameId !== null ? get_url('klienti/kontakty/' . $sameId) : '',
            'previewName' => $name !== '' ? $name : 'Jméno a příjmení',
            'deleteBlocked' => $primaryAt !== [] ? 'Smazat ji půjde, až u klientů ' . implode(', ', $primaryAt) . ' vyberete jinou hlavní osobu.' : '',
            'deleteNote' => self::deleteNote($clients),
        ], $status);
    }

    /** @param array<int, array{name: string}> $clients */
    private static function deleteNote(array $clients): string
    {
        return $clients === []
            ? 'Osoba není u žádného klienta. Smazání nejde vrátit.'
            : 'Smazání nejde vrátit. Osoba zmizí i od klientů: ' . implode(', ', array_column($clients, 'name'));
    }

    /** @return array<string, mixed> */
    private function contactOr404(int $id): array
    {
        return $this->kernel->contacts()->find($id) ?? throw HttpException::notFound('Kontaktní osoba neexistuje.');
    }

    /** @return array<string, mixed>|null */
    private function activeClient(int $id): ?array
    {
        $client = $this->kernel->clients()->find($id);

        return $client !== null && $client['archived_at'] === null ? $client : null;
    }
}
