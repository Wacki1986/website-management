<?php

declare(strict_types=1);

/**
 * Záložka Služby v detailu webu (`Sites\SiteServices`): které služby na
 * webu běží (GTM, Search Console, Analytics…) a co je hotové z kroků po
 * spuštění webu.
 *
 * Seznam služeb a úkolů je pevný číselník v kódu (`SiteServices::SERVICES`,
 * `::TASKS`) — tabulky drží jen to, co je zaškrtnuté. Chybějící řádek =
 * nenasazeno / nehotovo, takže nový web ani nová položka číselníku nic
 * nepotřebují.
 *
 * - `site_services` — řádek = služba na webu běží.
 * - `site_launch_tasks` — řádek = úkol hotový; kdo a kdy ho odškrtl.
 *   Smazaný účet podpis nechá prázdný (SET NULL), úkol zůstane hotový.
 */
return function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `site_services` (
          `site_id` int(10) unsigned NOT NULL,
          `service_key` varchar(40) NOT NULL,
          `created_at` datetime NOT NULL,
          PRIMARY KEY (`site_id`,`service_key`),
          CONSTRAINT `fk_site_services_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `site_launch_tasks` (
          `site_id` int(10) unsigned NOT NULL,
          `task_key` varchar(40) NOT NULL,
          `done_by` int(10) unsigned DEFAULT NULL,
          `done_at` datetime NOT NULL,
          PRIMARY KEY (`site_id`,`task_key`),
          KEY `idx_launch_tasks_user` (`done_by`),
          CONSTRAINT `fk_launch_tasks_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
          CONSTRAINT `fk_launch_tasks_user` FOREIGN KEY (`done_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );
};
