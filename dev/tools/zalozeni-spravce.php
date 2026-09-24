<?php

declare(strict_types=1);

/**
 * JEDNORÁZOVÉ založení účtu správce na hostingu bez SSH.
 *
 * Postup (provozní dokumentace, krok 4):
 *  1. Vygenerujte si dlouhý token (např. `php dev/tools/generate-tokens.php`)
 *     a nahrajte ho jako obsah souboru `storage/install-token.txt` na serveru.
 *  2. Tento soubor nahrajte do web rootu správy jako `zalozeni-spravce.php`.
 *  3. Otevřete https://sprava…/zalozeni-spravce.php, vyplňte token a údaje.
 *  4. Po úspěchu se skript sám smaže (a smaže i token) — zkontrolujte,
 *     že adresa vrací 404. Kdyby smazání nevyšlo, smažte oba soubory ručně.
 *
 * Bez platného tokenu neudělá nic; existující účet umí i odemknout / nastavit
 * mu nové heslo a vypnout dvoufázové přihlášení (ztracený telefon) — tatáž
 * cesta jako `create-admin.php` na příkazové řádce.
 */

use App\Core\Auth\PasswordPolicy;

/** @var \App\Core\Kernel $kernel */
$kernel = require __DIR__ . '/app/bootstrap.php';

$tokenPath = $kernel->storagePath('install-token.txt');
$expected = is_file($tokenPath) ? trim((string) file_get_contents($tokenPath)) : '';

$render = static function (string $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="cs"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>Založení správce</title>'
        . '<body style="font-family:system-ui;max-width:480px;margin:8vh auto;padding:0 16px;line-height:1.5">'
        . $body . '</body></html>';
    exit;
};

if ($expected === '' || strlen($expected) < 32) {
    $render('<h1>Skript je vypnutý</h1><p>Chybí <code>storage/install-token.txt</code> '
        . 's tokenem o délce aspoň 32 znaků. Nahrajte ho a načtěte stránku znovu.</p>', 403);
}

/**
 * Databáze se zkouší hned — ať se na špatné přístupy přijde před vyplněním
 * formuláře a bez stack trace (hláška PDO obsahuje jméno uživatele a cesty).
 */
$dbReady = false;

try {
    $dbReady = $kernel->db()->isAvailable();
} catch (Throwable) {
    $dbReady = false;
}

if (!$dbReady) {
    $render('<h1>Databáze nedostupná</h1>'
        . '<p>Připojení podle <code>config/env.php</code> selhalo (přístup odepřen, nebo databáze neexistuje). '
        . 'Zkontrolujte v cPanelu (MySQL Databases):</p><ol>'
        . '<li>název databáze <strong>včetně prefixu účtu</strong> (např. <code>ucet_sprava</code>),</li>'
        . '<li>uživatel existuje a je <strong>přiřazený k databázi</strong> („Add User To Database", ALL PRIVILEGES) '
        . '— to je nejčastější chybějící krok,</li>'
        . '<li>heslo je v <code>env.php</code> opsané přesně; apostrof nebo zpětné lomítko v hesle '
        . 'se v PHP řetězci musí escapovat — jednodušší je vygenerovat heslo bez nich,</li>'
        . '<li><code>host</code> bývá na cPanelu <code>localhost</code>.</li></ol>'
        . '<p>Po opravě stačí stránku načíst znovu.</p>', 503);
}

$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$token = (string) ($_POST['token'] ?? '');
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$error = '';

if ($submitted && hash_equals($expected, $token)) {
    $policyError = PasswordPolicy::validate($password);

    if ($username === '') {
        $error = 'Vyplňte přihlašovací jméno.';
    } elseif ($policyError !== null) {
        $error = $policyError;
    } else {
        try {
            $report = $kernel->migrator()->run();
        } catch (Throwable $e) {
            $render('<h1>Migrace selhala</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>', 500);
        }

        if ($report['failed'] !== null) {
            $render('<h1>Migrace selhala</h1><p>' . htmlspecialchars($report['message']) . '</p>', 500);
        }

        $users = $kernel->users();
        // I mezi e-maily — viz `create-admin.php`, tatáž past.
        $existing = $users->findByLogin($username);
        $hash = PasswordPolicy::hash($password);

        if ($existing !== null) {
            // Odemčení je i cesta ze ztraceného telefonu — dvoufázové
            // přihlášení se vypne a účet si ho po přihlášení zapne znovu.
            $users->update((int) $existing['id'], ['password_hash' => $hash, 'is_active' => 1]);
            $kernel->twoFactor()->disable((int) $existing['id']);
        } else {
            $users->create($username, $hash);
        }

        // Uklidit po sobě: token i tento skript. Selhání úklidu se hlásí.
        $tokenGone = @unlink($tokenPath);
        $selfGone = @unlink(__FILE__);

        $render('<h1>Hotovo</h1><p>Účet <strong>' . htmlspecialchars($username) . '</strong> je připravený — '
            . '<a href="./prihlaseni">přihlaste se</a>.</p>'
            . (!$tokenGone || !$selfGone
                ? '<p style="color:#b00"><strong>Pozor:</strong> nepodařilo se smazat '
                    . (!$selfGone ? 'tento skript' : '') . (!$tokenGone && !$selfGone ? ' a ' : '')
                    . (!$tokenGone ? 'storage/install-token.txt' : '')
                    . ' — smažte je ručně přes FTP, hned teď.</p>'
                : '<p>Skript i token se smazaly samy.</p>'));
    }
} elseif ($submitted) {
    $error = 'Token nesouhlasí.';
}

$render('<h1>Založení správce</h1>'
    . '<p>Jednorázový krok při nasazení správy — po dokončení se skript sám smaže.</p>'
    . ($error !== '' ? '<p style="color:#b00">' . htmlspecialchars($error) . '</p>' : '')
    . '<form method="post">'
    . '<p><label>Token ze storage/install-token.txt<br><input name="token" required style="width:100%"></label></p>'
    . '<p><label>Přihlašovací jméno<br><input name="username" required style="width:100%" value="'
    . htmlspecialchars($username) . '"></label></p>'
    . '<p><label>Heslo (aspoň 10 znaků)<br><input type="password" name="password" required style="width:100%"></label></p>'
    . '<p><button type="submit">Založit správce</button></p>'
    . '</form>');
