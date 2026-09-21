<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Log\Logger;
use RuntimeException;
use Throwable;

/**
 * Odesílání e-mailů.
 *
 * Nastavení bere z `MailSettings` (Nastavení → E-mail), ne z configu — viz
 * tamní zdůvodnění. Transporty:
 *
 *  - `none` — neodesílá se nic (výchozí, dokud nikdo poštu nenastaví).
 *  - `mail` — funkce `mail()` hostingu. Nejjednodušší, ale nejméně spolehlivé.
 *  - `smtp` — vlastní klient (`SmtpTransport`); jediný, u kterého se pozná
 *    **proč** odeslání selhalo.
 *  - `log` — zpráva se uloží do `storage/logs` jako `.eml`. Pro vývoj (a testy
 *    — na tomhle stroji antivir náhodně zabíjí PHP procesy, síťové SMTP
 *    v testech by bylo nespolehlivé, `log` transport síť neotvírá vůbec).
 *
 * Správa nemá frontu ani cron: objem je nízký (reset hesla, zkušební e-mail),
 * takže `send()` posílá synchronně a vrací bool — jedno neodeslané oznámení
 * nesmí shodit akci, která ho vyvolala. Kdo chce vědět proč, volá `lastError()`.
 */
final class Mailer
{
    private ?string $lastError = null;

    /** @var (callable(array<string, mixed>): SmtpTransport)|null */
    private $smtpFactory;

    /**
     * @param string $logoUrl absolutní adresa loga do hlavičky e-mailů
     *        (Nastavení → E-mail); prázdná = místo obrázku název odesílatele
     * @param (callable(array<string, mixed>): SmtpTransport)|null $smtpFactory kvůli testům
     */
    public function __construct(
        private readonly MailSettings $settings,
        private readonly Logger $logger,
        private readonly string $logDirectory,
        private readonly string $logoUrl = '',
        ?callable $smtpFactory = null,
    ) {
        $this->smtpFactory = $smtpFactory;
    }

    /**
     * Prostý text — pro zprávy, které přicházejí jako řetězec. HTML podoba
     * se z textu odvodí: prázdný řádek dělí odstavce, řádek
     * „Popisek: https://…" (nebo holá adresa) je tlačítko. Nové e-maily se
     * skládají přes `sendMessage()`.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        return $this->sendMessage($to, $subject, self::fromPlainText($subject, $body), $body);
    }

    /**
     * Strukturovaný e-mail (`EmailMessage`) — z jedné stavebnice se vykreslí
     * HTML karta podle návrhu i textová varianta, takže se nemůžou rozejít.
     *
     * @param string|null $textBody vlastní textová varianta; null = odvodí se
     *        ze stavebnice (`EmailMessage::toText()`)
     */
    public function sendMessage(string $to, string $subject, EmailMessage $message, ?string $textBody = null): bool
    {
        $config = $this->settings->current();
        $fromName = (string) ($config['from_name'] !== '' ? $config['from_name'] : 'Správa instancí');

        return $this->deliver($to, $subject, $textBody ?? $message->toText(), $message->toHtml($fromName, $this->logoUrl));
    }

    /**
     * Hotové HTML (klientský report) — jediný e-mail, který se neskládá ze
     * stavebnice: má vlastní rozvržení podle návrhu `nahled-reportu.html`.
     */
    public function sendHtml(string $to, string $subject, string $html, string $text): bool
    {
        return $this->deliver($to, $subject, $text, $html);
    }

