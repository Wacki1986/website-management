<?php

declare(strict_types=1);

/**
 * Spouštěč falešných serverů (php -S) pro testy — instance i cPanel.
 *
 * Sdílí ho víc testovacích souborů (require_once), harness běží v jednom
 * procesu. Serverů může běžet víc najednou (průvodce potřebuje falešný
 * cPanel i falešnou instanci) — drží se podle portu.
 */
final class FakeInstance
{
    /** @var array<int, resource> */
    private static array $processes = [];
    private static ?string $lastError = null;

    /**
     * @param array<string, string> $env FAKE_* proměnné pro fixture
     * @param string $script router ve fixtures/ (fake-instance.php, fake-cpanel.php)
     */
    public static function start(int $port, array $env = [], string $script = 'fake-instance.php'): bool
    {
        self::stopPort($port);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/' . $script],
            $descriptors,
            $pipes,
            null,
            $env + self::environment(),
        );

        if (!is_resource($process)) {
            self::$lastError = 'proc_open selhal';

            return false;
        }

        self::$processes[$port] = $process;

        // Čekání, až server začne poslouchat (antivir umí start protahovat).
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.25);

            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }

            usleep(100_000);
        }

        self::$lastError = 'server nezačal poslouchat';
        self::stopPort($port);

        return false;
    }

    /** Zastaví všechny běžící falešné servery. */
    public static function stop(): void
    {
        foreach (array_keys(self::$processes) as $port) {
            self::stopPort($port);
        }
    }

    public static function stopPort(int $port): void
    {
        $process = self::$processes[$port] ?? null;

        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }

        unset(self::$processes[$port]);
    }

    public static function lastError(): string
    {
        return self::$lastError ?? 'neznámý důvod';
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        // Windows potřebuje SystemRoot a spol., jinak PHP nenastartuje.
        $env = [];

        foreach (['SystemRoot', 'PATH', 'TEMP', 'TMP'] as $key) {
            $value = getenv($key);

            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
