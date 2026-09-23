<?php

declare(strict_types=1);

/**
 * Knihovna pluginů — placené a vlastní pluginy, které nejsou na
 * wordpress.org. Od každého jen nejnovější verze (ZIP ve
 * `storage/plugin-library/<slug>.zip`); nahrání novější starou nahradí.
 *
 * `file` = cesta hlavního souboru pluginu tak, jak ji zná WordPress
 * (`slozka/soubor.php`) — podle ní se plugin páruje s `site_plugins`.
 */
return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS `plugin_library` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `slug` varchar(100) NOT NULL,
          `file` varchar(191) NOT NULL,
          `name` varchar(150) NOT NULL DEFAULT '',
          `version` varchar(30) NOT NULL DEFAULT '',
          `author` varchar(150) NOT NULL DEFAULT '',
          `requires_wp` varchar(20) NOT NULL DEFAULT '',
          `requires_php` varchar(20) NOT NULL DEFAULT '',
          `size` int(10) unsigned NOT NULL DEFAULT 0,
          `uploaded_by` varchar(100) NOT NULL DEFAULT '',
          `uploaded_at` datetime NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_library_slug` (`slug`),
          UNIQUE KEY `uq_library_file` (`file`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
        SQL);
};
