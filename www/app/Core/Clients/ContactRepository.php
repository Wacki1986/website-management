<?php

declare(strict_types=1);

namespace App\Core\Clients;

use App\Core\Db\Connection;

/**
 * Kontaktní osoby — každá jednou, připojená k libovolnému počtu klientů.
 *
 * Jméno, e-mail a telefon patří osobě (změna se projeví u všech jejích
 * klientů), role a příznak „hlavní kontakt" patří vazbě na klienta
 * (`contact_links`), protože u každé firmy můžou být jiné.
 *
 * Archivovaný klient se ve výpisech osob nepočítá — pro aplikaci už
 * neexistuje, jen jeho řádek zůstal v databázi.
 */
final class ContactRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM contacts WHERE id = :id', ['id' => $id]);
    }

    /**
     * Všechny osoby (podle příjmení) s jejich klienty v `clients`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(string $q = ''): array
    {
        $where = '';
        $params = [];

        if ($q !== '') {
            // Hledá se i podle firmy: „kdo je u Kavárny Dobré?"
            $where = 'WHERE ct.first_name LIKE :q1 OR ct.last_name LIKE :q2 OR ct.email LIKE :q3 OR ct.phone LIKE :q4
                      OR CONCAT(ct.first_name, \' \', ct.last_name) LIKE :q5
                      OR EXISTS (SELECT 1 FROM contact_links l JOIN clients c ON c.id = l.client_id
                                 WHERE l.contact_id = ct.id AND c.archived_at IS NULL AND c.name LIKE :q6)';

            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $key) {
                $params[$key] = '%' . $q . '%';
            }
        }

        $contacts = $this->db->select(
            'SELECT ct.* FROM contacts ct ' . $where . ' ORDER BY ct.last_name, ct.first_name, ct.id',
            $params,
        );

        return $this->withClients($contacts);
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM contacts');
    }

    /**
     * Klienti, u kterých osoba je (bez archivovaných), hlavní kontakt první.
     *
     * @return array<int, array{client_id: int, name: string, role: string, is_primary: int}>
     */
    public function clientsOf(int $contactId): array
    {
        return $this->withClients([['id' => $contactId]])[0]['clients'];
    }

    /**
     * Osoby připojené ke klientovi, hlavní kontakt první. Každá nese `role`,
     * `is_primary` a `others` — názvy ostatních klientů, u kterých je.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forClient(int $clientId): array
    {
        $contacts = $this->db->select(
            'SELECT ct.*, l.role, l.is_primary
             FROM contact_links l JOIN contacts ct ON ct.id = l.contact_id
             WHERE l.client_id = :client_id
             ORDER BY l.is_primary DESC, ct.last_name, ct.first_name',
            ['client_id' => $clientId],
        );

        $rows = [];

        foreach ($this->withClients($contacts) as $contact) {
            $contact['others'] = array_values(array_filter(
                $contact['clients'],
                static fn (array $c): bool => $c['client_id'] !== $clientId,
            ));
            $rows[] = $contact;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null hlavní kontakt klienta s `role` */
    public function primaryFor(int $clientId): ?array
    {
        return $this->forClient($clientId)[0] ?? null;
    }

    /** @param array{first_name: string, last_name: string, email: string, phone: string} $person */
    public function create(array $person): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('contacts', $person + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array{first_name: string, last_name: string, email: string, phone: string} $person */
    public function update(int $id, array $person): void
    {
        $this->db->update('contacts', $person + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * Osoba se stejným e-mailem i jménem, jaká už v kontaktech je — aby
     * ruční zadání nezaložilo dvojníka. Pravidlo je stejné jako při převodu
     * starých kontaktů (migrace `2026_10_30_000000_contacts`).
     *
     * @param array{first_name: string, last_name: string, email: string, phone: string} $person
     */
    public function findSame(array $person): ?int
    {
        if ($person['email'] === '') {
            return null;
        }

        // Porovnání bez ohledu na velikost písmen dělá kolace tabulky.
        $id = $this->db->scalar(
            'SELECT id FROM contacts WHERE email = :email AND first_name = :first_name AND last_name = :last_name ORDER BY id LIMIT 1',
            ['email' => $person['email'], 'first_name' => $person['first_name'], 'last_name' => $person['last_name']],
        );

        return $id !== null ? (int) $id : null;
    }

    /**
     * Nová osoba, nebo ta, která už se stejným jménem a e-mailem existuje.
     *
     * @param array{first_name: string, last_name: string, email: string, phone: string} $person
     */
    public function findOrCreate(array $person): int
    {
        $id = $this->findSame($person);

        if ($id === null) {
            return $this->create($person);
        }

        // Telefon jen doplnit — přepsat by ho měla úprava osoby, ne přidání.
        if ($person['phone'] !== '') {
            $this->db->execute("UPDATE contacts SET phone = :phone WHERE id = :id AND phone = ''", ['phone' => $person['phone'], 'id' => $id]);
        }

        return $id;
    }

    /** Připojí osobu ke klientovi jako další kontakt; už připojené jen změní roli. */
    public function link(int $clientId, int $contactId, string $role): void
    {
        $this->db->execute(
            'INSERT INTO contact_links (client_id, contact_id, role, is_primary, created_at)
             VALUES (:client_id, :contact_id, :role, 0, :created_at)
             ON DUPLICATE KEY UPDATE role = VALUES(role)',
            ['client_id' => $clientId, 'contact_id' => $contactId, 'role' => $role, 'created_at' => date('Y-m-d H:i:s')],
        );
    }

    /**
     * Hlavní kontakt klienta. Dosavadní hlavní osoba od klienta neodchází,
     * jen se z ní stane další kontakt — odebrat ji jde jedním klikem, kdežto
     * omylem ztracený kontakt by se musel hledat.
     */
    public function setPrimary(int $clientId, int $contactId, string $role): void
    {
        $this->db->transaction(function () use ($clientId, $contactId, $role): void {
            $this->db->execute('UPDATE contact_links SET is_primary = 0 WHERE client_id = :client_id', ['client_id' => $clientId]);
            $this->db->execute(
                'INSERT INTO contact_links (client_id, contact_id, role, is_primary, created_at)
                 VALUES (:client_id, :contact_id, :role, 1, :created_at)
                 ON DUPLICATE KEY UPDATE role = VALUES(role), is_primary = 1',
                ['client_id' => $clientId, 'contact_id' => $contactId, 'role' => $role, 'created_at' => date('Y-m-d H:i:s')],
            );
        });
    }

    public function updateRole(int $clientId, int $contactId, string $role): void
    {
        $this->db->execute(
            'UPDATE contact_links SET role = :role WHERE client_id = :client_id AND contact_id = :contact_id',
            ['role' => $role, 'client_id' => $clientId, 'contact_id' => $contactId],
        );
    }

    /**
     * Odpojí osobu od klienta; osoba v kontaktech zůstává. Hlavní kontakt se
     * neodpojuje — bez něj by klient neměl komu psát.
     */
    public function unlink(int $clientId, int $contactId): bool
    {
        return $this->db->execute(
            'DELETE FROM contact_links WHERE client_id = :client_id AND contact_id = :contact_id AND is_primary = 0',
            ['client_id' => $clientId, 'contact_id' => $contactId],
        ) > 0;
    }

    /**
     * Názvy klientů, u kterých je osoba hlavním kontaktem. Takovou osobu
     * nejde smazat, dokud jí klienti nenajdou náhradu.
     *
     * @return array<int, string>
     */
    public function primaryAt(int $contactId): array
    {
        $names = [];

        foreach ($this->clientsOf($contactId) as $client) {
            if ($client['is_primary'] === 1) {
                $names[] = $client['name'];
            }
        }

        return $names;
    }

    /** Smaže osobu i její vazby. Hlavní kontakt nějakého klienta nesmaže. */
    public function delete(int $contactId): bool
    {
        if ($this->primaryAt($contactId) !== []) {
            return false;
        }

        return $this->db->delete('contacts', ['id' => $contactId]) > 0;
    }

    /**
     * Doplní každé osobě `clients` — klienty, u kterých je (bez archivovaných).
     *
     * @param array<int, array<string, mixed>> $contacts
     * @return array<int, array<string, mixed>>
     */
    private function withClients(array $contacts): array
    {
        if ($contacts === []) {
            return [];
        }

        $ids = array_map(static fn (array $c): int => (int) $c['id'], $contacts);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $byContact = [];

        $stmt = $this->db->pdo()->prepare(
            "SELECT l.contact_id, l.client_id, l.role, l.is_primary, c.name
             FROM contact_links l JOIN clients c ON c.id = l.client_id
             WHERE l.contact_id IN ($placeholders) AND c.archived_at IS NULL
             ORDER BY l.is_primary DESC, c.name",
        );
        $stmt->execute($ids);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $link) {
            $byContact[(int) $link['contact_id']][] = [
                'client_id' => (int) $link['client_id'],
                'name' => (string) $link['name'],
                'role' => (string) $link['role'],
                'is_primary' => (int) $link['is_primary'],
            ];
        }

        foreach ($contacts as $i => $contact) {
            $contacts[$i]['clients'] = $byContact[(int) $contact['id']] ?? [];
        }

        return $contacts;
    }
}
