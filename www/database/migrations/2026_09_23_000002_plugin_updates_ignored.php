<?php

declare(strict_types=1);

/**
 * „Nesledovat aktualizace" u konkrétního pluginu na konkrétním webu —
 * typicky placený plugin bez licence, který se už aktualizovat nebude.
 * Takový plugin se nepočítá do čekajících aktualizací ani do alertu.
 *
 * Import dat z pluginu příznak nepřepisuje; smaže se jen s řádkem, když
 * plugin z webu zmizí (po novém nainstalování se zase sleduje).
 *
 * Sloupec se přidává jen když chybí: testy mažou evidenci migrací
 * a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_plugins' AND COLUMN_NAME = 'updates_ignored'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `site_plugins` ADD COLUMN `updates_ignored` tinyint(1) NOT NULL DEFAULT 0 AFTER `has_update`');
    }
};
