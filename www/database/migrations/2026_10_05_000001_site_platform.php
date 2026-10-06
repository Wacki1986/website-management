<?php

declare(strict_types=1);

/**
 * Na čem web běží: `platform` = `wordpress` (plugin MEDIAGRAFIK Monitor,
 * API klíč, pluginy, aktualizace) nebo `other` (Shoptet, Webnode,
 * statické HTML… — jen dostupnost, SSL a doména). `platform_name` je
 * popisek jiného systému do seznamů („Shoptet").
 *
 * Stávající weby jsou WordPress (výchozí hodnota). Sloupce se přidávají
 * jen když chybí: testy pouštějí migrace nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'platform'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `sites`
               ADD COLUMN `platform` varchar(20) NOT NULL DEFAULT 'wordpress' AFTER `project_id`,
               ADD COLUMN `platform_name` varchar(60) NOT NULL DEFAULT '' AFTER `platform`",
        );
    }
};
