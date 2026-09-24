<?php

declare(strict_types=1);

/**
 * Jestli WordPress k nabízené aktualizaci pluginu má balíček ke stažení
 * (MEDIAGRAFIK Monitor 1.5.4+). Placené pluginy bez licence hlásí novou
 * verzi, ale bez balíčku — aktualizace jde jen ručně a správa ji nenabízí.
 * NULL = nezjištěno (starší plugin), bere se jako „balíček je".
 *
 * Sloupec se přidává jen když chybí: testy mažou evidenci migrací
 * a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_plugins' AND COLUMN_NAME = 'update_package'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `site_plugins` ADD COLUMN `update_package` tinyint(1) DEFAULT NULL AFTER `has_update`');
    }
};
