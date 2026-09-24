<?php

declare(strict_types=1);

/**
 * Údaje o pluginech z adresáře wordpress.org (`PluginDirectory`): kdy
 * plugin naposledy vyšel, do jaké verze WP je testovaný, jestli ho
 * adresář nestáhl (a proč). Jeden řádek na plugin, sdílený všemi weby.
 *
 * `site_snapshots` dostává počty problémových pluginů webu — z nich čte
 * stav webu (výpis, dashboard) stejně jako ostatní údaje ze snímku.
 *
 * Tabulka i sloupce se zakládají jen když chybí: testy mažou evidenci
 * migrací a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `plugin_directory` (
          `slug` varchar(191) NOT NULL,
          `status` varchar(10) NOT NULL DEFAULT 'found' COMMENT 'found | closed | missing (není na wordpress.org)',
          `name` varchar(200) NOT NULL DEFAULT '',
          `version` varchar(40) NOT NULL DEFAULT '',
          `last_updated` date DEFAULT NULL,
          `tested` varchar(20) NOT NULL DEFAULT '',
          `active_installs` int(10) unsigned DEFAULT NULL,
          `closed_date` date DEFAULT NULL,
          `closed_reason` varchar(100) NOT NULL DEFAULT '',
          `checked_at` datetime NOT NULL,
          PRIMARY KEY (`slug`),
          KEY `idx_checked` (`checked_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );

    foreach (['plugins_abandoned', 'plugins_closed', 'plugins_insecure'] as $column) {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_snapshots' AND COLUMN_NAME = '" . $column . "'",
        );

        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `site_snapshots` ADD COLUMN `' . $column . '` smallint(6) NOT NULL DEFAULT 0 AFTER `security_updates`');
        }
    }
};
