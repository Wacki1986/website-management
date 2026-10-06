<?php

declare(strict_types=1);

namespace App\Core\Projects;

use App\Core\Db\Connection;

/**
 * Projekty — „pekarnanovak.cz" jako celek: klient → projekt → web(y),
 * později domény, hosting, e-maily a přístupy (plán v `dev/docs/02-todo.md`).
 *
 * Klienta drží projekt. Weby mají v `sites.client_id` jeho kopii, kterou
 * udržuje jen tahle třída (`update()`, `attachSite()`) — čtou ji alerty,
 * reporty, monitor i dashboard. Kdo by klienta webu měnil napřímo,
 * rozešel by se s projektem.
 */
final class ProjectRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null s `client_name` */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT p.*, c.name AS client_name FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE p.id = :id',
            ['id' => $id],
        );
    }

    /**
     * Seznam s klientem a počtem webů v monitoringu.
     *
     * @param array{q?: string, client?: ?int} $filter `q` hledá v názvu projektu, klientovi i adresách webů
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filter = []): array
    {
        $conditions = ['1 = 1'];
        $params = [];

        if (($filter['q'] ?? '') !== '') {
            $conditions[] = '(p.name LIKE :q1 OR c.name LIKE :q2 OR EXISTS (SELECT 1 FROM sites q WHERE q.project_id = p.id AND q.removed_at IS NULL AND (q.url LIKE :q3 OR q.name LIKE :q4)))';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $filter['q'] . '%';
        }

        if (($filter['client'] ?? null) !== null) {
            $conditions[] = 'p.client_id = :client_id';
            $params['client_id'] = (int) $filter['client'];
        }

        return $this->db->select(
            'SELECT p.*, c.name AS client_name,
                    (SELECT COUNT(*) FROM sites s WHERE s.project_id = p.id AND s.removed_at IS NULL) AS site_count
             FROM projects p
             LEFT JOIN clients c ON c.id = p.client_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY p.name',
            $params,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        return $this->all(['client' => $clientId]);
    }

    /** Projekty bez klienta — nabídka při zakládání a v detailu klienta. @return array<int, array<string, mixed>> */
    public function unassigned(): array
    {
        return $this->db->select(
            'SELECT p.*, (SELECT COUNT(*) FROM sites s WHERE s.project_id = p.id AND s.removed_at IS NULL) AS site_count
             FROM projects p WHERE p.client_id IS NULL ORDER BY p.name',
        );
    }

    /**
     * Nabídka do výběru (web → projekt). U stejně pojmenovaných projektů
     * různých klientů rozhodne klient v závorce.
     *
     * @return array<int, string> id => „název (klient)"
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->db->select('SELECT p.id, p.name, c.name AS client_name FROM projects p LEFT JOIN clients c ON c.id = p.client_id ORDER BY p.name') as $row) {
            $options[(int) $row['id']] = (string) $row['name'] . ($row['client_name'] !== null ? ' (' . $row['client_name'] . ')' : '');
        }

        return $options;
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM projects');
    }

    /** @param array{name: string, client_id?: ?int, note?: ?string} $data */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('projects', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Úprava projektu. Změna klienta se propíše do všech jeho webů
     * (i odebraných — archiv má ukazovat, čí web byl).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->update('projects', $data + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);

        if (array_key_exists('client_id', $data)) {
            $this->db->execute('UPDATE sites SET client_id = :client_id WHERE project_id = :id', ['client_id' => $data['client_id'], 'id' => $id]);
        }
    }

    /** Web patří do projektu a přebírá jeho klienta. */
    public function attachSite(int $siteId, int $projectId): void
    {
        $this->db->execute(
            'UPDATE sites s JOIN projects p ON p.id = :project_id SET s.project_id = p.id, s.client_id = p.client_id WHERE s.id = :site_id',
            ['project_id' => $projectId, 'site_id' => $siteId],
        );
    }

    /** Nový projekt jen pro jeden web — při přidání webu bez vybraného projektu. */
    public function createForSite(int $siteId, string $name, ?int $clientId): int
    {
        $id = $this->create(['name' => $name, 'client_id' => $clientId]);
        $this->attachSite($siteId, $id);

        return $id;
    }

    /** Přiřazení projektů klientovi (formulář a detail klienta). @param array<int, int> $projectIds */
    public function assignToClient(array $projectIds, int $clientId): void
    {
        foreach ($projectIds as $projectId) {
            $this->update((int) $projectId, ['client_id' => $clientId]);
        }
    }

    /** Kolik webů v monitoringu projekt má — projekt s weby nejde smazat. */
    public function activeSiteCount(int $id): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM sites WHERE project_id = :id AND removed_at IS NULL', ['id' => $id]);
    }

    /**
     * Smazání prázdného projektu. Odebrané weby v archivu projekt ztratí
     * (cizí klíč SET NULL), weby v monitoringu ho zablokují.
     *
     * @return bool false = projekt má weby, nic se nesmazalo
     */
    public function delete(int $id): bool
    {
        if ($this->activeSiteCount($id) > 0) {
            return false;
        }

        $this->db->delete('projects', ['id' => $id]);

        return true;
    }
}
