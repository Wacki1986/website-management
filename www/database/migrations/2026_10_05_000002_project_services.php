<?php

declare(strict_types=1);

/**
 * Služby projektu (`Projects\ProjectServices`): domény a hosting — kdo
 * je u koho, kdy se obnovují a co z toho fakturujeme klientovi.
 *
 * Jedna tabulka pro oba druhy (`kind`): fakturační pole jsou stejná
 * a přehled „Obnovy a fakturace" pak čte jedno místo. Další placené
 * služby (licence e-mailu…) přibudou jako další `kind`.
 *
 * - `renews_on` — konec zaplaceného období (u domény expirace z RDAPu,
 *   `expiry_checked_at`/`expiry_error` = poslední automatické ověření).
 * - `paid_by` — `us` = kupujeme my a přefakturujeme, `client` = platí sám.
 * - `invoiced_until` — do kdy má klient vyfakturováno; když je před
 *   koncem příštího období a obnova se blíží, služba čeká na fakturu.
 * - `notified_for` — pro které `renews_on` už odešlo upozornění (jednou
 *   na každou obnovu, ne každý den).
 *
 * Datová část: `sites.hosting_note` a doména webu (s expirací, kterou už
 * monitor zjistil) se převedou na služby projektu webu. Ceny se doplní ručně.
 */
return function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `project_services` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `project_id` int(10) unsigned NOT NULL,
          `kind` varchar(20) NOT NULL COMMENT 'domain | hosting',
          `name` varchar(190) NOT NULL DEFAULT '' COMMENT 'doména / označení hostingu',
          `provider` varchar(120) NOT NULL DEFAULT '' COMMENT 'registrátor / poskytovatel',
          `plan` varchar(120) NOT NULL DEFAULT '' COMMENT 'tarif',
          `renews_on` date DEFAULT NULL,
          `expiry_checked_at` datetime DEFAULT NULL,
          `expiry_error` varchar(255) DEFAULT NULL,
          `paid_by` varchar(10) NOT NULL DEFAULT 'us' COMMENT 'us | client',
          `cost_price` decimal(10,2) DEFAULT NULL,
          `sale_price` decimal(10,2) DEFAULT NULL,
          `currency` char(3) NOT NULL DEFAULT 'CZK',
          `period_months` smallint(5) unsigned NOT NULL DEFAULT 12,
          `invoiced_until` date DEFAULT NULL,
          `notified_for` date DEFAULT NULL,
          `note` text DEFAULT NULL,
          `created_at` datetime NOT NULL,
          `updated_at` datetime NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_services_project` (`project_id`,`kind`),
          KEY `idx_services_renews` (`renews_on`),
          CONSTRAINT `fk_services_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );

    // Převod jen jednou: projekt, který už nějakou službu má, se přeskočí.
    $sites = $pdo->query(
        'SELECT s.project_id, s.url, s.hosting_note, s.domain_expires_on, s.domain_checked_at FROM sites s
         WHERE s.removed_at IS NULL AND s.project_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM project_services ps WHERE ps.project_id = s.project_id)
         ORDER BY s.id',
    )->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare(
        'INSERT INTO project_services (project_id, kind, name, provider, renews_on, expiry_checked_at, created_at, updated_at)
         VALUES (:project_id, :kind, :name, :provider, :renews_on, :checked, :now, :now2)',
    );
    $now = date('Y-m-d H:i:s');
    $seen = [];

    foreach ($sites as $site) {
        $projectId = (int) $site['project_id'];
        $host = (string) (parse_url((string) $site['url'], PHP_URL_HOST) ?: '');
        $domain = App\Core\Monitor\DomainChecker::registrableDomain($host);

        // Dva weby jednoho projektu na téže doméně (rezervace.hotel.cz) = jedna doména.
        if ($domain !== null && !isset($seen[$projectId]['domain'][$domain])) {
            $seen[$projectId]['domain'][$domain] = true;
            $insert->execute(['project_id' => $projectId, 'kind' => 'domain', 'name' => $domain, 'provider' => '',
                'renews_on' => $site['domain_expires_on'], 'checked' => $site['domain_checked_at'], 'now' => $now, 'now2' => $now]);
        }

        $hosting = trim((string) $site['hosting_note']);

        if ($hosting !== '' && !isset($seen[$projectId]['hosting'][$hosting])) {
            $seen[$projectId]['hosting'][$hosting] = true;
            $insert->execute(['project_id' => $projectId, 'kind' => 'hosting', 'name' => '', 'provider' => mb_substr($hosting, 0, 120),
                'renews_on' => null, 'checked' => null, 'now' => $now, 'now2' => $now]);
        }
    }
};
