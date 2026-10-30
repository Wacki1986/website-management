<?php

declare(strict_types=1);

/**
 * Kontaktní osoby sdílené mezi klienty.
 *
 * Dřív měl každý klient vlastní kopie osob (`client_contacts`), takže
 * člověk, který jedná za dvě firmy, byl zapsaný dvakrát a změna telefonu
 * se musela hlídat na obou místech. Teď:
 *
 * - `contacts` — osoba jednou: jméno, e-mail, telefon.
 * - `contact_links` — u kterých klientů osoba je. Role (jednatel, účetní…)
 *   patří k vazbě, protože u každé firmy může být jiná. `is_primary` =
 *   hlavní kontakt klienta (jeden na klienta, hlídá `ContactRepository`).
 *
 * Převod starých dat: osoby se stejným e-mailem i jménem se sloučí do
 * jedné (rozhodnutí správce 30. 10. 2026), ostatní zůstanou zvlášť.
 * Převádí se jen do prázdné tabulky — testy pouštějí migrace nad
 * existujícím schématem znovu.
 *
 * `client_contacts` a sloupce `clients.email`/`phone` zůstávají jako
 * záloha, aplikace je už nečte (úklid v `dev/docs/02-todo.md`).
 */
return function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `contacts` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `first_name` varchar(100) NOT NULL DEFAULT '',
          `last_name` varchar(100) NOT NULL DEFAULT '',
          `email` varchar(190) NOT NULL DEFAULT '',
          `phone` varchar(50) NOT NULL DEFAULT '',
          `created_at` datetime NOT NULL,
          `updated_at` datetime NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_contacts_name` (`last_name`,`first_name`),
          KEY `idx_contacts_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `contact_links` (
          `client_id` int(10) unsigned NOT NULL,
          `contact_id` int(10) unsigned NOT NULL,
          `role` varchar(100) NOT NULL DEFAULT '',
          `is_primary` tinyint(1) NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL,
          PRIMARY KEY (`client_id`,`contact_id`),
          KEY `idx_contact_links_contact` (`contact_id`),
          CONSTRAINT `fk_contact_links_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
          CONSTRAINT `fk_contact_links_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci",
    );

    if ((int) $pdo->query('SELECT COUNT(*) FROM `contacts`')->fetchColumn() > 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $insertContact = $pdo->prepare(
        'INSERT INTO `contacts` (first_name, last_name, email, phone, created_at, updated_at)
         VALUES (:first_name, :last_name, :email, :phone, :created_at, :updated_at)',
    );
    $fillPhone = $pdo->prepare("UPDATE `contacts` SET phone = :phone WHERE id = :id AND phone = ''");
    // Stejná osoba dvakrát u jednoho klienta → jedna vazba; hlavní vyhrává.
    $insertLink = $pdo->prepare(
        'INSERT INTO `contact_links` (client_id, contact_id, role, is_primary, created_at)
         VALUES (:client_id, :contact_id, :role, :is_primary, :created_at)
         ON DUPLICATE KEY UPDATE is_primary = GREATEST(is_primary, VALUES(is_primary)),
                                 role = IF(role = \'\', VALUES(role), role)',
    );

    /** @var array<string, int> $merged klíč „e-mail|jméno|příjmení" => id osoby */
    $merged = [];
    $rows = $pdo->query('SELECT * FROM `client_contacts` ORDER BY is_primary DESC, id')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $email = mb_strtolower(trim((string) $row['email']));
        // Bez e-mailu se neslučuje: dva „Jan Novák" bez adresy můžou být dva lidi.
        $key = $email !== ''
            ? $email . '|' . mb_strtolower(trim((string) $row['first_name'])) . '|' . mb_strtolower(trim((string) $row['last_name']))
            : null;

        if ($key !== null && isset($merged[$key])) {
            $contactId = $merged[$key];

            if ((string) $row['phone'] !== '') {
                $fillPhone->execute(['phone' => (string) $row['phone'], 'id' => $contactId]);
            }
        } else {
            $insertContact->execute([
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'email' => $email,
                'phone' => (string) $row['phone'],
                'created_at' => (string) $row['created_at'],
                'updated_at' => $now,
            ]);
            $contactId = (int) $pdo->lastInsertId();

            if ($key !== null) {
                $merged[$key] = $contactId;
            }
        }

        $insertLink->execute([
            'client_id' => (int) $row['client_id'],
            'contact_id' => $contactId,
            'role' => (string) $row['role'],
            'is_primary' => (int) $row['is_primary'],
            'created_at' => (string) $row['created_at'],
        ]);
    }
};
