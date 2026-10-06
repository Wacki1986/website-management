<?php

declare(strict_types=1);

/**
 * Oprava po 2026_10_05_000004: ta migrace v první podobě přidávala sloupec
 * `dkim_selector` a teprve později `registrar_detected`. Databáze, kde stihla
 * proběhnout první podoba, je má v evidenci jako hotovou — upravená verze
 * se tam už nespustí a krok cronu „renewals" padá na chybějícím sloupci.
 *
 * Tady se srovná schéma u všech: `registrar_detected` se doplní, kde chybí,
 * a nepoužívaný `dkim_selector` (selektor se dnes nezadává) se odstraní.
 * Obojí jen podle skutečného stavu tabulky — opakovaný běh nic nezmění.
 */
return function (PDO $pdo): void {
    $has = static fn (string $column): bool => (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_services' AND COLUMN_NAME = " . $pdo->quote($column),
    )->fetchColumn() > 0;

    if (!$has('registrar_detected')) {
        $pdo->exec("ALTER TABLE `project_services` ADD COLUMN `registrar_detected` varchar(120) NOT NULL DEFAULT '' AFTER `expiry_error`");
    }

    if ($has('dkim_selector')) {
        $pdo->exec('ALTER TABLE `project_services` DROP COLUMN `dkim_selector`');
    }
};
