<?php

declare(strict_types=1);

/**
 * Založení (nebo reset hesla) účtu správce.
 *
 * Spuštění: php dev/tools/create-admin.php <jméno> <heslo>
 *
 * Správa nemá instalační průvodce ani „zapomenuté heslo" — tohle je ta cesta,
 * kterou popisuje provozní dokumentace: kdo má přístup k souborům (FTP/CLI),
 * je vlastník a smí si účet založit či odemknout. Skript projede i migrace,
 * takže funguje na čerstvé databázi.
 */

use App\Core\Auth\PasswordPolicy;

if (PHP_SAPI !== 'cli') {
    exit('Skript se spouští z příkazové řádky.');
}

$username = trim((string) ($argv[1] ?? ''));
$password = (string) ($argv[2] ?? '');

if ($username === '' || $password === '') {
    fwrite(STDERR, "Použití: php dev/tools/create-admin.php <jméno> <heslo>\n");
    exit(1);
}

/** @var \App\Core\Kernel $kernel */
$kernel = require dirname(__DIR__, 2) . '/www/app/bootstrap.php';

$policyError = PasswordPolicy::validate($password);

if ($policyError !== null) {
    fwrite(STDERR, $policyError . "\n");
    exit(1);
}

$report = $kernel->migrator()->run();

if ($report['failed'] !== null) {
    fwrite(STDERR, 'Migrace selhala: ' . $report['message'] . "\n");
    exit(1);
}

$users = $kernel->users();
// Hledá se i mezi e-maily: kdo tenhle nástroj potřebuje, obvykle si nepamatuje
// přihlašovací jméno. Bez toho by adresa místo jména založila druhý účet,
// jehož jméno by tomu původnímu zastínilo přihlášení e-mailem.
$existing = $users->findByLogin($username);
$hash = PasswordPolicy::hash($password);

if ($existing !== null) {
    $users->update((int) $existing['id'], ['password_hash' => $hash, 'is_active' => 1]);
    // Odemčení účtu je i cesta ze ztraceného telefonu: dvoufázové přihlášení
    // se vypne, po přihlášení si ho účet zapne znovu s novým telefonem.
    $kernel->twoFactor()->disable((int) $existing['id']);
    echo 'Účtu „' . $username . '“ bylo nastaveno nové heslo a vypnuto dvoufázové přihlášení.' . "\n";
    exit(0);
}

$users->create($username, $hash);
echo 'Účet „' . $username . '“ byl založen. Přihlaste se na /prihlaseni.' . "\n";
