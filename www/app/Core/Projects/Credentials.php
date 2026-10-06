<?php

declare(strict_types=1);

namespace App\Core\Projects;

use App\Core\Db\Connection;
use App\Core\Security\Secrets;
use SensitiveParameter;

/**
 * Trezor přístupů projektu — FTP/SFTP, administrace hostingu, databáze,
 * e-mailové schránky, registrátor domény, jiné.
 *
 * Přístupy patří projektu, ne webu: hosting i e-maily žijí dál, i když se
 * web odebere nebo vymění. `site_id` jen poznamenává, ke kterému webu se
 * přístup váže (založený ze záložky Přístupy u webu).
 *
 * Dřív ležely přístupy k FTP v `.vscode/sftp.json` v čitelné podobě na
 * OneDrivu, tedy na každém počítači a v cloudu. Tady je heslo i poznámka
 * šifrované přes `Secrets` stejně jako API klíče webů: klíč je jen
 * v `config/env.php` na serveru, databáze ani její záloha heslo nenese.
 *
 * Pravidla, na kterých trezor stojí:
 *  - hesla nikdy nejdou do výpisu ani do HTML stránky — výpis vrací jen
 *    příznak „heslo je uložené", samotné heslo `password()` na vyžádání
 *    (oko / kopírování, `CredentialController::password()`);
 *  - nikdy do e-mailu, reportu ani exportu — nic z toho tuhle třídu nevolá;
 *  - vidí je jen účet se zapnutým dvoufázovým přihlášením (hlídá controller).
 */
final class Credentials
{
    /**
     * Druhy přístupů: popisek, ikona a která pole druh má.
     *
     * Pole navíc (host u hostingu, databáze u FTP) by formulář jen
     * nafukovala — kdo potřebuje něco mimo, má druh „Jiný přístup"
     * a poznámku. U e-mailu je `username` adresa schránky a `label`,
     * kde schránka běží.
     */
    public const KINDS = [
        'ftp' => ['label' => 'FTP / SFTP', 'icon' => 'download', 'fields' => ['protocol', 'host', 'port', 'username', 'password', 'note']],
        'hosting' => ['label' => 'Hosting', 'icon' => 'server', 'fields' => ['url', 'username', 'password', 'note']],
        'database' => ['label' => 'Databáze', 'icon' => 'database', 'fields' => ['url', 'host', 'database_name', 'username', 'password', 'note']],
        'email' => ['label' => 'E-mail', 'icon' => 'mail', 'fields' => ['label', 'username', 'password', 'url', 'host', 'note']],
        'registrar' => ['label' => 'Registrátor domény', 'icon' => 'globe', 'fields' => ['url', 'username', 'password', 'note']],
        'other' => ['label' => 'Jiný přístup', 'icon' => 'key', 'fields' => ['label', 'url', 'username', 'password', 'note']],
    ];

    /** Protokoly FTP a jejich výchozí porty (prázdný port = výchozí). */
    public const PROTOCOLS = [
        'sftp' => ['label' => 'SFTP (přes SSH)', 'port' => 22],
        'ftps' => ['label' => 'FTPS (FTP se šifrováním TLS)', 'port' => 21],
        'ftp' => ['label' => 'FTP (bez šifrování)', 'port' => 21],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Secrets $secrets,
    ) {
    }

    /** Dá se vůbec ukládat? Bez `app_key` ne — viz `Secrets::isReady()`. */
    public function isAvailable(): bool
    {
        return $this->secrets->isReady();
    }

