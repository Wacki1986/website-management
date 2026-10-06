<?php

declare(strict_types=1);

/**
 * Projekty (`Projects\ProjectRepository`): „pekarnanovak.cz" jako celek,
 * o který se staráme — web(y), později domény, hosting, e-maily a přístupy.
 * Klient → projekt → služby.
 *
 * Klienta drží projekt. `sites.client_id` zůstává jako kopie projektu
 * (udržuje ji `ProjectRepository`) — čtou ho alerty, reporty, monitor
 * i dashboard a přepisovat všechny ty dotazy na join přes projekt by
 * nic nepřineslo.
 *
 * Datová část: každý web v monitoringu, který ještě projekt nemá, dostane
 * vlastní (název a klient z webu). Weby jednoho klienta se nespojují
 * samy — co k sobě patří, ví jen správce; přesune je v Nastavení webu.
 *
 * Sloupec a cizí klíč se přidávají jen když chybí: testy mažou evidenci
 * migrací a pouštějí je nad existujícím schématem znovu.
 */
return function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `projects` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `client_id` int(10) unsigned DEFAULT NULL,
          `name` varchar(150) NOT NULL,
          `note` text DEFAULT NULL,
          `created_at` datetime NOT NULL,
          `updated_at` datetime NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_projects_client` (`client_id`),
          KEY `idx_projects_name` (`name`),
          CONSTRAINT `fk_projects_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );

    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'project_id'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE `sites`
               ADD COLUMN `project_id` int(10) unsigned DEFAULT NULL AFTER `client_id`,
               ADD KEY `idx_sites_project` (`project_id`),
               ADD CONSTRAINT `fk_sites_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
        );
    }

    $sites = $pdo->query('SELECT id, name, client_id FROM sites WHERE removed_at IS NULL AND project_id IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare('INSERT INTO projects (client_id, name, created_at, updated_at) VALUES (:client_id, :name, :now, :now2)');
    $attach = $pdo->prepare('UPDATE sites SET project_id = :project_id WHERE id = :id');
    $now = date('Y-m-d H:i:s');

    foreach ($sites as $site) {
        $insert->execute(['client_id' => $site['client_id'], 'name' => $site['name'], 'now' => $now, 'now2' => $now]);
        $attach->execute(['project_id' => (int) $pdo->lastInsertId(), 'id' => (int) $site['id']]);
    }
};
