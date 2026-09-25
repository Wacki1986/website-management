<?php

declare(strict_types=1);

/**
 * Moduly u webů (`Modules\Modules`): které volitelné měření (SEO, později
 * rychlost…) je u webu zapnuté. Modul musí být zapnutý i globálně
 * v Nastavení → Moduly — tady je jen volba u jednotlivého webu.
 *
 * Tabulka místo sloupce `watch_*` v `sites`: další modul pak nepotřebuje
 * měnit tabulku webů, jen přibude do `Modules::REGISTRY`.
 */
return function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `site_modules` (
          `site_id` int(10) unsigned NOT NULL,
          `module` varchar(20) NOT NULL,
          `is_on` tinyint(1) NOT NULL DEFAULT 0,
          `updated_at` datetime NOT NULL,
          PRIMARY KEY (`site_id`,`module`),
          CONSTRAINT `fk_site_modules_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );
};