    private function deliver(string $to, string $subject, string $text, string $html): bool
    {
        $this->lastError = null;
        $config = $this->settings->current();
        $transport = (string) $config['transport'];

        if ($transport === MailSettings::TRANSPORT_NONE) {
            // Není to chyba, je to (zatím) nenastavená pošta — proto jen záznam do logu.
            $this->logger->info('E-maily jsou vypnuté, zpráva se neodeslala', [
                'to' => $this->maskEmail($to),
                'subject' => $subject,
            ]);
            $this->lastError = 'Odesílání e-mailů není v Nastavení → E-mail zapnuté.';

            return false;
        }

        $fromAddress = (string) ($config['from_address'] !== '' ? $config['from_address'] : 'no-reply@localhost');
        $fromName = (string) ($config['from_name'] !== '' ? $config['from_name'] : 'Správa instancí');

        // Obálka nese obě varianty (`multipart/alternative`) — poštovní klient
        // si vybere, kterou umí.
        $boundary = 'np_' . bin2hex(random_bytes(16));

        $headers = [
            'From: ' . $this->encodeName($fromName) . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $payload = $this->buildMultipart($boundary, $text, $html);

        try {
            return match ($transport) {
                MailSettings::TRANSPORT_LOG => $this->writeToLog($to, $subject, $payload, $headers),
                MailSettings::TRANSPORT_SMTP => $this->sendOverSmtp($config, $fromAddress, $to, $subject, $payload, $headers),
                default => $this->sendWithMailFunction($to, $subject, $payload, $headers),
            };
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error('Odeslání e-mailu selhalo', [
                'to' => $this->maskEmail($to),
                'subject' => $subject,
                'transport' => $transport,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Proč naposledy neodešlo. Null = poslední odeslání prošlo. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $headers
     */
    private function sendOverSmtp(
        array $config,
        string $from,
        string $to,
        string $subject,
        string $body,
        array $headers,
    ): bool {
        $transport = $this->smtpFactory !== null
            ? ($this->smtpFactory)($config)
            : new SmtpTransport([
                'host' => (string) $config['host'],
                'port' => (int) $config['port'],
                'security' => (string) $config['security'],
                'username' => (string) $config['username'],
                'password' => (string) $config['password'],
            ]);

        // Hlavičky obálky (To/Subject) posílá při SMTP klient sám — u mail()
        // je naopak dodává PHP. Proto se skládají tady, ne v transportu.
        $transport->send($from, $to, $subject, $body, array_merge([
            'To: ' . $to,
            'Subject: ' . $this->encodeSubject($subject),
            'Date: ' . date('r'),
        ], $headers));

        return true;
    }

    /** @param array<int, string> $headers */
    private function sendWithMailFunction(string $to, string $subject, string $body, array $headers): bool
    {
        $sent = @mail($to, $this->encodeSubject($subject), $body, implode("\r\n", $headers));

        if (!$sent) {
            // mail() vrací jen false — důvod se nedozvíme. Odsud plyne
            // doporučení používat na produkci SMTP.
            throw new RuntimeException(
                'Funkce mail() odeslání odmítla. Hosting obvykle neřekne proč — '
                . 'spolehlivější je nastavit SMTP.',
            );
        }

        return true;
    }

    /** @param array<int, string> $headers */
    private function writeToLog(string $to, string $subject, string $body, array $headers): bool
    {
        if (!is_dir($this->logDirectory) && !@mkdir($this->logDirectory, 0775, true)) {
            throw new RuntimeException('Nejde vytvořit adresář pro logy.');
        }

        $file = sprintf('%s/mail-%s-%s.eml', $this->logDirectory, date('Ymd-His'), bin2hex(random_bytes(3)));
        $content = "To: {$to}\r\nSubject: {$subject}\r\n" . implode("\r\n", $headers) . "\r\n\r\n{$body}";

        if (@file_put_contents($file, $content) === false) {
            throw new RuntimeException('Zprávu se nepodařilo uložit do souboru.');
        }

        return true;
    }

    /** Sestaví tělo `multipart/alternative` z hotových variant. */
    private function buildMultipart(string $boundary, string $text, string $html): string
    {
        return "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n\r\n"
            . "--{$boundary}--";
    }

    /**
     * Prostý text → stavebnice. Nadpisem je předmět, prázdný řádek dělí
     * odstavce, řádek „Popisek: https://…" (nebo holá adresa) je tlačítko.
     */
    private static function fromPlainText(string $subject, string $body): EmailMessage
    {
        $message = EmailMessage::make($subject);
        $buffer = [];

        $flush = static function () use (&$buffer, $message): void {
            if ($buffer !== []) {
                $message->paragraph(implode("\n", $buffer));
                $buffer = [];
            }
        };

        foreach (preg_split('/\r\n|\r|\n/', trim($body)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                $flush();

                continue;
            }

            if (preg_match('#^(?:([^:]{2,60}):\s*)?(https?://\S+)$#u', $line, $m) === 1) {
                $flush();
                $message->button(trim($m[1]) !== '' ? trim($m[1]) : 'Otevřít v aplikaci', $m[2]);

                continue;
            }

            $buffer[] = $line;
        }

        $flush();

        return $message;
    }

    private function encodeSubject(string $subject): string
    {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    private function encodeName(string $name): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $name) === 1
            ? '"' . str_replace('"', '', $name) . '"'
            : '=?UTF-8?B?' . base64_encode($name) . '?=';
    }

    /** Do logu nikdy nepatří celá adresa. */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        return $at === false ? '***' : mb_substr($email, 0, 2) . '***' . mb_substr($email, $at);
    }
}
