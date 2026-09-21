<?php

declare(strict_types=1);

/**
 * Falešná instance pro testy HealthClienta.
 *
 * Router pro `php -S`: odpovídá jen na /system/health, podobu odpovědi řídí
 * proměnná prostředí FAKE_HEALTH_MODE (ok | degraded | html).
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

/**
 * Hosting s nefunkčním přepisem adres (FAKE_CLEAN_404=1): čisté cesty vrací
 * holé 404 jako Apache/LiteSpeed bez .htaccess, tvar `/index.php/…` funguje —
 * na testy záložky SystemClienta a její detekce v Provisioneru.
 */
if ((getenv('FAKE_CLEAN_404') ?: '') === '1' && !str_contains((string) $path, '/index.php/')) {
    http_response_code(404);
    exit;
}

/**
 * Pověření jako v jádru (`SystemController::authorize()`): napřed jednorázový
 * handshake, teprve pak token.
 *
 * Handshake se ověřuje jen když fixture ví, kde má instance adresář
 * (`FAKE_INSTANCE_DIR`) — bez něj se falešná instance chová jako ta na cizím
 * serveru, tedy jen na token. Obojí je potřeba: většina testů jede přes token
 * a `HandshakeTest` přes nonce.
 */
function fake_authorized(): bool
{
    $dir = getenv('FAKE_INSTANCE_DIR') ?: '';
    $nonce = (string) ($_SERVER['HTTP_X_HANDSHAKE_NONCE'] ?? '');

    if ($dir !== '' && $nonce !== '') {
        $file = rtrim($dir, '/\\') . '/storage/handshake.json';
        $records = json_decode((string) @file_get_contents($file), true);
        $hash = hash('sha256', $nonce);
        $found = false;
        $keep = [];

        foreach (is_array($records) ? $records : [] as $record) {
            if (is_array($record) && hash_equals((string) ($record['hash'] ?? ''), $hash)) {
                $found = true; // jednorázovost: záznam se nepřenáší dál
                continue;
            }

            $keep[] = $record;
        }

        if ($found) {
            @file_put_contents($file, json_encode($keep));

            return true;
        }
    }

    $expected = getenv('FAKE_MIGRATE_TOKEN') ?: '';
    $given = (string) ($_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '');

    return $expected === '' || hash_equals($expected, $given);
}

/**
 * Porovnává se konec cesty: testy potřebují víc „instancí" na jednom
 * serveru (evidence má unikátní URL), takže se liší prefixem —
 * http://127.0.0.1:8131/a i …/b obsluhuje tentýž proces.
 */
$isMigrate = str_ends_with((string) $path, '/system/migrate');
$isHealth = str_ends_with((string) $path, '/system/health');
$isSuspend = str_ends_with((string) $path, '/system/suspend');
$isResume = str_ends_with((string) $path, '/system/resume');

/**
 * Co falešná instance „ví" o konci předplatného — zapisuje /system/subscription,
 * čte health (jen s FAKE_SUBSCRIPTION=1). Soubor podle portu: testů běží víc
 * a každý chce svou instanci; tady se smí použít jen PHP bez rámce.
 */
function fake_subscription_file(): string
{
    return sys_get_temp_dir() . '/fake-instance-subscription-' . (string) ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
}

/**
 * POST /system/subscription — datum konce předplatného jako v jádru:
 * token hlavičkou, `valid_until` (Y-m-d, prázdné = smazat), JSON odpověď.
 */
