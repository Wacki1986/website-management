<?php

declare(strict_types=1);

namespace App\Core\Clients;

use App\Core\Db\Connection;

/**
 * Klienti (firmy) a jejich kontaktní osoby.
 *
 * Klient je jen kontakt: frekvence reportů, servis i prahy alertů se
 * nastavují u konkrétního webu (návrh `novy-klient.html`). Hlavní kontakt
 * se drží i v řádku klienta (`email`, `phone`), aby seznam nepotřeboval
 * join na každý řádek.
 */
final class ClientRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM clients WHERE id = :id', ['id' => $id]);
    }

    /**
     * Seznam s počtem webů a hlavním kontaktem, bez archivovaných.
     *
     * @param array{q?: string, filter?: string} $filter `filter`: '' | multi | person
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filter = []): array
    {
        $conditions = ['c.archived_at IS NULL'];
        $params = [];

        if (($filter['q'] ?? '') !== '') {
            $conditions[] = '(c.name LIKE :q1 OR c.email LIKE :q2 OR p.first_name LIKE :q3 OR p.last_name LIKE :q4)';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $filter['q'] . '%';
        }

        $having = match ($filter['filter'] ?? '') {
            'multi' => ' HAVING site_count > 1',
            // OSVČ = bez IČO firmy… IČO má i OSVČ; „bez firmy" = bez DIČ.
            'person' => " HAVING c.vat_id = ''",
            default => '',
        };

        return $this->db->select(
            "SELECT c.*, p.first_name, p.last_name, p.role AS contact_role,
                    (SELECT COUNT(*) FROM sites s WHERE s.client_id = c.id AND s.removed_at IS NULL) AS site_count
             FROM clients c
             LEFT JOIN client_contacts p ON p.client_id = c.id AND p.is_primary = 1
             WHERE " . implode(' AND ', $conditions) . '
             GROUP BY c.id' . $having . '
             ORDER BY c.name',
            $params,
        );
    }

    /** Nabídka do výběru (web → klient). @return array<int, string> id => název */
    public function options(): array
    {
        $options = [];

        foreach ($this->db->select('SELECT id, name FROM clients WHERE archived_at IS NULL ORDER BY name') as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM clients WHERE archived_at IS NULL');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('clients', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('clients', $data + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** Archivace — weby zůstanou v monitoringu bez klienta (návrh: karta „Odebrat klienta"). */
    public function archive(int $id): void
    {
        $this->db->execute('UPDATE sites SET client_id = NULL WHERE client_id = :id', ['id' => $id]);
        $this->update($id, ['archived_at' => date('Y-m-d H:i:s')]);
    }

    // -----------------------------------------------------------------
    // Kontakty
    // -----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> hlavní kontakt první */
    public function contacts(int $clientId): array
    {
        return $this->db->select(
            'SELECT * FROM client_contacts WHERE client_id = :client_id ORDER BY is_primary DESC, last_name, first_name',
            ['client_id' => $clientId],
        );
    }

    /** @return array<string, mixed>|null */
    public function primaryContact(int $clientId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM client_contacts WHERE client_id = :client_id ORDER BY is_primary DESC, id LIMIT 1',
            ['client_id' => $clientId],
        );
    }

    /**
     * Uložení hlavního kontaktu — vytvoří, nebo přepíše ten stávající —
     * a promítne e-mail s telefonem do řádku klienta.
     *
     * @param array{first_name: string, last_name: string, role: string, email: string, phone: string} $contact
     */
    public function savePrimaryContact(int $clientId, array $contact): void
    {
        $existing = $this->db->selectOne(
            'SELECT id FROM client_contacts WHERE client_id = :client_id AND is_primary = 1',
            ['client_id' => $clientId],
        );

        if ($existing !== null) {
            $this->db->update('client_contacts', $contact, ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('client_contacts', $contact + [
                'client_id' => $clientId,
                'is_primary' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->update($clientId, ['email' => $contact['email'], 'phone' => $contact['phone']]);
    }

    /** @param array<string, mixed> $contact */
    public function addContact(int $clientId, array $contact): int
    {
        return $this->db->insert('client_contacts', $contact + [
            'client_id' => $clientId,
            'is_primary' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function removeContact(int $clientId, int $contactId): void
    {
        // Hlavní kontakt se nemaže — bez něj by klient neměl komu psát.
        $this->db->execute(
            'DELETE FROM client_contacts WHERE id = :id AND client_id = :client_id AND is_primary = 0',
            ['id' => $contactId, 'client_id' => $clientId],
        );
    }
}
