<?php

declare(strict_types=1);

namespace App\Core\Log;

/**
 * Souborový log s měsíční rotací.
 *
 * Do logu nikdy nesmí jít hesla, tokeny ani celé SQL s daty — to byla jedna
 * z děr staré aplikace (db-errors.log obsahoval kompletní dotazy).
 */
final class Logger
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            str_replace(["\n", "\r"], ' ', $message),
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE),
        );

        @file_put_contents(
            $this->directory . '/app-' . date('Y-m') . '.log',
            $line,
            FILE_APPEND | LOCK_EX,
        );
    }
}
