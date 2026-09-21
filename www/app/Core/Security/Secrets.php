<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Http\HttpException;
use SensitiveParameter;
use Throwable;

/**
 * Šifrování tajemství, která musí bydlet v databázi.
 *
 * Pravidlo z architektury §11 zní „tajemství jen v `env.php`" a platí dál —
 * jenže heslo k SMTP je výjimka, kterou si vynutil provoz: kancelář si má
 * poštu nastavit sama a lézt kvůli tomu na FTP je přesně to, čemu se aplikace
 * vyhýbá.
 *
 * Kompromis: **hodnota je v databázi, klíč v souboru.** Záloha databáze — která
 * se posílá mailem, kopíruje na disk a leží po adresářích — tím pádem heslo
 * neobsahuje v čitelné podobě. Kdo má obojí, má stejně všechno; ale to je jiná
 * úroveň průšvihu než dump s hesly.
 *
 * Používá se `sodium_crypto_secretbox` (XSalsa20-Poly1305) — je součástí PHP od
 * 7.2, takže nepřibývá závislost, a je autentizované: podvržená hodnota se
 * pozná, místo aby se rozšifrovala na nesmysl.
 */
final class Secrets
{
    /** Předpona uložené hodnoty — podle ní se pozná, co je šifrované. */
    private const PREFIX = 'enc:v1:';

    private ?string $key = null;

    public function __construct(#[SensitiveParameter] private readonly string $appKey)
    {
    }

    /**
     * Je čím šifrovat?
     *
     * Bez klíče se tajemství **neuloží vůbec** — uložit ho v čitelné podobě
     * „zatím" je ta nejhorší varianta: fungovalo by to a nikdo by se k tomu
     * nevrátil.
     */
    public function isReady(): bool
    {
        return $this->key() !== null;
    }

    public function encrypt(#[SensitiveParameter] string $value): string
    {
        $key = $this->key();

        if ($key === null) {
            throw new HttpException(
                500,
                'Chybí app_key v config/env.php — bez něj se heslo nedá bezpečně uložit. '
                . 'Vygenerujte ho příkazem php dev/tools/generate-tokens.php.',
            );
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $key));
    }

    /**
     * Rozšifruje hodnotu. Vrací null, když to nejde.
     *
     * Nejde = špatný nebo vyměněný klíč, poškozená hodnota, nebo hodnota, která
     * šifrovaná nikdy nebyla. Výjimka by tu byla na škodu: nefunkční pošta
     * nesmí shodit stránku, na které se nastavuje.
     */
    public function decrypt(string $value): ?string
    {
        $key = $this->key();

        if ($key === null || !self::isEncrypted($value)) {
            return null;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        try {
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key,
            );
        } catch (Throwable) {
            return null;
        }

        return $plain === false ? null : $plain;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Klíč odvozený z `app_key`.
     *
     * Z konfigurace přichází hexa řetězec; secretbox chce přesně 32 bajtů.
     * Kratší klíč se odmítne, ne dopadne nulami — mlčky oslabené šifrování je
     * horší než hlášená chyba.
     */
    private function key(): ?string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $raw = trim($this->appKey);

        if ($raw === '') {
            return null;
        }

        $binary = preg_match('/^[0-9a-f]+$/i', $raw) === 1 ? (string) hex2bin($raw) : $raw;

        return $this->key = strlen($binary) >= SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ? substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
            : null;
    }
}
