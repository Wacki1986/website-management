<?php

declare(strict_types=1);

/**
 * Poznámky v trezoru přístupů projektu (karta Poznámky pod přístupy na
 * záložce Přístupy u webu i v projektu). Píšou se sem věci, které do
 * jednoho přístupu nepatří — PIN k podpoře hostingu, záložní kódy, kdo
 * co spravuje — proto šifrovaně přes `Secrets` jako hesla.
 *
 * Nepleť si s `projects.note`: ta je čitelná a ukazuje se v detailu
 * projektu bez odemčení trezoru.
 *
 * Sloupec se přidává jen když chybí: testy pouštějí migrace nad
 * existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'vault_note'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `projects` ADD COLUMN `vault_note` text DEFAULT NULL COMMENT 'šifrované (Secrets)' AFTER `note`");
    }
};
