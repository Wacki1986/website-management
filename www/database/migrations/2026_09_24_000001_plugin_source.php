<?php

declare(strict_types=1);

/**
 * Placené pluginy se stejným slugem jako (dávno stažený) plugin
 * z wordpress.org — WPML (`sitepress-multilingual-cms`) — se nesmí hodnotit
 * podle adresáře.
 *
 * - `site_plugins.source`: odkud si plugin na webu bere aktualizace
 *   (MEDIAGRAFIK Monitor 1.5.3+): `wporg`, `external` (Update URI nebo
 *   vlastní updater autora), '' = nezjištěno.
 * - `plugin_directory.manual_external`: ruční označení „placená verze mimo
 *   wordpress.org" ze záložky Pluginy, platí pro všechny weby.
 *
 * Sloupce se přidávají jen když chybí: testy mažou evidenci migrací
 * a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $columns = [
        ['site_plugins', 'source', "varchar(10) NOT NULL DEFAULT '' AFTER `updates_ignored`"],
        ['plugin_directory', 'manual_external', 'tinyint(1) NOT NULL DEFAULT 0 AFTER `status`'],
    ];

    foreach ($columns as [$table, $column, $definition]) {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "' AND COLUMN_NAME = '" . $column . "'",
        );

        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
};
