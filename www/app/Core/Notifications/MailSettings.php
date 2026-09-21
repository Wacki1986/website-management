<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Settings\Settings;

/**
 * Nastavení odchozí pošty — v `settings`, ne v `env.php`.
 *
 * Stejný důvod jako u `cpanel_token` (`Secrets.php`): heslo k SMTP je
 * dokumentovaná výjimka z pravidla „tajemství jen v env.php" — provozovatel
 * si poštu nastaví v aplikaci, aniž by musel na FTP. Ukládá se přes
 * `Settings::setSecret()`, stejný mechanismus jako u tokenu cPanelu.
 *
 * Na rozdíl od klientské aplikace (jeden klíč `mail_config` s JSON polem)
 * správa používá ploché klíče (`mail_transport`, `mail_host`, …) — je to
 * idiom, který tu už `settings` tabulka má (`cpanel_url`, `cpanel_user`…).
 */
final class MailSettings
{
    public const TRANSPORT_NONE = 'none';
    public const TRANSPORT_MAIL = 'mail';
    public const TRANSPORT_SMTP = 'smtp';
    public const TRANSPORT_LOG = 'log';

    public const TRANSPORTS = [
        self::TRANSPORT_NONE => 'Neodesílat e-maily',
        self::TRANSPORT_MAIL => 'Funkce mail() hostingu',
        self::TRANSPORT_SMTP => 'SMTP server',
        self::TRANSPORT_LOG => 'Jen uložit do souboru (vývoj)',
    ];

    public const SECURITIES = [
        'tls' => 'STARTTLS (obvykle port 587)',
        'ssl' => 'SSL/TLS od začátku (obvykle port 465)',
        'none' => 'Nešifrovaně',
    ];

    public function __construct(
        private readonly Settings $settings,
        /** @var array<string, mixed> záložní hodnoty z env.php, dokud nikdo nic v aplikaci nenastavil */
        private readonly array $fallback = [],
    ) {
    }

    /**
     * Platné nastavení. Heslo se vrací **rozšifrované** — tohle je jediné
     * místo, které ho má komu předat; do šablony jde jen `secretHint()`.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        if (!$this->isConfigured()) {
            return $this->fromFallback();
        }

        $security = $this->settings->get('mail_security', 'tls');

        return [
            'transport' => $this->transport($this->settings->get('mail_transport')),
            'from_address' => $this->settings->get('mail_from_address'),
            'from_name' => $this->settings->get('mail_from_name'),
            'host' => $this->settings->get('mail_host'),
            'port' => (int) $this->settings->get('mail_port', '587'),
            'security' => isset(self::SECURITIES[$security]) ? $security : 'tls',
            'username' => $this->settings->get('mail_username'),
            'password' => $this->settings->secret('mail_password') ?? '',
        ];
    }

    /**
     * Hodnoty pro formulář — **bez hesla** (prohlížeč by ho jinak nabízel
     * k autofillu a posílal zpátky při každém uložení jiného pole).
     *
     * @return array<string, mixed>
     */
    public function forForm(): array
    {
        $current = $this->current();
        unset($current['password']);
        $current['password_hint'] = $this->settings->secretHint('mail_password');

        return $current;
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('mail_transport') !== '';
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $this->settings->set('mail_transport', $this->transport((string) ($input['transport'] ?? '')));
        $this->settings->set('mail_from_address', mb_substr(trim((string) ($input['from_address'] ?? '')), 0, 190));
        $this->settings->set('mail_from_name', mb_substr(trim((string) ($input['from_name'] ?? '')), 0, 120));
        $this->settings->set('mail_host', mb_substr(trim((string) ($input['host'] ?? '')), 0, 190));
        $this->settings->set('mail_port', (string) max(1, min(65535, (int) ($input['port'] ?? 587))));

        $security = (string) ($input['security'] ?? '');
        $this->settings->set('mail_security', isset(self::SECURITIES[$security]) ? $security : 'tls');
        $this->settings->set('mail_username', mb_substr(trim((string) ($input['username'] ?? '')), 0, 190));

        // Prázdné heslo znamená „nech to staré" — formulář ho nevypisuje,
        // takže by jinak vymazalo každé uložení jiného pole.
        $password = trim((string) ($input['password'] ?? ''));

        if ($password !== '') {
            $this->settings->setSecret('mail_password', $password);
        }
    }

    /**
     * Co brání odesílání. Prázdné pole = je to v pořádku.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $config = $this->current();
        $problems = [];

        if ($config['transport'] === self::TRANSPORT_NONE) {
            return $problems;
        }

        if (trim((string) $config['from_address']) === '') {
            $problems[] = 'Není vyplněná adresa odesílatele — e-maily bez ní většina serverů odmítne.';
        }

        if ($config['transport'] === self::TRANSPORT_SMTP && trim((string) $config['host']) === '') {
            $problems[] = 'Není vyplněný SMTP server.';
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private function fromFallback(): array
    {
        return [
            'transport' => $this->transport((string) ($this->fallback['transport'] ?? self::TRANSPORT_NONE)),
            'from_address' => (string) ($this->fallback['from_address'] ?? ''),
            'from_name' => (string) ($this->fallback['from_name'] ?? ''),
            'host' => '',
            'port' => 587,
            'security' => 'tls',
            'username' => '',
            'password' => '',
        ];
    }

    private function transport(string $value): string
    {
        return isset(self::TRANSPORTS[$value]) ? $value : self::TRANSPORT_NONE;
    }
}
