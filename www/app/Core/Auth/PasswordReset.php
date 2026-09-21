<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Db\Connection;

/**
 * Tokeny pro obnovu zapomenutého hesla e-mailem.
 *
 * Stejný vzor jako `RememberMe` — selector + hash validatoru, timing-safe
 * porovnání — jen jednorázový: token se po použití smaže, nerotuje se.
 * Odkaz v e-mailu nese `selector:validator`, stejně jako cookie.
 */
final class PasswordReset
{
    private const LIFETIME_MINUTES = 60;

    public function __construct(private readonly Connection $db)
    {
    }

    /** Založí token a vrátí `selector:validator` k vložení do odkazu. */
    public function issue(int $userId): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        $this->db->insert('password_resets', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => date('Y-m-d H:i:s', time() + (self::LIFETIME_MINUTES * 60)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $selector . ':' . $validator;
    }

    /** Platnost tokenu bez spotřebování — pro zobrazení formuláře. */
    public function verify(string $token): ?int
    {
        $row = $this->find($token);

        return $row !== null ? (int) $row['user_id'] : null;
    }

    /** Ověří a rovnou smaže — jednorázové použití. Vrací ID uživatele, nebo null. */
    public function consume(string $token): ?int
    {
        $row = $this->find($token);

        if ($row === null) {
            return null;
        }

        $this->db->delete('password_resets', ['id' => (int) $row['id']]);

        return (int) $row['user_id'];
    }

    /** Zruší všechny čekající tokeny uživatele (po úspěšné obnově). */
    public function invalidateFor(int $userId): void
    {
        $this->db->delete('password_resets', ['user_id' => $userId]);
    }

    /** Úklid prošlých tokenů — správa nemá cron, volá se příležitostně. */
    public function purgeExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM password_resets WHERE expires_at < :now',
            ['now' => date('Y-m-d H:i:s')],
        );
    }

    /** @return array<string, mixed>|null */
    private function find(string $token): ?array
    {
        if (!str_contains($token, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $token, 2);

        $row = $this->db->selectOne(
            'SELECT * FROM password_resets WHERE selector = :selector',
            ['selector' => $selector],
        );

        if ($row === null) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $this->db->delete('password_resets', ['id' => (int) $row['id']]);

            return null;
        }

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            return null;
        }

        return $row;
    }
}
