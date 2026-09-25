<?php

declare(strict_types=1);

/**
 * Modul SEO: poslední stav ve `site_snapshots` (čte ho seznam webů, záložka
 * SEO a alerty — stejně jako ostatní údaje ze snímku) a denní historie
 * v `seo_days` pro trend a pro „o 4 body lépe než minule" v reportu.
 *
 * `seo_average` NULL = SEO plugin na webu není nebo žádnou stránku
 * nehodnotil; `seo_indexable` NULL = data zatím nepřišla.
 *
 * Tabulka i sloupce se zakládají jen když chybí: testy mažou evidenci
 * migrací a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $columns = [
        'seo_plugin' => "varchar(20) NOT NULL DEFAULT '' COMMENT 'rank-math | yoast | prázdné = žádný'",
        'seo_average' => 'tinyint(3) unsigned DEFAULT NULL',
        'seo_bad' => 'smallint(6) DEFAULT NULL',
        'seo_indexable' => 'tinyint(1) DEFAULT NULL',
        'seo_checked_at' => 'datetime DEFAULT NULL',
    ];
    $after = 'plugins_insecure';

    foreach ($columns as $column => $definition) {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_snapshots' AND COLUMN_NAME = '" . $column . "'",
        );

        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `site_snapshots` ADD COLUMN `' . $column . '` ' . $definition . ' AFTER `' . $after . '`');
        }

        $after = $column;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `seo_days` (
          `site_id` int(10) unsigned NOT NULL,
          `day` date NOT NULL,
          `average` tinyint(3) unsigned DEFAULT NULL,
          `good` smallint(5) unsigned NOT NULL DEFAULT 0,
          `ok` smallint(5) unsigned NOT NULL DEFAULT 0,
          `bad` smallint(5) unsigned NOT NULL DEFAULT 0,
          `unscored` smallint(5) unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY (`site_id`,`day`),
          CONSTRAINT `fk_seo_days_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );
};
