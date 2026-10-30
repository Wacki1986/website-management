<?php

declare(strict_types=1);

use App\Core\Clients\Phone;

/**
 * Telefony kontaktních osob do jednoho tvaru „+420 603 213 408"
 * (`Clients\Phone`). Nová čísla se upravují při uložení; tohle srovná ta,
 * která byla v kontaktech už předtím. Čísla, která pravidla nepoznají
 * (cizí předvolba, poznámka u čísla), zůstanou beze změny.
 */
return function (PDO $pdo): void {
    $update = $pdo->prepare('UPDATE `contacts` SET phone = :phone WHERE id = :id');

    foreach ($pdo->query("SELECT id, phone FROM `contacts` WHERE phone <> ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $phone = Phone::normalize((string) $row['phone']);

        if ($phone !== $row['phone']) {
            $update->execute(['phone' => $phone, 'id' => (int) $row['id']]);
        }
    }
};
