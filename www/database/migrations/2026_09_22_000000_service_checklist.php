<?php

declare(strict_types=1);

/**
 * Checklist u zápisu servisu a čas poslední úpravy zápisu.
 *
 * `checklist` = JSON `[{"label": "Aktualizace pluginů", "done": true}, …]`
 * — kopie seznamu úkolů z Nastavení → Servis v okamžiku zápisu, takže
 * pozdější úprava seznamu starý zápis nezmění. NULL = zápis z doby před
 * checklistem.
 *
 * Sloupce se přidávají jen když chybí: testy mažou evidenci migrací
 * a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $columns = [
        'checklist' => 'ALTER TABLE `service_logs` ADD COLUMN `checklist` text DEFAULT NULL AFTER `description`',
        'updated_at' => 'ALTER TABLE `service_logs` ADD COLUMN `updated_at` datetime DEFAULT NULL AFTER `created_at`',
    ];

    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_logs' AND COLUMN_NAME = :column",
    );

    foreach ($columns as $column => $sql) {
        $exists->execute(['column' => $column]);

        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
};
