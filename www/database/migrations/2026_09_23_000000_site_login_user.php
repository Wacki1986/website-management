<?php

declare(strict_types=1);

/**
 * Účet, do kterého tlačítko „wp-admin" přihlásí jedním klikem — vlastní
 * u webu. Prázdné = výchozí účet z Nastavení → Monitoring.
 *
 * Sloupec se přidává jen když chybí: testy mažou evidenci migrací
 * a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'wp_login_user'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `sites` ADD COLUMN `wp_login_user` varchar(100) NOT NULL DEFAULT '' AFTER `admin_url`");
    }
};
