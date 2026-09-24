<?php

declare(strict_types=1);

/**
 * Trezor přístupů u webu (`Sites\SiteCredentials`): FTP/SFTP, administrace
 * hostingu, databáze a cokoli dalšího. Heslo a poznámka jsou šifrované přes
 * `Secrets` (klíč v `config/env.php`) — záloha databáze je v čitelné
 * podobě nenese.
 *
 * S webem se přístupy mažou (cizí klíč s ON DELETE CASCADE); odebrání webu
 * z monitoringu je maže samo, viz `SiteRepository::remove()`.
 */
return function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `site_credentials` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `site_id` int(10) unsigned NOT NULL,
          `kind` varchar(20) NOT NULL COMMENT 'ftp | hosting | database | other',
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
          KEY `idx_credentials_site` (`site_id`),
          CONSTRAINT `fk_credentials_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );
};
