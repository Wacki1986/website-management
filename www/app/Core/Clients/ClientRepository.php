<?php

declare(strict_types=1);

namespace App\Core\Clients;

use App\Core\Db\Connection;

/**
 * Klienti (firmy).
 *
 * Klient je jen kontakt: frekvence reportů, servis i prahy alertů se
 * nastavují u konkrétního webu (návrh `novy-klient.html`). Kontaktní osoby
 * jsou sdílené mezi klienty a žijí v `ContactRepository`; sloupce
 * `clients.email`/`phone` jsou pozůstatek staré kopie hlavního kontaktu
 * a nečtou se.
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
            // Hledá se ve všech osobách klienta, nejen v hlavní.
            $conditions[] = '(c.name LIKE :q1 OR EXISTS (
                SELECT 1 FROM contact_links l2 JOIN contacts p2 ON p2.id = l2.contact_id
                WHERE l2.client_id = c.id AND (p2.email LIKE :q2 OR p2.first_name LIKE :q3 OR p2.last_name LIKE :q4)))';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $filter['q'] . '%';
        }

        $having = match ($filter['filter'] ?? '') {
            'multi' => ' HAVING site_count > 1',
            // OSVČ = bez IČO firmy… IČO má i OSVČ; „bez firmy" = bez DIČ.
            'person' => " HAVING c.vat_id = ''",
            default => '',
        };

        return $this->db->select(
            "SELECT c.*, p.first_name, p.last_name, p.email AS contact_email, p.phone AS contact_phone, l.role AS contact_role,
                    (SELECT COUNT(*) FROM sites s WHERE s.client_id = c.id AND s.removed_at IS NULL) AS site_count
             FROM clients c
             LEFT JOIN contact_links l ON l.client_id = c.id AND l.is_primary = 1
             LEFT JOIN contacts p ON p.id = l.contact_id
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

    /** Archivace — projekty i weby zůstanou bez klienta (návrh: karta „Odebrat klienta"). */
    public function archive(int $id): void
    {
        $this->db->execute('UPDATE projects SET client_id = NULL WHERE client_id = :id', ['id' => $id]);
        $this->db->execute('UPDATE sites SET client_id = NULL WHERE client_id = :id', ['id' => $id]);
        $this->update($id, ['archived_at' => date('Y-m-d H:i:s')]);
    }
}
