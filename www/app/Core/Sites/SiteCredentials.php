<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Core\Db\Connection;
use App\Core\Security\Secrets;
use SensitiveParameter;

/**
 * Trezor přístupů u webu — FTP/SFTP, administrace hostingu, databáze, jiné.
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
final class SiteCredentials
{
    /**
     * Druhy přístupů: popisek, ikona a která pole druh má.
     *
     * Pole navíc (host u hostingu, databáze u FTP) by formulář jen
     * nafukovala — kdo potřebuje něco mimo, má druh „Jiný přístup"
     * a poznámku.
     */
    public const KINDS = [
        'ftp' => ['label' => 'FTP / SFTP', 'icon' => 'download', 'fields' => ['protocol', 'host', 'port', 'username', 'password', 'note']],
        'hosting' => ['label' => 'Hosting', 'icon' => 'globe', 'fields' => ['url', 'username', 'password', 'note']],
        'database' => ['label' => 'Databáze', 'icon' => 'database', 'fields' => ['url', 'host', 'database_name', 'username', 'password', 'note']],
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
     * Přístupy webu bez hesel — pro výpis. Poznámka je rozšifrovaná,
     * heslo nahrazuje příznak `has_password`.
     *
     * Pořadí: druhy tak, jak je má `KINDS` (FTP nahoře, na to se sahá
     * nejčastěji), uvnitř druhu podle založení.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forSite(int $siteId): array
    {
        $rows = $this->db->select(
            "SELECT * FROM site_credentials WHERE site_id = :site ORDER BY FIELD(kind, 'ftp', 'hosting', 'database', 'other'), id",
            ['site' => $siteId],
        );

        return array_map($this->withoutPassword(...), $rows);
    }

    /**
     * Jeden přístup webu bez hesla (formulář úpravy) — null, když k webu nepatří.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $siteId, int $id): ?array
    {
        $row = $this->row($siteId, $id);

        return $row !== null ? $this->withoutPassword($row) : null;
    }

    /** Heslo v čitelné podobě — jen pro oko a kopírování. Null = není / nejde rozšifrovat. */
    public function password(int $siteId, int $id): ?string
    {
        $stored = (string) ($this->row($siteId, $id)['password'] ?? '');

        return $stored !== '' ? $this->secrets->decrypt($stored) : null;
    }

    public function countForSite(int $siteId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM site_credentials WHERE site_id = :site', ['site' => $siteId]);
    }

    /**
     * @param array<string, mixed> $data pole z `KINDS[kind]['fields']`; `password`
     *        v čitelné podobě (zašifruje se tady)
     */
    public function create(int $siteId, string $kind, array $data, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('site_credentials', $this->prepare($kind, $data, true) + [
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
    public function update(int $siteId, int $id, string $kind, array $data): void
    {
        $this->db->update(
            'site_credentials',
            $this->prepare($kind, $data, false) + ['updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id, 'site_id' => $siteId],
        );
    }

    public function delete(int $siteId, int $id): void
    {
        $this->db->delete('site_credentials', ['id' => $id, 'site_id' => $siteId]);
    }

    public function deleteForSite(int $siteId): void
    {
        $this->db->delete('site_credentials', ['site_id' => $siteId]);
    }

    /**
     * Adresa pro rychlé připojení FileZilly: `sftp://jmeno:heslo@server:port`.
     *
     * Vloží se do pole „Hostitel" v liště Rychlé připojení a FileZilla si
     * z ní vezme všechno najednou. Jméno a heslo musí být zakódované —
     * zavináč nebo dvojtečka v hesle by adresu rozbily.
     *
     * @param array<string, mixed> $credential řádek z `forSite()` / `find()`
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
    private function row(int $siteId, int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM site_credentials WHERE id = :id AND site_id = :site',
            ['id' => $id, 'site' => $siteId],
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