if (str_ends_with((string) $path, '/system/subscription')) {
    header('Content-Type: application/json; charset=UTF-8');

    if (!fake_authorized()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Neplatný token.']);
        exit;
    }

    $validUntil = trim((string) ($_POST['valid_until'] ?? ''));

    if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Datum musí být ve tvaru RRRR-MM-DD.']);
        exit;
    }

    // Provozní zpráva jako v jádru: JSON {text, until}, prázdný text maže.
    // Drží se ve stejném souboru jako valid_until — jeden stav na port.
    $stored = json_decode((string) @file_get_contents(fake_subscription_file()), true);
    $notice = is_array($stored) ? ($stored['notice'] ?? null) : null;
    $noticeRaw = (string) ($_POST['notice'] ?? '');

    if ($noticeRaw !== '') {
        $decoded = json_decode($noticeRaw, true);
        $notice = is_array($decoded) && trim((string) ($decoded['text'] ?? '')) !== ''
            ? ['text' => (string) $decoded['text'], 'until' => (string) ($decoded['until'] ?? '')]
            : null;
    }

    // Spravovaná pošta jako v jádru: z konfigurace se ukládá jen otisk
    // (víc health nehlásí a testy víc nepotřebují); `{}` ho maže.
    // Výpočet zrcadlí ManagedMail::fingerprintOf() — viz SubscriptionSync.
    $mailFingerprint = is_array($stored) ? ($stored['mail_fingerprint'] ?? null) : null;
    $mailRaw = (string) ($_POST['mail'] ?? '');

    if ($mailRaw !== '') {
        $mail = json_decode($mailRaw, true);
        $mailFingerprint = is_array($mail) && $mail !== []
            ? hash('sha256', implode("\n", [
                (string) ($mail['transport'] ?? ''),
                (string) ($mail['from_address'] ?? ''),
                (string) ($mail['from_name'] ?? ''),
                (string) ($mail['host'] ?? ''),
                (string) max(1, min(65535, (int) ($mail['port'] ?? 587))),
                (string) ($mail['security'] ?? ''),
                (string) ($mail['username'] ?? ''),
                (string) ($mail['password'] ?? ''),
            ]))
            : null;
    }

    // Tarif jako v jádru: JSON {name, max_users}, prázdný objekt ho maže.
    $tariff = is_array($stored) ? ($stored['tariff'] ?? null) : null;
    $tariffRaw = (string) ($_POST['tariff'] ?? '');

    if ($tariffRaw !== '') {
        $decoded = json_decode($tariffRaw, true);
        $tariff = is_array($decoded) && $decoded !== []
            ? ['name' => (string) ($decoded['name'] ?? ''), 'max_users' => isset($decoded['max_users']) ? (int) $decoded['max_users'] : null]
            : null;
    }

    // Profil podpory jako v jádru: z JSON {name, email, photo} se drží jen
    // otisk (víc health nehlásí); `{}` ho maže. Výpočet zrcadlí
    // System\SupportProfile::fingerprintOf() — viz Instances\SupportProfile.
    $supportFingerprint = is_array($stored) ? ($stored['support_profile_fingerprint'] ?? null) : null;
    $supportRaw = (string) ($_POST['support_profile'] ?? '');

    if ($supportRaw !== '') {
        $decoded = json_decode($supportRaw, true);
        $photo = is_array($decoded) ? (string) base64_decode((string) ($decoded['photo'] ?? ''), true) : '';
        $supportFingerprint = is_array($decoded) && $decoded !== []
            ? hash('sha256', implode("\n", [
                trim((string) ($decoded['name'] ?? '')),
                trim((string) ($decoded['email'] ?? '')),
                $photo !== '' ? hash('sha256', $photo) : '',
            ]))
            : null;
    }

    if ($validUntil === '' && $notice === null && $mailFingerprint === null && $tariff === null && $supportFingerprint === null) {
        @unlink(fake_subscription_file());
    } else {
        file_put_contents(fake_subscription_file(), json_encode([
            'valid_until' => $validUntil === '' ? null : $validUntil,
            'notice' => $notice,
            'mail_fingerprint' => $mailFingerprint,
            'tariff' => $tariff,
            'support_profile_fingerprint' => $supportFingerprint,
        ]));
    }

    echo json_encode(['status' => 'success', 'valid_until' => $validUntil === '' ? null : $validUntil, 'notice' => $notice, 'mail_fingerprint' => $mailFingerprint, 'tariff' => $tariff, 'support_profile_fingerprint' => $supportFingerprint]);
    exit;
}

/**
 * GET /system/backup/download?name=… a GET /system/backup/archive — stažení
 * zálohy jako v jádru (08-sprava-instanci.md §4a): 200 + soubor, chyba JSON.
 * Tělo se posílá po kusech, aby se otestovalo průchozí streamování.
 */
