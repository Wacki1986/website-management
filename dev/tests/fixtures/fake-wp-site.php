<?php

declare(strict_types=1);

/**
 * Falešný WordPress web s pluginem MEDIAGRAFIK Monitor — router pro `php -S`.
 *
 * Napodobuje přesně to, co vidí hub: REST obálku pluginu, chybové odpovědi
 * WordPressu i chování bez hezkých adres. Podobu řídí proměnné prostředí:
 *
 *  FAKE_WP_MODE   ok | bad_key | no_plugin | html | no_pretty | slow
 *  FAKE_WP_KEY    klíč, který plugin přijme (výchozí mg_live_test…)
 *  FAKE_WP_LOGIN  public | hidden   (je /wp-login.php veřejný?)
 *  FAKE_WP_BASIC  1 = /wp-admin/ chráněný Basic auth
 *  FAKE_WP_STATUS HTTP stav kořene webu (uptime), výchozí 200
 *  FAKE_WP_PLUGIN_VERSION verze jednoho z pluginů v souhrnu (pro test rozdílů)
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

function fake_summary(): array
{
    $pluginVersion = getenv('FAKE_WP_PLUGIN_VERSION') ?: '9.3.0';

    return [
        'site' => ['name' => 'Kavárna Dobrá', 'url' => 'http://127.0.0.1/', 'admin_email' => 'info@kavarnadobra.cz', 'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'multisite' => false],
        'wordpress' => ['version' => '6.8.2', 'has_update' => true, 'new_version' => '6.9', 'debug' => false, 'auto_updates' => ''],
        'server' => ['php_version' => '7.4.33', 'db_type' => 'MariaDB', 'db_version' => '10.6.18', 'db_size_mb' => 312, 'memory_limit' => '256M', 'https' => true, 'server_software' => 'Apache'],
        'theme' => ['name' => 'Astra', 'version' => '4.8.2', 'is_child' => true, 'parent_name' => 'Astra', 'has_update' => false],
        'plugins' => [
            'total' => 3, 'active' => 2, 'updates' => 1, 'security_updates' => 0,
            'items' => [
                ['file' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'author' => 'Automattic', 'version' => $pluginVersion, 'is_active' => true, 'has_update' => false, 'new_version' => null, 'auto_update' => false],
                ['file' => 'elementor/elementor.php', 'name' => 'Elementor', 'author' => 'Elementor.com', 'version' => '3.23.1', 'is_active' => true, 'has_update' => true, 'new_version' => '3.24.0', 'auto_update' => false],
                ['file' => 'contact-form-7/wp-contact-form-7.php', 'name' => 'Contact Form 7', 'author' => 'Takayuki Miyoshi', 'version' => '5.9.3', 'is_active' => false, 'has_update' => false, 'new_version' => null, 'auto_update' => false],
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

    $data = match ($endpoint) {
        'ping' => ['site_url' => 'http://127.0.0.1/', 'wp_version' => '6.8.2', 'time' => date('c')],
        'summary' => fake_summary(),
        'security' => ['security' => fake_summary()['security']],
        default => null,
    };

    if ($data === null) {
        fake_wp_error(404, 'rest_no_route', 'Neznámý endpoint.');
    }

    fake_json(200, ['ok' => true, 'plugin_version' => '1.0.0', 'generated_at' => date('c'), 'data' => $data]);
}

// REST přes ?rest_route= funguje vždy (i bez hezkých adres).
if (isset($query['rest_route']) && preg_match('#^/mediagrafik-monitor/v1/([a-z]+)$#', (string) $query['rest_route'], $m) === 1) {
    fake_plugin_route($m[1], $mode, $expectedKey);
}

if (preg_match('#^/wp-json/mediagrafik-monitor/v1/([a-z]+)$#', $path, $m) === 1) {
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

// Kořen webu — kontrola dostupnosti.
http_response_code((int) (getenv('FAKE_WP_STATUS') ?: 200));
header('Content-Type: text/html');
echo '<html><body>Kavárna Dobrá</body></html>';
