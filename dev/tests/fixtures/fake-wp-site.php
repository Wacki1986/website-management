<?php

declare(strict_types=1);

/**
 * Falešný WordPress web s pluginem MEDIAGRAFIK Monitor — router pro `php -S`.
 *
 * Napodobuje přesně to, co vidí hub: REST obálku pluginu, chybové odpovědi
 * WordPressu i chování bez hezkých adres. Podobu řídí proměnné prostředí:
 *
 *  FAKE_WP_MODE   ok | bad_key | no_plugin | html | no_pretty | slow | no_filemods
 *  FAKE_WP_KEY    klíč, který plugin přijme (výchozí mg_live_test…)
 *  FAKE_WP_LOGIN  public | hidden   (je /wp-login.php veřejný?)
 *  FAKE_WP_BASIC  1 = /wp-admin/ chráněný Basic auth
 *  FAKE_WP_STATUS HTTP stav kořene webu (uptime), výchozí 200
 *  FAKE_WP_PLUGIN_VERSION verze jednoho z pluginů v souhrnu (pro test rozdílů)
 *  FAKE_WP_RELEASE verze pluginu MEDIAGRAFIK Monitor v obálce (výchozí 1.0.0)
 *  FAKE_WP_STATE  soubor, kam si web zapíše aktualizovaný Elementor — další
 *                 souhrn ho pak hlásí v nové verzi (php -S je bez paměti)
 *  FAKE_WP_ICON   '' | link (ikony v <head>) | favicon (jen /favicon.ico)
 *                 | wplogo (/favicon.ico přesměruje na logo WordPressu)
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$query = [];
parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY), $query);
$mode = getenv('FAKE_WP_MODE') ?: 'ok';
$expectedKey = getenv('FAKE_WP_KEY') ?: 'mg_live_TESTKEY0000000000000000000000';

function fake_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fake_wp_error(int $status, string $code, string $message): void
{
    fake_json($status, ['code' => $code, 'message' => $message, 'data' => ['status' => $status]]);
}

/** Stav webu mezi požadavky (`php -S` nemá paměť): značky vedle `FAKE_WP_STATE`. */
function fake_state(string $what): string
{
    $state = getenv('FAKE_WP_STATE') ?: '';

    return $state === '' ? '' : $state . ($what === 'elementor' ? '' : '.' . $what);
}

function fake_happened(string $what): bool
{
    $file = fake_state($what);

    return $file !== '' && is_file($file);
}

/**
 * Akce hubu — podpis se ověřuje stejným vzorcem jako
 * `MG_Api_Key::verify_signature()`, nezávisle na kódu hubu.
 */
function fake_action(string $action, string $mode, string $key): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        fake_wp_error(404, 'rest_no_route', 'Pro adresu URL a metodu nebyla nalezena žádná trasa.');
    }

    $body = (string) file_get_contents('php://input');
    $timestamp = (int) ($_SERVER['HTTP_X_MG_TIMESTAMP'] ?? 0);
    $payload = "POST\n/mediagrafik-monitor/v1/actions/" . $action . "\n" . $timestamp . "\n" . $body;

    if ($timestamp === 0 || abs(time() - $timestamp) > 300 || !hash_equals(hash_hmac('sha256', $payload, $key), (string) ($_SERVER['HTTP_X_MG_SIGNATURE'] ?? ''))) {
        fake_wp_error(401, 'invalid_signature', 'Podpis požadavku nesouhlasí nebo vypršel — zkontrolujte čas serveru.');
    }

    if ($mode === 'no_filemods') {
        fake_wp_error(409, 'file_mods_disabled', 'Web má úpravy souborů zakázané (DISALLOW_FILE_MODS) — aktualizujte přes hosting.');
    }

    $request = (array) json_decode($body, true);
    $data = match ($action) {
        'plugin-update' => ['plugins' => fake_plugin_update((array) ($request['plugins'] ?? []))],
        'plugin-delete' => ['plugins' => fake_plugin_delete((array) ($request['plugins'] ?? []))],
        'core-update' => ['core' => fake_core_update((string) ($request['version'] ?? ''))],
        'login-link' => ['login' => fake_login_link((string) ($request['user'] ?? ''))],
        'plugin-activation' => ['plugin' => fake_plugin_activation((string) ($request['plugin'] ?? ''), !empty($request['active']))],
        default => null,
    };

    if ($data === null) {
        fake_wp_error(404, 'rest_no_route', 'Neznámá akce.');
    }

    fake_json(200, ['ok' => true, 'plugin_version' => getenv('FAKE_WP_RELEASE') ?: '1.0.0', 'generated_at' => date('c'), 'data' => $data]);
}

