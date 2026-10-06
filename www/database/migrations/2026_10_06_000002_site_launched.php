<?php

declare(strict_types=1);

/**
 * Datum spuštění webu — zadává se ručně v Nastavení webu a ukazuje
 * v přehledu (jak dlouho web běží). Monitor ho zjistit neumí: datum
 * přidání do aplikace ani stáří domény se se spuštěním nekryjí.
 *
 * Sloupec se přidává jen když chybí: testy pouštějí migrace nad
 * existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'launched_on'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `sites` ADD COLUMN `launched_on` date NULL DEFAULT NULL AFTER `backup_note`');
    }
};
