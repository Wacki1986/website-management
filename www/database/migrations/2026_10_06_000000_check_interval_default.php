<?php

declare(strict_types=1);

/**
 * Výchozí frekvence kontrol nového webu: každou hodinu místo 15 minut.
 * Výpadek se i tak potvrdí rychle — po selhané kontrole se web zkouší
 * znovu po 15 minutách (`SiteRepository::RETRY_INTERVAL`).
 *
 * - výchozí hodnota sloupce (web založený bez frekvence, třeba importem);
 * - uložené nastavení `monitor_interval_min` (Nastavení → Monitoring), ze
 *   kterého bere frekvenci formulář „Přidat web" — rozhodnutí správce
 *   6. 10. 2026, proto se přepisuje i dřív uložená hodnota.
 *
 * Stávající weby si svou frekvenci nechávají.
 */
return function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE `sites` ALTER COLUMN `check_interval_min` SET DEFAULT 60');
    $pdo->exec("UPDATE `settings` SET `value` = '60', `updated_at` = NOW() WHERE `key` = 'monitor_interval_min'");
};
