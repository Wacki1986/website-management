<?php

declare(strict_types=1);

/**
 * Trezor přístupů patří projektu (`Projects\Credentials`), ne webu: FTP,
 * hosting, databáze, e-mailové schránky, registrátor. Smazáním webu
 * přístupy nemizí — hosting i e-maily žijí dál. `site_id` jen říká, ke
 * kterému webu se přístup váže (u FTP a databáze); s webem se vynuluje.
 *
 * Heslo a poznámka jsou šifrované přes `Secrets` jako dřív — převod je
 * kopíruje beze změny, klíč je pořád týž.
 *
 * `site_credentials` zůstává jednu verzi jako pojistka a pak ji smaže
 * samostatná migrace. Kopíruje se jen do prázdné tabulky, takže opakovaný
 * běh nic nezdvojí.
 */
return function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `credentials` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `project_id` int(10) unsigned NOT NULL,
          `site_id` int(10) unsigned DEFAULT NULL,
          `kind` varchar(20) NOT NULL COMMENT 'ftp | hosting | database | email | registrar | other',
          `label` varchar(150) NOT NULL DEFAULT '',
          `protocol` varchar(10) NOT NULL DEFAULT '' COMMENT 'ftp | ftps | sftp (jen u FTP)',
          `host` varchar(255) NOT NULL DEFAULT '',
          `port` smallint(5) unsigned DEFAULT NULL,
          `url` varchar(500) NOT NULL DEFAULT '',
          `database_name` varchar(190) NOT NULL DEFAULT '',
          `username` varchar(255) NOT NULL DEFAULT '',
          `password` text DEFAULT NULL COMMENT 'šifrované (Secrets)',
          `note` text DEFAULT NULL COMMENT 'šifrované (Secrets)',
          `created_by` int(10) unsigned DEFAULT NULL,
          `created_at` datetime NOT NULL,
          `updated_at` datetime NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_credentials_project` (`project_id`,`kind`),
          KEY `idx_credentials_site` (`site_id`),
          CONSTRAINT `fk_vault_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
          CONSTRAINT `fk_vault_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );

    if ((int) $pdo->query('SELECT COUNT(*) FROM credentials')->fetchColumn() > 0) {
        return;
    }

    $pdo->exec(
        'INSERT INTO credentials (project_id, site_id, kind, label, protocol, host, port, url, database_name, username, password, note, created_by, created_at, updated_at)
         SELECT s.project_id, sc.site_id, sc.kind, sc.label, sc.protocol, sc.host, sc.port, sc.url, sc.database_name, sc.username, sc.password, sc.note, sc.created_by, sc.created_at, sc.updated_at
         FROM site_credentials sc JOIN sites s ON s.id = sc.site_id
         WHERE s.project_id IS NOT NULL
         ORDER BY sc.id',
    );
};
