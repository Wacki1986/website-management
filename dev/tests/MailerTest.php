<?php

declare(strict_types=1);

/**
 * Odesílání e-mailů — proti testovací databázi (nastavení) a souborovému
 * systému (`log` transport). Skutečný SMTP server test nepotřebuje: SMTP se
 * SmtpTransport spojuje přes fsockopen a bez vyplněného hostu selže hned na
 * prvním kroku — přesně to jde otestovat i bez sítě.
 */

use App\Core\Log\Logger;
use App\Core\Notifications\Mailer;
use App\Core\Notifications\MailSettings;
use App\Core\Security\Secrets;
use App\Core\Settings\Settings;

const MAILER_APP_KEY = 'dd112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

/** @return array{0: Mailer, 1: MailSettings, 2: string} */
function makeMailer(): array
{
    $db = freshTestDb();
    $mailSettings = new MailSettings(new Settings($db, new Secrets(MAILER_APP_KEY)));

    $logDir = sys_get_temp_dir() . '/sprava-mail-' . getmypid();
    @mkdir($logDir, 0775, true);
    foreach (glob($logDir . '/*.eml') ?: [] as $old) {
        @unlink($old);
    }

    return [new Mailer($mailSettings, new Logger($logDir), $logDir), $mailSettings, $logDir];
}

return [
    'bez nastavení (transport none) se neodešle nic a je vidět proč' => function (): void {
        [$mailer] = makeMailer();

        $sent = $mailer->send('klient@example.cz', 'Předmět', 'Tělo zprávy');

        assertFalse($sent, 'Bez nastavení nemá co odeslat');
        assertTrue($mailer->lastError() !== null, 'lastError() musí říct proč');
    },

    'transport log zapíše čitelný .eml se zprávou a odkazem' => function (): void {
        [$mailer, $mailSettings, $logDir] = makeMailer();

        $mailSettings->save([
            'transport' => MailSettings::TRANSPORT_LOG,
            'from_address' => 'no-reply@sprava.example.cz',
            'from_name' => 'Správa webů',
        ]);

        $sent = $mailer->send(
            'klient@example.cz',
            'Obnova hesla',
            'Odkaz: https://sprava.example.cz/obnova-hesla/abc123',
        );

        assertTrue($sent, (string) $mailer->lastError());

        $files = glob($logDir . '/*.eml') ?: [];
        assertSame(1, count($files), 'Nevznikl přesně jeden .eml soubor');

        $content = (string) file_get_contents($files[0]);
        assertContainsString('To: klient@example.cz', $content);
        assertContainsString('https://sprava.example.cz/obnova-hesla/abc123', $content);
    },

    'prázdný SMTP server selže srozumitelně, ne pádem' => function (): void {
        [$mailer, $mailSettings] = makeMailer();

        $mailSettings->save([
            'transport' => MailSettings::TRANSPORT_SMTP,
            'from_address' => 'no-reply@sprava.example.cz',
            'host' => '',
        ]);

        $sent = $mailer->send('klient@example.cz', 'Test', 'Tělo');

        assertFalse($sent);
        assertContainsString('SMTP server', (string) $mailer->lastError());
    },

    'heslo k SMTP se ukládá a čte zašifrovaně' => function (): void {
        [, $mailSettings] = makeMailer();

        $mailSettings->save([
            'transport' => MailSettings::TRANSPORT_SMTP,
            'host' => 'smtp.example.cz',
            'username' => 'ucet',
            'password' => 'tajne-heslo-987',
        ]);

        assertSame('tajne-heslo-987', (string) $mailSettings->current()['password']);
        assertContainsString('987', $mailSettings->forForm()['password_hint']);

        // Prázdné heslo při dalším uložení znamená „nech beze změny".
        $mailSettings->save(['transport' => MailSettings::TRANSPORT_SMTP, 'host' => 'smtp.example.cz']);
        assertSame('tajne-heslo-987', (string) $mailSettings->current()['password']);
    },
];
