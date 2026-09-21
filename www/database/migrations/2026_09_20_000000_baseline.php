<?php

declare(strict_types=1);

/**
 * Výchozí schéma Správy webů.
 *
 * Tabulky účtů (`users`, `remember_tokens`, `password_resets`,
 * `rate_limits`, `settings`, `push_subscriptions`) jsou převzaté ze správy
 * instancí dispu beze změny schématu — jen `users` má navíc `role`
 * (štítek, ne oprávnění) a `invited_at` (pozvánka ještě nepřijatá).
 *
 * `users` je první schválně — ostatní tabulky se na ni odkazují cizím
 * klíčem a v tomhle pořadí není potřeba kontrolu vypínat.
 *
 * Migrace jen **přibývají**; tenhle soubor se smí doplňovat jen do prvního
 * ostrého nasazení. Pak každá změna schématu = nový soubor.
 */
return function (PDO $pdo): void {
    $tables = [
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `users` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `username` varchar(100) NOT NULL,
              `email` varchar(190) DEFAULT NULL,
              `name` varchar(190) DEFAULT NULL,
              `password_hash` varchar(255) NOT NULL,
              `role` varchar(20) NOT NULL DEFAULT 'admin',
              `is_active` tinyint(1) NOT NULL DEFAULT 1,
              `theme` varchar(10) NOT NULL DEFAULT 'auto',
              `avatar` varchar(50) DEFAULT NULL,
              `invited_at` datetime DEFAULT NULL,
              `last_login_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_username` (`username`),
              UNIQUE KEY `uq_users_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `remember_tokens` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `user_id` int(10) unsigned NOT NULL,
              `selector` varchar(32) NOT NULL,
              `validator_hash` char(64) NOT NULL,
              `previous_hash` char(64) DEFAULT NULL,
              `rotated_at` datetime DEFAULT NULL,
              `expires_at` datetime NOT NULL,
              `created_at` datetime NOT NULL,
              `last_used_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_remember_selector` (`selector`),
              KEY `idx_remember_user` (`user_id`),
              CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `password_resets` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `user_id` int(10) unsigned NOT NULL,
              `selector` varchar(32) NOT NULL,
              `validator_hash` char(64) NOT NULL,
              `expires_at` datetime NOT NULL,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_reset_selector` (`selector`),
              KEY `idx_reset_user` (`user_id`),
              KEY `idx_reset_expires` (`expires_at`),
              CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `rate_limits` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `bucket` varchar(40) NOT NULL,
              `key` varchar(191) NOT NULL,
              `success` tinyint(1) NOT NULL DEFAULT 0,
              `attempted_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_lookup` (`bucket`,`key`,`success`,`attempted_at`),
              KEY `idx_purge` (`attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `settings` (
              `key` varchar(100) NOT NULL,
              `value` text DEFAULT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Odběry web pushe — endpoint je dlouhý a do indexu se nevejde,
        // unikátnost hlídá jeho SHA-256 v `endpoint_hash`.
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `push_subscriptions` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `user_id` int(10) unsigned NOT NULL,
              `endpoint_hash` char(64) NOT NULL,
              `endpoint` text NOT NULL,
              `p256dh` varchar(255) NOT NULL,
              `auth` varchar(255) NOT NULL,
              `user_agent` varchar(255) DEFAULT NULL,
              `failed_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
              `created_at` datetime NOT NULL,
              `last_success_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
              KEY `idx_push_user` (`user_id`),
              CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Auditní log — kdo co udělal. `site_name` je snapshot, záznam musí
        // přežít i smazání webu a účtu. Nikdy se nemaže.
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `audit_log` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned DEFAULT NULL,
              `site_name` varchar(150) NOT NULL DEFAULT '',
              `action` varchar(30) NOT NULL,
              `user_id` int(10) unsigned DEFAULT NULL,
              `user_name` varchar(100) NOT NULL DEFAULT '',
              `success` tinyint(1) NOT NULL DEFAULT 1,
              `description` varchar(500) NOT NULL DEFAULT '',
              `detail` text DEFAULT NULL,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_audit_site` (`site_id`,`created_at`),
              KEY `idx_audit_created` (`created_at`),
              KEY `idx_audit_user` (`user_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Klienti a jejich kontakty. `email`/`phone` klienta = hlavní kontakt
        // (kopie pro rychlý výpis), zbytek kontaktů v `client_contacts`.
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `clients` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(150) NOT NULL,
              `company_id` varchar(20) NOT NULL DEFAULT '',
              `vat_id` varchar(20) NOT NULL DEFAULT '',
              `address` varchar(255) NOT NULL DEFAULT '',
              `billing_note` varchar(255) NOT NULL DEFAULT '',
              `since` date DEFAULT NULL,
              `email` varchar(190) NOT NULL DEFAULT '',
              `phone` varchar(50) NOT NULL DEFAULT '',
              `note` text DEFAULT NULL,
              `archived_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_clients_name` (`name`),
              KEY `idx_clients_archived` (`archived_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `client_contacts` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `client_id` int(10) unsigned NOT NULL,
              `first_name` varchar(100) NOT NULL DEFAULT '',
              `last_name` varchar(100) NOT NULL DEFAULT '',
              `role` varchar(100) NOT NULL DEFAULT '',
              `email` varchar(190) NOT NULL DEFAULT '',
              `phone` varchar(50) NOT NULL DEFAULT '',
              `is_primary` tinyint(1) NOT NULL DEFAULT 0,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_contacts_client` (`client_id`),
              CONSTRAINT `fk_contacts_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Weby v monitoringu. `api_key` je šifrovaný app_key (viz Secrets),
        // `removed_at` = odebrání z monitoringu (12 měsíců archiv, pak smazání).
        // Sloupce `last_*`, `ssl_*`, `domain_*` plní monitor (etapa P2).
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `sites` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `client_id` int(10) unsigned DEFAULT NULL,
              `name` varchar(150) NOT NULL,
              `url` varchar(255) NOT NULL,
              `admin_url` varchar(255) NOT NULL DEFAULT '',
              `api_key` text DEFAULT NULL,
              `api_key_hint` varchar(4) NOT NULL DEFAULT '',
              `check_interval_min` smallint(5) unsigned NOT NULL DEFAULT 15,
              `timeout_s` tinyint(3) unsigned DEFAULT NULL,
              `watch_uptime` tinyint(1) NOT NULL DEFAULT 1,
              `watch_updates` tinyint(1) NOT NULL DEFAULT 1,
              `watch_ssl` tinyint(1) NOT NULL DEFAULT 1,
              `hosting_note` varchar(120) NOT NULL DEFAULT '',
              `backup_note` varchar(120) NOT NULL DEFAULT '',
              `status` varchar(10) NOT NULL DEFAULT 'unknown',
              `consecutive_failures` smallint(5) unsigned NOT NULL DEFAULT 0,
              `last_check_at` datetime DEFAULT NULL,
              `last_status_code` smallint(6) DEFAULT NULL,
              `last_response_ms` int(11) DEFAULT NULL,
              `last_ok_at` datetime DEFAULT NULL,
              `last_error` varchar(255) DEFAULT NULL,
              `api_status` varchar(10) NOT NULL DEFAULT 'unknown',
              `api_failures` smallint(5) unsigned NOT NULL DEFAULT 0,
              `last_snapshot_at` datetime DEFAULT NULL,
              `snapshot_error` varchar(255) DEFAULT NULL,
              `ssl_valid_to` date DEFAULT NULL,
              `ssl_issuer` varchar(120) DEFAULT NULL,
              `ssl_checked_at` datetime DEFAULT NULL,
              `ssl_error` varchar(255) DEFAULT NULL,
              `domain_expires_on` date DEFAULT NULL,
              `domain_checked_at` datetime DEFAULT NULL,
              `removed_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_sites_url` (`url`),
              KEY `idx_sites_client` (`client_id`),
              KEY `idx_sites_removed` (`removed_at`),
              KEY `idx_sites_check` (`removed_at`,`last_check_at`),
              KEY `idx_sites_snapshot` (`removed_at`,`last_snapshot_at`),
              CONSTRAINT `fk_sites_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Poslední data z pluginu — jeden řádek na web (UPSERT). Celý JSON
        // v `payload`, nejčastěji čtené hodnoty rozepsané do sloupců.
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `site_snapshots` (
              `site_id` int(10) unsigned NOT NULL,
              `fetched_at` datetime NOT NULL,
              `plugin_version` varchar(20) NOT NULL DEFAULT '',
              `payload` mediumtext NOT NULL,
              `wp_version` varchar(20) NOT NULL DEFAULT '',
              `wp_update_version` varchar(20) DEFAULT NULL,
              `php_version` varchar(20) NOT NULL DEFAULT '',
              `db_type` varchar(20) NOT NULL DEFAULT '',
              `db_version` varchar(30) NOT NULL DEFAULT '',
              `db_size_mb` int(11) DEFAULT NULL,
              `theme_name` varchar(100) NOT NULL DEFAULT '',
              `theme_version` varchar(20) NOT NULL DEFAULT '',
              `theme_is_child` tinyint(1) NOT NULL DEFAULT 0,
              `plugins_total` smallint(6) NOT NULL DEFAULT 0,
              `plugins_active` smallint(6) NOT NULL DEFAULT 0,
              `plugins_updates` smallint(6) NOT NULL DEFAULT 0,
              `security_updates` smallint(6) NOT NULL DEFAULT 0,
              `last_backup_at` datetime DEFAULT NULL,
              `security_json` text DEFAULT NULL,
              `security_checked_at` datetime DEFAULT NULL,
              `security_missing` tinyint(4) NOT NULL DEFAULT 0,
              `security_partial` tinyint(4) NOT NULL DEFAULT 0,
              PRIMARY KEY (`site_id`),
              CONSTRAINT `fk_snapshots_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `site_plugins` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned NOT NULL,
              `file` varchar(191) NOT NULL,
              `name` varchar(150) NOT NULL DEFAULT '',
              `author` varchar(150) NOT NULL DEFAULT '',
              `version` varchar(30) NOT NULL DEFAULT '',
              `new_version` varchar(30) DEFAULT NULL,
              `is_active` tinyint(1) NOT NULL DEFAULT 0,
              `has_update` tinyint(1) NOT NULL DEFAULT 0,
              `first_seen_at` datetime NOT NULL,
              `last_seen_at` datetime NOT NULL,
              `version_changed_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_site_plugin` (`site_id`,`file`),
              CONSTRAINT `fk_plugins_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // „Historie změn" u webu — všechno, co se s webem stalo (i automaticky).
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `events` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned NOT NULL,
              `kind` varchar(12) NOT NULL,
              `tone` varchar(8) NOT NULL DEFAULT 'ok',
              `message` varchar(500) NOT NULL,
              `detail` text DEFAULT NULL,
              `user_name` varchar(100) NOT NULL DEFAULT '',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_events_site` (`site_id`,`created_at`),
              KEY `idx_events_kind` (`kind`,`created_at`),
              CONSTRAINT `fk_events_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Kontroly dostupnosti: surové řádky (retence podle nastavení)
        // a denní součty (navždy — zdroj pásku uptime i reportů).
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `uptime_checks` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned NOT NULL,
              `checked_at` datetime NOT NULL,
              `ok` tinyint(1) NOT NULL DEFAULT 0,
              `status_code` smallint(6) NOT NULL DEFAULT 0,
              `response_ms` int(11) NOT NULL DEFAULT 0,
              `error` varchar(255) DEFAULT NULL,
              `source` varchar(8) NOT NULL DEFAULT 'cron',
              PRIMARY KEY (`id`),
              KEY `idx_checks_site` (`site_id`,`checked_at`),
              KEY `idx_checks_purge` (`checked_at`),
              CONSTRAINT `fk_checks_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `uptime_days` (
              `site_id` int(10) unsigned NOT NULL,
              `day` date NOT NULL,
              `checks` smallint(5) unsigned NOT NULL DEFAULT 0,
              `failed` smallint(5) unsigned NOT NULL DEFAULT 0,
              `downtime_min` smallint(5) unsigned NOT NULL DEFAULT 0,
              `sum_ms` bigint(20) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`site_id`,`day`),
              CONSTRAINT `fk_days_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Alerty — jeden otevřený na (web, typ). `rule_label` je věta
        // „pravidlo: 3 selhané kontroly" z návrhu, `occurrences_30d` počet
        // výskytů téhož typu za 30 dní.
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `alerts` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned NOT NULL,
              `type` varchar(20) NOT NULL,
              `severity` varchar(8) NOT NULL DEFAULT 'warning',
              `title` varchar(200) NOT NULL,
              `body` varchar(1000) NOT NULL DEFAULT '',
              `rule_label` varchar(120) NOT NULL DEFAULT '',
              `status` varchar(10) NOT NULL DEFAULT 'open',
              `opened_at` datetime NOT NULL,
              `resolved_at` datetime DEFAULT NULL,
              `resolved_by` varchar(100) NOT NULL DEFAULT '',
              `resolve_note` varchar(255) NOT NULL DEFAULT '',
              `notified_at` datetime DEFAULT NULL,
              `occurrences_30d` smallint(5) unsigned NOT NULL DEFAULT 1,
              `detail` text DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_alerts_open` (`status`,`opened_at`),
              KEY `idx_alerts_site` (`site_id`,`type`,`status`),
              CONSTRAINT `fk_alerts_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Servis: plán (druh, opakování, další termín) a historie provedených
        // servisů — zapisuje se do klientského reportu.
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `service_plans` (
              `site_id` int(10) unsigned NOT NULL,
              `is_active` tinyint(1) NOT NULL DEFAULT 0,
              `kind` varchar(8) NOT NULL DEFAULT 'small',
              `frequency` varchar(10) NOT NULL DEFAULT 'monthly',
              `first_date` date DEFAULT NULL,
              `next_date` date DEFAULT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`site_id`),
              KEY `idx_plans_next` (`is_active`,`next_date`),
              CONSTRAINT `fk_plans_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `service_logs` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned NOT NULL,
              `performed_on` date NOT NULL,
              `kind` varchar(8) NOT NULL DEFAULT 'small',
              `description` text NOT NULL,
              `minutes` smallint(5) unsigned DEFAULT NULL,
              `status` varchar(8) NOT NULL DEFAULT 'done',
              `user_name` varchar(100) NOT NULL DEFAULT '',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_service_site` (`site_id`,`performed_on`),
              CONSTRAINT `fk_service_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        // Klientské reporty: nastavení per web, adresáti a jednotlivé
        // (odeslané i čekající) reporty s uloženým HTML — co klient dostal,
        // jde kdykoli otevřít znovu.
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `report_settings` (
              `site_id` int(10) unsigned NOT NULL,
              `is_active` tinyint(1) NOT NULL DEFAULT 0,
              `frequency` varchar(10) NOT NULL DEFAULT 'monthly',
              `send_day` tinyint(3) unsigned NOT NULL DEFAULT 1,
              `send_hour` tinyint(3) unsigned NOT NULL DEFAULT 6,
              `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
              `sections` text NOT NULL,
              `next_send_at` datetime DEFAULT NULL,
              `last_sent_at` datetime DEFAULT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`site_id`),
              KEY `idx_report_settings_next` (`is_active`,`next_send_at`),
              CONSTRAINT `fk_report_settings_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `report_recipients` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned NOT NULL,
              `email` varchar(190) NOT NULL,
              `label` varchar(10) NOT NULL DEFAULT 'klient',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_report_recipient` (`site_id`,`email`),
              CONSTRAINT `fk_report_recipients_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
        <<<'SQL'
            CREATE TABLE IF NOT EXISTS `reports` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned NOT NULL,
              `token` char(32) NOT NULL,
              `kind` varchar(10) NOT NULL DEFAULT 'scheduled',
              `period_from` date NOT NULL,
              `period_to` date NOT NULL,
              `period_label` varchar(60) NOT NULL,
              `note` text NOT NULL,
              `recipients` text NOT NULL,
              `sections` text NOT NULL,
              `summary_json` mediumtext NOT NULL,
              `html` mediumtext NOT NULL,
              `status` varchar(20) NOT NULL DEFAULT 'draft',
              `error` varchar(255) NOT NULL DEFAULT '',
              `scheduled_for` datetime DEFAULT NULL,
              `sent_at` datetime DEFAULT NULL,
              `opened_at` datetime DEFAULT NULL,
              `open_count` int(10) unsigned NOT NULL DEFAULT 0,
              `created_at` datetime NOT NULL,
              `created_by` varchar(100) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_reports_token` (`token`),
              KEY `idx_reports_site` (`site_id`,`status`,`period_from`),
              KEY `idx_reports_status` (`status`,`scheduled_for`),
              CONSTRAINT `fk_reports_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
            SQL,
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
};
