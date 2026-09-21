<?php

declare(strict_types=1);

/**
 * Vygeneruje řádek do .htpasswd pro HTTP Basic autentizaci nad správou.
 *
 * Spuštění: php dev/tools/generate-htpasswd.php <jméno> <heslo>
 *
 * Používá bcrypt ($2y$) — Apache mu rozumí od 2.4. Soubor .htpasswd patří
 * MIMO web root (o úroveň výš) a cesta k němu do www/.htaccess (varianta B).
 *
 * **Cena 5, ne výchozí PHP.** Tohle je jediné místo v projektu, kde se
 * nízká cena hodí — a je to schválně. Basic auth ověřuje heslo znovu
 * u **každého požadavku**, tedy i u každého obrázku a stylu na stránce;
 * s výchozí cenou (dnes 12) by každý z nich stál čtvrt vteřiny procesoru
 * a správa by se plazila. Apacheho vlastní `htpasswd -B` používá z téhož
 * důvodu 5. Bezpečnost to nesnižuje tak, jak to vypadá: soubor není
 * veřejný, takže offline útok předpokládá, že už je server prolomený,
 * a heslo z generátoru má entropie dost. Hesla uživatelů správy
 * (`Auth`, `create-admin.php`) se tímhle NEŘÍDÍ — ta se ověřují jednou
 * za přihlášení a zůstávají na výchozí ceně.
 */
const HTPASSWD_COST = 5;

if (PHP_SAPI !== 'cli') {
    exit('Skript se spouští z příkazové řádky.');
}

$username = trim((string) ($argv[1] ?? ''));
$password = (string) ($argv[2] ?? '');

if ($username === '' || $password === '') {
    fwrite(STDERR, "Použití: php dev/tools/generate-htpasswd.php <jméno> <heslo>\n");
    exit(1);
}

echo "Řádek do souboru .htpasswd-sprava (nahrajte MIMO web root):\n\n";
echo '    ' . $username . ':' . password_hash($password, PASSWORD_BCRYPT, ['cost' => HTPASSWD_COST]) . "\n\n";
echo "V www/.htaccess odkomentujte variantu B a doplňte cestu k souboru.\n";