if (str_ends_with((string) $path, '/system/backup/download') || str_ends_with((string) $path, '/system/backup/archive')) {
    if (!fake_authorized()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Neplatný token.']);
        exit;
    }

    $archive = str_ends_with((string) $path, '/system/backup/archive');
    $name = (string) ($_GET['name'] ?? '');

    if (!$archive && $name !== '' && $name !== '2026-08-16-101500.sql.enc') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Taková záloha tu není: ' . $name]);
        exit;
    }

    header('Content-Type: ' . ($archive ? 'application/gzip' : 'application/sql; charset=UTF-8'));
    header('Content-Disposition: attachment; filename="' . ($archive ? 'zaloha-firma-2026-08-16-101500.tar.gz' : '2026-08-16-101500.sql') . '"');

    foreach (['-- dump ', 'part one ', 'part two', "\n"] as $piece) {
        echo $piece;
        flush();
    }

    exit;
}

/**
 * POST /system/backup — čerstvá záloha na vyžádání (před smazáním).
 */
if (str_ends_with((string) $path, '/system/backup')) {
    header('Content-Type: application/json; charset=UTF-8');

    if (!fake_authorized()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Neplatný token.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'backup' => '2026-08-16-101500.sql.enc']);
    exit;
}

/**
 * POST /system/login-link — jednorázový přihlašovací odkaz jako v jádru.
 */
