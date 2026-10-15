<?php

declare(strict_types=1);

/**
 * Nadklient projektu — volné slovo, které spojí projekty různých klientů
 * patřících reálně k jedné firmě (PENAM Česko + PENAM Slovensko → „chleba").
 * Není to klient ani kontaktní osoba, jen štítek pro hledání ve výpisu,
 * proto text a ne odkaz na tabulku.
 *
 * Sloupec se přidává jen když chybí: testy pouštějí migrace nad
 * existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'umbrella'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `projects` ADD COLUMN `umbrella` varchar(100) NULL DEFAULT NULL AFTER `client_id`, ADD KEY `idx_projects_umbrella` (`umbrella`)');
    }
};
