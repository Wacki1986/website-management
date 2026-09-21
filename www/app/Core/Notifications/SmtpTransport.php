<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use RuntimeException;
use SensitiveParameter;

/**
 * Odeslání e-mailu přes SMTP.
 *
 * Vlastní klient místo knihovny: protokol je pro tenhle účel malý (EHLO,
 * STARTTLS, AUTH, MAIL FROM, RCPT TO, DATA) a jediná alternativa — přibalit
 * PHPMailer — by znamenala závislost a Composer na hostingu kvůli dvěma stům
 * řádkům. Převzato beze změny z klientské aplikace
 * (`aplikace/www/app/Core/Notifications/SmtpTransport.php`) — stejný
 * problém, stejné řešení.
 *
 * **Spojení otevírá injektovaný uzávěr, ne `fsockopen` natvrdo.** Díky tomu se
 * dá celý rozhovor se serverem odehrát v testu proti scénáři a nemusí k tomu
 * být mailserver.
 */
final class SmtpTransport
{
    /** Konec řádku je v SMTP závazně CRLF, ne PHP_EOL. */
    private const CRLF = "\r\n";

    /** @var callable(string, int, int): mixed */
    private $connector;

    /**
     * @param array{host?: string, port?: int, security?: string, username?: string, password?: string, timeout?: int} $config
     * @param (callable(string, int, int): mixed)|null $connector otevře spojení; null = fsockopen
     */
    public function __construct(
        #[SensitiveParameter] private readonly array $config,
        ?callable $connector = null,
    ) {
        $this->connector = $connector ?? static function (string $address, int $port, int $timeout) {
            $stream = @fsockopen($address, $port, $code, $message, $timeout);

            if ($stream === false) {
                throw new RuntimeException(sprintf('Spojení se serverem selhalo: %s (%d).', $message, $code));
            }

            return $stream;
        };
    }

    /**
     * @param array<int, string> $headers hotové hlavičky zprávy
     * @throws RuntimeException s hláškou, která smí jít uživateli na obrazovku
     */
    public function send(string $from, string $to, string $subject, string $body, array $headers): void
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 587);
        $security = (string) ($this->config['security'] ?? 'tls');
        $timeout = (int) ($this->config['timeout'] ?? 10);

        if ($host === '') {
            throw new RuntimeException('Není vyplněný SMTP server.');
        }

        // `ssl://` = šifrováno od prvního bajtu (port 465). `tls` = otevře se
        // nešifrovaně a přepne příkazem STARTTLS (port 587) — jsou to dvě různé
        // věci, které se pravidelně pletou.
        $address = ($security === 'ssl' ? 'ssl://' : '') . $host;
        $stream = ($this->connector)($address, $port, $timeout);

        try {
            $this->expect($stream, 220);

            $ehlo = $this->command($stream, 'EHLO ' . $this->clientName(), 250);

            if ($security === 'tls') {
                $this->command($stream, 'STARTTLS', 220);
                $this->enableCrypto($stream);
                // Po přepnutí do šifrování se EHLO opakuje — server po
                // STARTTLS zapomíná, co ohlásil předtím (a musí, kvůli
                // podvržení nabídky v nešifrované fázi).
                $ehlo = $this->command($stream, 'EHLO ' . $this->clientName(), 250);
            }

            $this->authenticate($stream, $ehlo);

            $this->command($stream, 'MAIL FROM:<' . $from . '>', 250);
            $this->command($stream, 'RCPT TO:<' . $to . '>', 250, 251);
            $this->command($stream, 'DATA', 354);

            $this->write($stream, implode(self::CRLF, $headers) . self::CRLF . self::CRLF
                . $this->escapeBody($body) . self::CRLF . '.');
            $this->expect($stream, 250);

            $this->command($stream, 'QUIT', 221);
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
    }

    /**
     * Přihlášení, pokud jsou vyplněné údaje.
     *
     * Přednost má AUTH PLAIN (jeden krok); LOGIN je záloha pro servery, které
     * PLAIN neumí. Když server neohlásí ani jedno, přihlášení se přeskočí —
     * některé vnitrofiremní relaye ho nevyžadují.
     */
    private function authenticate(mixed $stream, string $ehlo): void
    {
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username === '') {
            return;
        }

        $mechanisms = strtoupper($ehlo);

        if (str_contains($mechanisms, 'AUTH') && str_contains($mechanisms, 'PLAIN')) {
            $this->command(
                $stream,
                'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password),
                235,
            );

            return;
        }

        if (str_contains($mechanisms, 'AUTH') && str_contains($mechanisms, 'LOGIN')) {
            $this->command($stream, 'AUTH LOGIN', 334);
            $this->command($stream, base64_encode($username), 334);
            $this->command($stream, base64_encode($password), 235);
        }
    }

    private function enableCrypto(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }

        $enabled = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($enabled !== true) {
            throw new RuntimeException('Nepodařilo se přepnout spojení do šifrovaného režimu (STARTTLS).');
        }
    }

    /** Pošle příkaz a ověří návratový kód. Vrací celou odpověď serveru. */
    private function command(mixed $stream, string $command, int ...$expected): string
    {
        $this->write($stream, $command);

        return $this->expect($stream, ...$expected);
    }

    private function write(mixed $stream, string $line): void
    {
        if (@fwrite($stream, $line . self::CRLF) === false) {
            throw new RuntimeException('Spojení se serverem se přerušilo.');
        }
    }

    /**
     * Přečte odpověď (i víceřádkovou) a zkontroluje kód.
     *
     * Víceřádková odpověď má na pokračovacích řádcích pomlčku hned za kódem
     * (`250-STARTTLS`), poslední řádek mezeru (`250 OK`). Kdo čte jen první
     * řádek, přijde o seznam podporovaných rozšíření — a tím o informaci,
     * jestli se dá použít STARTTLS a jaké přihlášení server umí.
     */
    private function expect(mixed $stream, int ...$expected): string
    {
        $response = '';

        while (true) {
            $line = @fgets($stream, 1024);

            if ($line === false) {
                throw new RuntimeException('Server neodpověděl (vypršel čas nebo spojení spadlo).');
            }

            $response .= $line;

            // Poslední řádek pozná se podle mezery na čtvrté pozici.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException(sprintf(
                'Server odpověděl „%s" (čekalo se %s).',
                trim($response),
                implode(' nebo ', $expected),
            ));
        }

        return $response;
    }

    /**
     * Tečka na začátku řádku se v SMTP zdvojuje.
     *
     * Osamocená tečka ukončuje zprávu, takže řádek začínající tečkou by ji
     * uřízl v půlce. Klasická chyba, která se projeví až u konkrétního textu.
     */
    private function escapeBody(string $body): string
    {
        $normalized = preg_replace('/\r\n|\r|\n/', self::CRLF, $body) ?? $body;

        return preg_replace('/^\./m', '..', $normalized) ?? $normalized;
    }

    /** Jméno, kterým se klient představí. Adresa serveru je rozumnější než „localhost". */
    private function clientName(): string
    {
        $host = trim((string) ($this->config['host'] ?? ''));

        return $host !== '' ? $host : 'localhost';
    }
}
