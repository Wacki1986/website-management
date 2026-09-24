<?php

declare(strict_types=1);

/**
 * Kernel s čistou testovací databází a přihlášeným uživatelem — pro testy,
 * které procházejí celý požadavek (routa → controller → šablona).
 *
 * Přihlášení je „nasucho" přes session: `Auth::login()` by chtělo heslo
 * a rate limiter, o které tu nejde.
 */

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Kernel;
use App\Core\View\Urls;

const TEST_KERNEL_APP_KEY = 'ee112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

/**
 * @param array<string, mixed> $env doplňky konfigurace (např. `allow_insecure_sites`)
 * @return array{0: Kernel, 1: string} kernel a CSRF token
 */
function loggedInKernel(string $userName = 'Technik', array $env = []): array
{
    freshTestDb();

    $kernel = new Kernel($env + [
        'environment' => 'development',
        'timezone' => 'Europe/Prague',
        'app_key' => TEST_KERNEL_APP_KEY,
        'app_url' => 'https://sprava.test',
        'database' => [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'name' => getenv('DB_NAME') ?: 'sprava_webu_test',
            'user' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ],
        'storage_path' => sys_get_temp_dir() . '/sprava-test-kernel-' . getmypid(),
    ], WWW_ROOT);

    Urls::bind($kernel->url(...), $kernel->asset(...));

    $now = date('Y-m-d H:i:s');
    $userId = $kernel->db()->insert('users', [
        'username' => mb_strtolower($userName),
        'email' => mb_strtolower($userName) . '@test.cz',
        'name' => $userName,
        'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'is_active' => 1,
        'theme' => 'auto',
        // Dvoufázové přihlášení je povinné — bez spárovaného telefonu by
        // Kernel každý požadavek poslal na párování.
        'totp_secret' => $kernel->secrets()->encrypt(App\Core\Auth\Totp::generateSecret()),
        'totp_enabled_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $user = $kernel->users()->find($userId);

    // Kernel spouští session až při prvním požadavku a `session_start()`
    // by $_SESSION přepsala prázdnou relací — přihlášení by zmizelo.
    // Dřív to procházelo jen proto, že session už spustil jiný test.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION = [
        'user_id' => $userId,
        'auth_hash' => hash('sha256', $user['id'] . '|' . $user['password_hash']),
        'last_activity' => time(),
    ];

    return [$kernel, $kernel->csrf()->token()];
}

/**
 * @param array<string, mixed> $body
 * @param array<string, mixed> $query
 */
function kernelRequest(Kernel $kernel, string $method, string $path, array $body = [], array $query = []): Response
{
    return $kernel->handle(new Request(
        method: $method,
        path: $path,
        query: $query,
        body: $body,
        files: [],
        cookies: [],
        headers: ['accept' => 'text/html'],
        server: ['SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'],
    ));
}