/** @param array<int, string> $files */
function fake_plugin_update(array $files): array
{
    $items = [];

    foreach ($files as $file) {
        if ($file === 'elementor/elementor.php') {
            touch(fake_state('elementor'));
            $items[] = ['file' => $file, 'name' => 'Elementor', 'status' => 'updated', 'from' => '3.23.1', 'to' => '3.24.0', 'message' => ''];
        } else {
            $items[] = ['file' => $file, 'name' => $file, 'status' => 'failed', 'from' => '1.0', 'to' => '1.0', 'message' => 'Balíček pro aktualizaci není k dispozici.'];
        }
    }

    return $items;
}

/** Smazat jde jen neaktivní Contact Form 7 — stejně jako u skutečného pluginu. @param array<int, string> $files */
function fake_plugin_delete(array $files): array
{
    $items = [];

    foreach ($files as $file) {
        if ($file === 'contact-form-7/wp-contact-form-7.php') {
            touch(fake_state('deleted'));
            $items[] = ['file' => $file, 'name' => 'Contact Form 7', 'version' => '5.9.3', 'status' => 'deleted', 'message' => ''];
        } else {
            $items[] = ['file' => $file, 'name' => $file, 'version' => '', 'status' => 'skipped', 'message' => 'Plugin je aktivní — nejdřív ho ve wp-admin deaktivujte.'];
        }
    }

    return $items;
}

function fake_core_update(string $version): array
{
    if ($version !== '6.9') {
        fake_wp_error(409, 'core_offer_changed', 'Web teď nabízí WordPress 6.9 místo ' . $version . ' — načtěte data znovu a potvrďte novou verzi.');
    }

    touch(fake_state('core'));

    return ['from' => '6.8.2', 'to' => '6.9'];
}

