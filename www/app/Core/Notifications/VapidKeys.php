<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Notifications\WebPush\Vapid;
use App\Core\Settings\Settings;
use Throwable;

/**
 * Pár klíčů, kterým se správa podepisuje push službě.
 *
 * Vyrobí se sám při prvním použití a uloží do `settings` — soukromá půlka
 * šifrovaně přes `setSecret()`, stejně jako `subscription_cron_token`.
 * Klientská aplikace je má v `config/env.php`, protože jí ten soubor píše
 * `Provisioner`; sem se ale push přidává do **běžící instalace** a stejné
 * řešení by znamenalo lézt přes FTP na produkční server.
 *
 * **Výměna páru zneplatní všechny odběry** — prohlížeč si veřejný klíč
 * pamatuje od chvíle, kdy se k odběru přihlásil, a jiným už zprávy nepřijme.
 * Proto tu není žádné „vygenerovat znovu": pár se vyrobí jednou a zůstává.
 * Ztráta `app_key` má stejný následek (soukromý klíč se nedešifruje) — je to
 * ale otázka minuty práce, ne katastrofa: každý si upozornění zapne znovu.
 */
final class VapidKeys
{
    public const PUBLIC_KEY = 'vapid_public_key';
    public const PRIVATE_KEY = 'vapid_private_key';
    public const SUBJECT = 'vapid_subject';

    public function __construct(
        private readonly Settings $settings,
        private readonly string $appUrl,
    ) {
    }

    /** Jsou klíče připravené? (Karta Stav v Nastavení → Oznámení.) */
    public function ready(): bool
    {
        return $this->settings->get(self::PUBLIC_KEY) !== '';
    }

    /**
     * Jde tím klíčem opravdu podepisovat?
     *
     * `ready()` kouká jen na veřejnou půlku — ta je v nastavení vidět vždycky.
     * Soukromá se ale může stát nečitelnou (vyměněný `app_key`) a push pak
     * mlčky nefunguje, přestože stránka hlásí „připraveno". Karta Stav se ptá
     * tímhle.
     */
    public function usable(): bool
    {
        return $this->vapid() !== null;
    }

    /** Veřejný klíč pro `pushManager.subscribe()`; prázdný = ještě není. */
    public function publicKey(): string
    {
        return $this->settings->get(self::PUBLIC_KEY);
    }

    /**
     * Vyrobí pár, pokud ještě není. Vrací true, když se opravdu vyráběl —
     * volající tak pozná „připraveno" od „už bylo".
     */
    public function ensure(): bool
    {
        if ($this->ready()) {
            return false;
        }

        $pair = Vapid::generateKeys();

        $this->settings->set(self::PUBLIC_KEY, $pair['public_key']);
        $this->settings->setSecret(self::PRIVATE_KEY, $pair['private_key']);
        $this->settings->set(self::SUBJECT, $this->subject());

        return true;
    }

    /**
     * Podepisovač pro `WebPush`, nebo null, když klíče nejsou (a nebo je
     * hosting bez `openssl` neumí vyrobit).
     */
    public function vapid(): ?Vapid
    {
        try {
            $private = $this->settings->secret(self::PRIVATE_KEY);
        } catch (Throwable) {
            // Nečitelné tajemství (vyměněný app_key) — push prostě nejede.
            return null;
        }

        if ($private === null) {
            return null;
        }

        // Uložený subject se bere jen tehdy, když něco obsahuje: `Settings::get()`
        // vrací výchozí hodnotu jen u chybějícího klíče, ne u prázdného. Prázdný
        // subject přitom `fromConfig()` odmítne — a push by mlčky přestal chodit.
        $subject = $this->settings->get(self::SUBJECT);

        // `fromConfig()` je převzatý z klientské aplikace beze změny, jen mu
        // místo sekce z env.php podáváme hodnoty z nastavení.
        return Vapid::fromConfig([
            'public_key' => $this->settings->get(self::PUBLIC_KEY),
            'private_key' => $private,
            'subject' => $subject !== '' ? $subject : $this->subject(),
        ]);
    }

    /**
     * Kontakt na provozovatele pro push službu — když jí něco vadí, píše sem.
     * Přednost má odesílací adresa z Nastavení → E-mail, jinak `noreply@`
     * na doméně správy.
     */
    private function subject(): string
    {
        $from = trim($this->settings->get('mail_from_address'));

        if ($from !== '') {
            return 'mailto:' . $from;
        }

        $host = (string) parse_url($this->appUrl, PHP_URL_HOST);

        return 'mailto:noreply@' . ($host !== '' ? $host : 'localhost');
    }
}
