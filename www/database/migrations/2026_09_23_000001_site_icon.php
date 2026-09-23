<?php

declare(strict_types=1);

/**
 * Ikona webu v seznamech místo koleček s iniciálami.
 *
 * `icon` = jméno souboru ve `storage/site-icons/` (prázdné = iniciály),
 * `icon_source` = `auto` (stažená favicona) | `manual` (nahrané logo —
 * automatika ho nepřepíše) | '' (zatím nic), `icon_checked_at` = kdy se
 * naposledy zkoušelo stáhnout.
 *
 * Sloupce se přidávají jen když chybí: testy mažou evidenci migrací
 * a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $columns = [
        'icon' => "ALTER TABLE `sites` ADD COLUMN `icon` varchar(64) NOT NULL DEFAULT '' AFTER `wp_login_user`",
        'icon_source' => "ALTER TABLE `sites` ADD COLUMN `icon_source` varchar(8) NOT NULL DEFAULT '' AFTER `icon`",
        'icon_checked_at' => 'ALTER TABLE `sites` ADD COLUMN `icon_checked_at` datetime DEFAULT NULL AFTER `icon_source`',
    ];

    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = :column",
    );

    foreach ($columns as $column => $sql) {
        $exists->execute(['column' => $column]);

        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
};