    /**
     * Přístupy projektu bez hesel — pro výpis. Poznámka je rozšifrovaná,
     * heslo nahrazuje příznak `has_password`.
     *
     * Pořadí: druhy tak, jak je má `KINDS` (FTP nahoře, na to se sahá
     * nejčastěji), uvnitř druhu podle založení.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forProject(int $projectId): array
    {
        $rows = $this->db->select(
            "SELECT * FROM credentials WHERE project_id = :project
             ORDER BY FIELD(kind, '" . implode("', '", array_keys(self::KINDS)) . "'), id",
            ['project' => $projectId],
        );

        return array_map($this->withoutPassword(...), $rows);
    }

    /** Počty po druzích — souhrn v detailu projektu (bez odemčení trezoru). @return array<string, int> */
    public function countsByKind(int $projectId): array
    {
        $counts = [];

        foreach ($this->db->select('SELECT kind, COUNT(*) AS n FROM credentials WHERE project_id = :project GROUP BY kind', ['project' => $projectId]) as $row) {
            $counts[(string) $row['kind']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Jeden přístup projektu bez hesla (formulář úpravy) — null, když k projektu nepatří.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $projectId, int $id): ?array
    {
        $row = $this->row($projectId, $id);

        return $row !== null ? $this->withoutPassword($row) : null;
    }

    /** Heslo v čitelné podobě — jen pro oko a kopírování. Null = není / nejde rozšifrovat. */
    public function password(int $projectId, int $id): ?string
    {
        $stored = (string) ($this->row($projectId, $id)['password'] ?? '');

        return $stored !== '' ? $this->secrets->decrypt($stored) : null;
    }

    public function countForProject(int $projectId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM credentials WHERE project_id = :project', ['project' => $projectId]);
    }

    /**
     * @param int|null $siteId web, ze kterého se přístup zakládá (null = z projektu)
     * @param array<string, mixed> $data pole z `KINDS[kind]['fields']`; `password`
     *        v čitelné podobě (zašifruje se tady)
     */
    public function create(int $projectId, ?int $siteId, string $kind, array $data, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('credentials', $this->prepare($kind, $data, true) + [
            'project_id' => $projectId,
            'site_id' => $siteId,
            'kind' => $kind,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Úprava. Prázdné heslo = heslo se nemění (formulář ho nikdy nevyplňuje,
     * takže prázdné pole neznamená „smazat").
     *
     * @param array<string, mixed> $data
     */
    public function update(int $projectId, int $id, string $kind, array $data): void
    {
        $this->db->update(
            'credentials',
            $this->prepare($kind, $data, false) + ['updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id, 'project_id' => $projectId],
        );
    }

    public function delete(int $projectId, int $id): void
    {
        $this->db->delete('credentials', ['id' => $id, 'project_id' => $projectId]);
    }

    /**
     * Adresa pro rychlé připojení FileZilly: `sftp://jmeno:heslo@server:port`.
     *
     * Vloží se do pole „Hostitel" v liště Rychlé připojení a FileZilla si
     * z ní vezme všechno najednou. Jméno a heslo musí být zakódované —
     * zavináč nebo dvojtečka v hesle by adresu rozbily.
     *
     * @param array<string, mixed> $credential řádek z `forProject()` / `find()`
     */
    public static function fileZillaUrl(array $credential, #[SensitiveParameter] string $password): string
    {
        $protocol = isset(self::PROTOCOLS[$credential['protocol']]) ? (string) $credential['protocol'] : 'ftp';
        $port = $credential['port'] !== null ? (int) $credential['port'] : self::PROTOCOLS[$protocol]['port'];

        return $protocol . '://' . rawurlencode((string) $credential['username'])
            . ($password !== '' ? ':' . rawurlencode($password) : '')
            . '@' . $credential['host'] . ':' . $port;
    }

    /**
     * Sloupce k uložení — jen ta pole, která druh má; ostatní se vyprázdní,
     * aby po změně druhu nezůstalo nic viset.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepare(string $kind, array $data, bool $isNew): array
    {
        $fields = self::KINDS[$kind]['fields'];
        $has = static fn (string $field): bool => in_array($field, $fields, true);
        $text = static fn (string $field, int $max): string => $has($field) ? mb_substr(trim((string) ($data[$field] ?? '')), 0, $max) : '';

        $port = $has('port') ? (int) ($data['port'] ?? 0) : 0;
        $note = $text('note', 5000);
        $password = $has('password') ? (string) ($data['password'] ?? '') : '';

        $row = [
            'label' => $text('label', 150),
            'protocol' => $has('protocol') && isset(self::PROTOCOLS[$data['protocol'] ?? '']) ? (string) $data['protocol'] : '',
            'host' => $text('host', 255),
            'port' => $port > 0 && $port <= 65535 ? $port : null,
            'url' => $text('url', 500),
            'database_name' => $text('database_name', 190),
            'username' => $text('username', 255),
            'note' => $note !== '' ? $this->secrets->encrypt($note) : null,
        ];

        // Heslo se při úpravě přepisuje, jen když přišlo nové.
        if ($password !== '' || $isNew) {
            $row['password'] = $password !== '' ? $this->secrets->encrypt($password) : null;
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function row(int $projectId, int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM credentials WHERE id = :id AND project_id = :project',
            ['id' => $id, 'project' => $projectId],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withoutPassword(array $row): array
    {
        $row['has_password'] = (string) ($row['password'] ?? '') !== '';
        $row['note'] = (string) ($row['note'] ?? '') !== '' ? ($this->secrets->decrypt((string) $row['note']) ?? '') : '';
        $row['port'] = $row['port'] !== null ? (int) $row['port'] : null;
        unset($row['password']);

        return $row;
    }
}
