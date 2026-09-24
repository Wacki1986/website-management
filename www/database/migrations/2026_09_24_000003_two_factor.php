<?php

declare(strict_types=1);

/**
 * Dvoufázové přihlášení (`Auth\TwoFactor`): tajemství pro aplikaci
 * v telefonu (šifrované přes `Secrets`), kdy se zapnulo, poslední použitý
 * 30s krok (kód nejde použít dvakrát) a hashe záložních kódů jako JSON.
 *
 * Sloupce se přidávají jen když chybí: testy mažou evidenci migrací
 * a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $columns = [
        'totp_secret' => "varchar(255) DEFAULT NULL COMMENT 'šifrované (Secrets)'",
        'totp_enabled_at' => 'datetime DEFAULT NULL',
        'totp_last_step' => 'bigint(20) DEFAULT NULL',
        'recovery_codes' => "text DEFAULT NULL COMMENT 'JSON pole SHA-256 hashů nepoužitých záložních kódů'",
    ];

    foreach ($columns as $column => $definition) {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = '" . $column . "'",
        );

        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `users` ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
};