if (str_ends_with((string) $path, '/system/login-link')) {
    header('Content-Type: application/json; charset=UTF-8');

    if (!fake_authorized()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Neplatný token.']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'url' => 'https://fake.dispu.cz/prihlaseni-odkazem?token=' . bin2hex(random_bytes(8)),
        'username' => 'admin',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * POST /system/setup — založení prvního administrátora jako v jádru
 * (JSON varianta z fáze 5). FAKE_SETUP_MODE=exists simuluje 409.
 */
if (str_ends_with((string) $path, '/system/setup')) {
    header('Content-Type: application/json; charset=UTF-8');

    if ((getenv('FAKE_SETUP_MODE') ?: '') === 'exists') {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Aplikace už má uživatele.']);
        exit;
    }

    $expected = getenv('FAKE_SETUP_TOKEN') ?: '';
    $given = (string) ($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '');

    if ($expected !== '' && !hash_equals($expected, $given)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Neplatný token.']);
        exit;
    }

    if ((string) ($_POST['username'] ?? '') === '' || (string) ($_POST['password'] ?? '') === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Chybí jméno nebo heslo.']);
        exit;
    }

    $response = ['status' => 'success', 'user_id' => 1];

    // `invite=1` — jádro místo hesla vydá odkaz k jeho nastavení a vrátí ho
    // v odpovědi (`PasswordReset::inviteLinkFor()`). Posílá ho pak správa,
    // ne instance. Odkaz se schválně liší pokaždé: test tak pozná, jestli
    // se uložil ten skutečně vydaný.
    if ((string) ($_POST['invite'] ?? '') === '1') {
        $response['invite_url'] = 'https://fake.test/reset-hesla?token=' . bin2hex(random_bytes(8));
    }

    echo json_encode($response);
    exit;
}

/**
 * POST /system/suspend a /system/resume — jako jádro: token hlavičkou,
 * JSON odpověď. FAKE_SUSPEND_MODE=fail simuluje nezapisovatelný storage.
 */
if ($isSuspend || $isResume) {
    header('Content-Type: application/json; charset=UTF-8');

    if (!fake_authorized()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Neplatný token.']);
        exit;
    }

    if ((getenv('FAKE_SUSPEND_MODE') ?: 'success') === 'fail') {
        http_response_code(500);
        echo json_encode(['status' => 'error',
            'message' => 'Příznak se nepodařilo zapsat — zkontrolujte práva na adresář storage/.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'suspended' => $isSuspend]);
    exit;
}

/**
 * GET /system/demo-reset — jako jádro: mimo demo instanci (FAKE_DEMO ≠ 1)
 * JSON 404 ještě před ověřením tokenu; FAKE_DEMO_MODE=busy simuluje
 * souběžnou migraci (409).
 */
if (str_ends_with((string) $path, '/system/demo-reset')) {
    header('Content-Type: application/json; charset=UTF-8');

    if ((getenv('FAKE_DEMO') ?: '') !== '1') {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Stránka neexistuje.']);
        exit;
    }

    if (!fake_authorized()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Neplatný token.']);
        exit;
    }

    if ((getenv('FAKE_DEMO_MODE') ?: 'success') === 'busy') {
        http_response_code(409);
        echo json_encode(['status' => 'running',
            'message' => 'Obnova nebo migrace právě probíhá v jiném požadavku.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'migrations' => 14,
        'seed' => ['users' => 5, 'customers' => 8, 'entries' => 31],
        'uploads_removed' => 2,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * POST /system/migrate — chová se jako jádro: token hlavičkou, JSON odpověď,
 * úspěch/selhání podle FAKE_MIGRATE_MODE (success | fail).
 */
if ($isMigrate) {
    // Spadlé PHP s vypnutým výpisem chyb: HTTP 500 a prázdné tělo.
    if ((getenv('FAKE_MIGRATE_MODE') ?: '') === 'fatal') {
        http_response_code(500);
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');

    if (!fake_authorized()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Neplatný token.']);
        exit;
    }

    if ((getenv('FAKE_MIGRATE_MODE') ?: 'success') === 'fail') {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'backup' => '2026-08-14-1402-migrace.sql.enc',
            'applied' => ['core:2026_08_10_000000_prvni'],
            'failed' => 'core:2026_08_12_000000_druha',
            'message' => 'Migrace core:2026_08_12_000000_druha selhala: SQLSTATE[HY000] [1205] Lock wait timeout',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'backup' => '2026-08-14-1402-migrace.sql.enc',
        'applied' => ['core:2026_08_10_000000_prvni', 'core:2026_08_12_000000_druha'],
        'installed_modules' => [],
        'message' => 'Aplikováno migrací: 2.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$isHealth) {
    http_response_code(404);
    exit;
}

$mode = getenv('FAKE_HEALTH_MODE') ?: 'ok';

if ($mode === 'html') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><title>Ne-JSON</title>';
    exit;
}

// Čekající migrace: databáze žije (HTTP 200), status „degraded", pending > 0.
if ($mode === 'pending') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'degraded',
        'version' => '1.0.0-dev',
        'company' => 'fake',
        'environment' => 'production',
        'php' => '8.4',
        'database' => 'ok',
        'pending_migrations' => 2,
        'modules' => ['entries', 'absences', 'timelogs'],
        'backup' => ['ready' => true, 'count' => 7, 'size' => 12345, 'latest' => '2026-08-13-030001.sql.enc',
            'latest_at' => '2026-08-13T03:00:01+02:00', 'age_hours' => 9.2, 'stale' => false],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$degraded = $mode === 'degraded';

http_response_code($degraded ? 503 : 200);
header('Content-Type: application/json; charset=UTF-8');

// Co instance ví o konci předplatného — jen s FAKE_SUBSCRIPTION=1, ať se
// ostatním testům nemění podoba odpovědi (viz fake_subscription_file()).
$subscription = [];

if ((getenv('FAKE_SUBSCRIPTION') ?: '') === '1') {
    $stored = json_decode((string) @file_get_contents(fake_subscription_file()), true);
    $subscription = [
        'valid_until' => is_array($stored) ? ($stored['valid_until'] ?? null) : null,
        'notice' => is_array($stored) ? ($stored['notice'] ?? null) : null,
        'mail_fingerprint' => is_array($stored) ? ($stored['mail_fingerprint'] ?? null) : null,
        'tariff' => is_array($stored) ? ($stored['tariff'] ?? null) : null,
        'support_profile_fingerprint' => is_array($stored) ? ($stored['support_profile_fingerprint'] ?? null) : null,
    ];
}

echo json_encode(($degraded
    ? [
        'status' => 'degraded',
        'version' => '1.0.0-dev',
        'company' => 'fake',
        'environment' => 'production',
        'php' => '8.4',
        'database' => 'unavailable',
        'pending_migrations' => null,
        'modules' => [],
        'backup' => null,
    ]
    : [
        'status' => 'ok',
        'version' => '1.0.0-dev',
        'company' => 'fake',
        'environment' => 'production',
        'php' => '8.4',
        'database' => 'ok',
        'pending_migrations' => 0,
        'modules' => ['entries', 'absences', 'timelogs'],
        'backup' => ['ready' => true, 'count' => 7, 'size' => 12345, 'latest' => '2026-08-13-030001.sql.enc',
            'latest_at' => '2026-08-13T03:00:01+02:00', 'age_hours' => 9.2, 'stale' => false],
    ]) + $subscription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
