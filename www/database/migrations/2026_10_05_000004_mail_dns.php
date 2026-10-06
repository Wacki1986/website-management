<?php

declare(strict_types=1);

/**
 * Domény projektu — co se zjišťuje samo:
 *
 * - DNS pošty (`Monitor\MailDnsCheck`): MX, SPF, DMARC a DKIM. Výsledek
 *   poslední kontroly jako JSON v `mail_dns_json` (co se našlo + seznam
 *   problémů). DKIM selektor se nezadává, zkoušejí se obvyklé.
 * - Registrátor z RDAPu (`registrar_detected`): naposledy zjištěný. Pole
 *   `provider` se jím přepisuje, jen dokud ho nikdo neopravil ručně —
 *   tedy když je prázdné, nebo rovné tomu, co se zjistilo minule.
 */
return function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_services' AND COLUMN_NAME = 'mail_dns_json'",
    );

    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `project_services`
               ADD COLUMN `registrar_detected` varchar(120) NOT NULL DEFAULT '' AFTER `expiry_error`,
               ADD COLUMN `mail_dns_json` text DEFAULT NULL AFTER `registrar_detected`,
               ADD COLUMN `mail_dns_checked_at` datetime DEFAULT NULL AFTER `mail_dns_json`",
        );
    }
};
