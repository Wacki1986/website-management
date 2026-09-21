<?php

declare(strict_types=1);

/**
 * Minimální testovací harness bez závislostí — převzatý z klientské aplikace.
 *
 * Testovací soubor vrací pole `['název testu' => callable]`, spouští je
 * dev/tests/run.php. Testy, které potřebují databázi, se bez ní přeskočí.
 */

use App\Core\Autoloader;
use App\Core\Db\Connection;

define('PROJECT_ROOT', dirname(__DIR__, 2));
define('WWW_ROOT', PROJECT_ROOT . '/www');

require WWW_ROOT . '/app/Core/Autoloader.php';

Autoloader::addNamespace('App', WWW_ROOT . '/app');
Autoloader::register();

// Globální funkce pro šablony — stejný seznam jako v app/bootstrap.php.
foreach (['markup', 'url', 'forms', 'time'] as $helpers) {
    $file = WWW_ROOT . '/app/helpers/' . $helpers . '.php';

    if (is_file($file)) {
        require_once $file;
    }
}

// V testech je každé varování, notice i deprecated hláška chyba.
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Stejná časová zóna jako v aplikaci (Kernel::boot).
date_default_timezone_set('Europe/Prague');

// Session pro testy, které ji potřebují (CSRF, přihlášení) — musí se spustit
// před prvním výstupem.
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_save_path() === '') {
        session_save_path(sys_get_temp_dir());
    }

    @session_start();
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class SkippedTest extends RuntimeException
{
}

function skip(string $reason): never
{
    throw new SkippedTest($reason);
}

function assertTrue(bool $condition, string $message = 'Očekáváno true'): void
{
    if (!$condition) {
        throw new AssertionError($message);
    }
}

function assertFalse(bool $condition, string $message = 'Očekáváno false'): void
{
    assertTrue(!$condition, $message);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(sprintf(
            "%s\n    očekáváno: %s\n    skutečnost: %s",
            $message !== '' ? $message : 'Hodnoty se neshodují',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertContainsString(string $needle, string $haystack, string $message = ''): void
{
    assertTrue(
        str_contains($haystack, $needle),
        $message !== '' ? $message : sprintf('Řetězec neobsahuje "%s"', $needle),
    );
}

/** @param class-string<Throwable> $expected */
function assertThrows(string $expected, callable $callback, string $message = ''): Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        if (!$e instanceof $expected) {
            throw new AssertionError(sprintf(
                'Očekávána výjimka %s, přišla %s (%s)',
                $expected,
                $e::class,
                $e->getMessage(),
            ));
        }

        return $e;
    }

    throw new AssertionError($message !== '' ? $message : sprintf('Výjimka %s nepřišla', $expected));
}

/**
 * Připojení k testovací databázi, nebo přeskočení testu.
 * Konfigurace přes proměnné prostředí (stejné, jaké nastaví CI).
 */
function testDb(): Connection
{
    static $connection = null;
    static $unavailable = null;

    if ($connection instanceof Connection) {
        return $connection;
    }

    if (is_string($unavailable)) {
        skip($unavailable);
    }

    $config = [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'sprava_webu_test',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ];

    if (!extension_loaded('pdo_mysql')) {
        $unavailable = 'rozšíření pdo_mysql není k dispozici';
        skip($unavailable);
    }

    $candidate = new Connection($config + ['timeout' => 3]);

    if (!$candidate->isAvailable()) {
        $unavailable = sprintf('testovací databáze není dostupná (%s@%s)', $config['name'], $config['host']);
        skip($unavailable);
    }

    return $connection = $candidate;
}

/**
 * Čistá databáze se schématem aplikace.
 *
 * Schéma se zahazuje jen jednou za běh, dál se tabulky vyprazdňují (DELETE,
 * ne TRUNCATE — viz zdůvodnění v klientské aplikaci). Evidence migrací se
 * maže taky, takže se při každém testu ověří, že migrace jsou idempotentní.
 */
function freshTestDb(): Connection
{
    static $schemaReady = false;

    $db = testDb();

    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($db->select('SHOW TABLES') as $row) {
        $table = $db->quoteIdentifier((string) array_values($row)[0]);

        $db->pdo()->exec($schemaReady ? 'DELETE FROM ' . $table : 'DROP TABLE IF EXISTS ' . $table);
    }

    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    $schemaReady = true;

    $migrator = new App\Core\Db\Migrator($db);
    $migrator->addPath('core', WWW_ROOT . '/database/migrations');

    $report = $migrator->run();

    if ($report['failed'] !== null) {
        throw new AssertionError('Migrace v testu selhala: ' . $report['message']);
    }

    return $db;
}