/** Na webu je jen správcovský účet `mediagrafik` — jako `MG_Login::create_link()`. */
function fake_login_link(string $user): array
{
    if ($user !== 'mediagrafik') {
        fake_wp_error(404, 'login_user_missing', 'Na webu není účet „' . $user . '" — založte ho, nebo ve Správě webů nastavte jiný.');
    }

    return ['url' => 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/?mg_login=' . str_repeat('ab', 32), 'user' => $user, 'expires_in' => 60];
}

/** Zapnuté/vypnuté pluginy (akce plugin-activation) — přebíjí výchozí stav v souhrnu. */
function fake_active_overrides(): array
{
    $file = fake_state('active');

    return $file !== '' && is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
}

/** Jako `MG_Site_Actions::set_active()`: sebe sama nevypne, neznámý plugin odmítne. */
function fake_plugin_activation(string $file, bool $active): array
{
    $known = ['woocommerce/woocommerce.php' => 'WooCommerce', 'elementor/elementor.php' => 'Elementor', 'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7'];

    if ($file === 'mediagrafik-monitor/mediagrafik-monitor.php') {
        fake_wp_error(409, 'plugin_self', 'MEDIAGRAFIK Monitor se ze správy vypnout nedá — správa by k webu ztratila přístup.');
    }

    if (!isset($known[$file])) {
        fake_wp_error(404, 'plugin_missing', 'Plugin na webu není — načtěte data znovu.');
    }

    $overrides = fake_active_overrides();
    $overrides[$file] = $active;
    file_put_contents(fake_state('active'), json_encode($overrides));

    return ['file' => $file, 'name' => $known[$file], 'active' => $active];
}

function fake_summary(): array
{
    $summary = fake_summary_base();
    $active = 0;

    foreach ($summary['plugins']['items'] as $i => $item) {
        $summary['plugins']['items'][$i]['is_active'] = fake_active_overrides()[$item['file']] ?? $item['is_active'];
        $active += $summary['plugins']['items'][$i]['is_active'] ? 1 : 0;
    }

    $summary['plugins']['active'] = $active;

    return $summary;
}

function fake_summary_base(): array
{
    $pluginVersion = getenv('FAKE_WP_PLUGIN_VERSION') ?: '9.3.0';
    $elementor = fake_happened('elementor')
        ? ['version' => '3.24.0', 'has_update' => false, 'new_version' => null]
        : ['version' => '3.23.1', 'has_update' => true, 'new_version' => '3.24.0'];

    return [
        'site' => ['name' => 'Kavárna Dobrá', 'url' => 'http://127.0.0.1/', 'admin_email' => 'info@kavarnadobra.cz', 'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'multisite' => false],
        'wordpress' => (fake_happened('core') ? ['version' => '6.9', 'has_update' => false, 'new_version' => null] : ['version' => '6.8.2', 'has_update' => true, 'new_version' => '6.9']) + ['debug' => false, 'auto_updates' => ''],
        'server' => ['php_version' => '7.4.33', 'db_type' => 'MariaDB', 'db_version' => '10.6.18', 'db_size_mb' => 312, 'memory_limit' => '256M', 'https' => true, 'server_software' => 'Apache'],
        'theme' => ['name' => 'Astra', 'version' => '4.8.2', 'is_child' => true, 'parent_name' => 'Astra', 'has_update' => false],
        'plugins' => [
            'total' => fake_happened('deleted') ? 3 : 4, 'active' => 3, 'updates' => $elementor['has_update'] ? 1 : 0, 'security_updates' => 0,
            'items' => [
                ['file' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'author' => 'Automattic', 'version' => $pluginVersion, 'is_active' => true, 'has_update' => false, 'new_version' => null, 'auto_update' => false],
                ['file' => 'elementor/elementor.php', 'name' => 'Elementor', 'author' => 'Elementor.com', 'is_active' => true, 'auto_update' => false] + $elementor,
                ['file' => 'mediagrafik-monitor/mediagrafik-monitor.php', 'name' => 'MEDIAGRAFIK Monitor', 'author' => 'Mediagrafik.cz', 'version' => getenv('FAKE_WP_RELEASE') ?: '1.0.0', 'is_active' => true, 'has_update' => false, 'new_version' => null, 'auto_update' => true],
                ...(fake_happened('deleted') ? [] : [['file' => 'contact-form-7/wp-contact-form-7.php', 'name' => 'Contact Form 7', 'author' => 'Takayuki Miyoshi', 'version' => '5.9.3', 'is_active' => false, 'has_update' => false, 'new_version' => null, 'auto_update' => false]]),
            ],
        ],
        'content' => ['post_types' => [
            ['slug' => 'post', 'label' => 'Příspěvky', 'published' => 148, 'drafts' => 3, 'latest' => ['title' => 'Podzimní menu', 'date' => '2026-09-14 10:00:00', 'days_ago' => 4]],
            ['slug' => 'page', 'label' => 'Stránky', 'published' => 12, 'drafts' => 1, 'latest' => ['title' => 'Kontakt', 'date' => '2026-07-18 10:00:00', 'days_ago' => 62]],
        ]],
        'security' => ['checks' => [
            ['id' => 'security_plugin', 'status' => 'warning', 'value' => 'Wordfence 7.11.4', 'note' => 'Běží, ale čeká na aktualizaci na 8.0.1'],
            ['id' => 'two_factor', 'status' => 'warning', 'value' => 'Částečně · 1 z 3', 'note' => 'Zapnuto u 1 ze 3 administrátorů'],
            ['id' => 'content_dir', 'status' => 'error', 'value' => '/wp-content', 'note' => 'Výchozí cesta, obsah je dohledatelný'],
        ]],
        'backup' => ['last_backup_at' => '2026-09-17 03:00:00', 'source' => 'UpdraftPlus'],
    ];
}

function fake_plugin_route(string $endpoint, string $mode, string $expectedKey): void
{
    if ($mode === 'no_plugin') {
        fake_wp_error(404, 'rest_no_route', 'Pro adresu URL nebyla nalezena žádná trasa.');
    }

    if ($mode === 'html') {
        http_response_code(500);
        header('Content-Type: text/html');
        echo '<html><body>Fatal error</body></html>';
        exit;
    }

    if ($mode === 'slow') {
        sleep(3);
    }

    $given = (string) ($_SERVER['HTTP_X_MG_KEY'] ?? '');

    if ($given === '') {
        fake_wp_error(401, 'missing_key', 'Chybí hlavička X-MG-Key.');
    }

    if ($mode === 'bad_key' || !hash_equals($expectedKey, $given)) {
        fake_wp_error(401, 'invalid_key', 'Neplatný API klíč.');
    }

    if (str_starts_with($endpoint, 'actions/')) {
        fake_action(substr($endpoint, 8), $mode, $given);
    }

    $data = match ($endpoint) {
        'ping' => ['site_url' => 'http://127.0.0.1/', 'wp_version' => '6.8.2', 'time' => date('c')],
        'summary' => fake_summary(),
        'security' => ['security' => fake_summary()['security']],
        default => null,
    };

    if ($data === null) {
        fake_wp_error(404, 'rest_no_route', 'Neznámý endpoint.');
    }

    fake_json(200, ['ok' => true, 'plugin_version' => getenv('FAKE_WP_RELEASE') ?: '1.0.0', 'generated_at' => date('c'), 'data' => $data]);
}

// REST přes ?rest_route= funguje vždy (i bez hezkých adres).
if (isset($query['rest_route']) && preg_match('#^/mediagrafik-monitor/v1/([a-z/-]+)$#', (string) $query['rest_route'], $m) === 1) {
    fake_plugin_route($m[1], $mode, $expectedKey);
}

if (preg_match('#^/wp-json/mediagrafik-monitor/v1/([a-z/-]+)$#', $path, $m) === 1) {
    if ($mode === 'no_pretty') {
        // Hosting bez mod_rewrite: /wp-json/ vrací HTML 404.
        http_response_code(404);
        header('Content-Type: text/html');
        echo '<html><body><h1>Not Found</h1></body></html>';
        exit;
    }

    fake_plugin_route($m[1], $mode, $expectedKey);
}

if ($path === '/wp-login.php') {
    if ((getenv('FAKE_WP_LOGIN') ?: 'public') === 'hidden') {
        http_response_code(404);
        exit;
    }

    header('Content-Type: text/html');
    echo '<html><body><form name="loginform" id="loginform" action="/wp-login.php" method="post"></form></body></html>';
    exit;
}

if ($path === '/wp-admin/' || $path === '/wp-admin') {
    if ((getenv('FAKE_WP_BASIC') ?: '') === '1') {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="admin"');
        exit;
    }

    http_response_code(302);
    header('Location: /wp-login.php?redirect_to=%2Fwp-admin%2F');
    exit;
}

// Ikony webu (FAKE_WP_ICON): obrázek 1×1 PNG, jako ICO, nebo výchozí „W".
$iconMode = getenv('FAKE_WP_ICON') ?: '';
$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

if (str_starts_with($path, '/wp-content/uploads/') || $path === '/wp-includes/images/w-logo-blue-white-bg.png') {
    header('Content-Type: image/png');
    echo $png;
    exit;
}

if ($path === '/favicon.ico' && $iconMode === 'favicon') {
    // ICO s jedním obrázkem uvnitř ve formátu PNG (tak je dnes většina favicon).
    header('Content-Type: image/x-icon');
    echo pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 1, 1, 0, 0, 1, 32, strlen($png), 22) . $png;
    exit;
}

if ($path === '/favicon.ico' && $iconMode === 'wplogo') {
    // WordPress bez „Ikony webu" přesměruje /favicon.ico na své logo.
    http_response_code(302);
    header('Location: /wp-includes/images/w-logo-blue-white-bg.png');
    exit;
}

// Kořen webu — kontrola dostupnosti.
http_response_code((int) (getenv('FAKE_WP_STATUS') ?: 200));
header('Content-Type: text/html');
echo '<html><head>'
    . ($iconMode === 'link'
        ? '<link rel="icon" href="/wp-content/uploads/cropped-icon-32x32.png" sizes="32x32">'
            . '<link rel="icon" href="https://cdn.example.test/logo.svg" type="image/svg+xml">'
            . '<link rel="apple-touch-icon" href="/wp-content/uploads/cropped-icon-180x180.png">'
        : '')
    . '</head><body>Kavárna Dobrá</body></html>';
