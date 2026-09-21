<?php

declare(strict_types=1);

namespace App\Core\Notifications\WebPush;

use RuntimeException;

/**
 * Šifrování zprávy pro web push — RFC 8291 (`aes128gcm`, RFC 8188).
 *
 * Push server (Google, Apple, Mozilla) zprávu jen předává; číst ji smí až
 * prohlížeč. Proto se šifruje klíčem odvozeným z toho, co prohlížeč vydal
 * při přihlášení k odběru: veřejný klíč `p256dh` a tajemství `auth`.
 *
 * Postup je přesně ten z RFC, bez vlastních nápadů:
 *
 *  1. jednorázový pár klíčů serveru, ECDH s `p256dh` → sdílené tajemství,
 *  2. HKDF(auth, tajemství, "WebPush: info" + oba veřejné klíče) → IKM,
 *  3. náhodná sůl; HKDF(sůl, IKM) → klíč AES-128 a nonce,
 *  4. AES-128-GCM přes zprávu s oddělovačem 0x02 (jediný, poslední záznam),
 *  5. tělo = sůl (16) · velikost záznamu (4) · délka klíče (1) · klíč (65) · šifra.
 *
 * Veškerou kryptografii dělá `ext-openssl` a `hash_hkdf`; tady se skládají
 * bajty ve správném pořadí. Správnost hlídá test proti referenčnímu
 * vektoru z RFC 8291 §5 — proto jdou jednorázový klíč a sůl podstrčit.
 */
final class MessageEncryption
{
    /** Velikost záznamu. Zpráva je vždy jeden záznam a push servery chtějí ≤ 4 kB. */
    private const RECORD_SIZE = 4096;

    /** Kolik zbude na samotnou zprávu: záznam − oddělovač − značka GCM. */
    public const MAX_PAYLOAD = self::RECORD_SIZE - 1 - 16;

    /**
     * @param string $plaintext zpráva (typicky JSON)
     * @param string $p256dh    veřejný klíč prohlížeče, base64url
     * @param string $auth      tajemství prohlížeče, base64url (16 bajtů)
     * @param array{public: string, private: string}|null $serverKey jen pro test — jinak náhodný
     * @param string|null $salt jen pro test — jinak náhodných 16 bajtů
     *
     * @return string tělo požadavku (binární)
     */
    public static function encrypt(
        string $plaintext,
        string $p256dh,
        string $auth,
        ?array $serverKey = null,
        ?string $salt = null,
    ): string {
        if (strlen($plaintext) > self::MAX_PAYLOAD) {
            throw new RuntimeException('Zpráva pro push je příliš dlouhá.');
        }

        $clientPublic = Keys::base64UrlDecode($p256dh);
        $authSecret = Keys::base64UrlDecode($auth);

        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('Tajemství auth má mít 16 bajtů.');
        }

        $serverKey ??= Keys::generate();
        $salt ??= random_bytes(16);

        $sharedSecret = self::ecdh($serverKey, $clientPublic);

        $ikm = hash_hkdf(
            'sha256',
            $sharedSecret,
            32,
            "WebPush: info\x00" . $clientPublic . $serverKey['public'],
            $authSecret,
        );
        $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext . "\x02",
            'aes-128-gcm',
            $contentKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES-GCM selhalo: ' . (string) openssl_error_string());
        }

        return $salt
            . pack('N', self::RECORD_SIZE)
            . chr(strlen($serverKey['public']))
            . $serverKey['public']
            . $ciphertext
            . $tag;
    }

    /**
     * Sdílené tajemství ECDH (souřadnice x společného bodu).
     *
     * @param array{public: string, private: string} $serverKey
     */
    private static function ecdh(array $serverKey, string $clientPublic): string
    {
        $private = openssl_pkey_get_private(Keys::privatePem($serverKey['private'], $serverKey['public']));
        $public = openssl_pkey_get_public(Keys::publicPem($clientPublic));

        if ($private === false || $public === false) {
            throw new RuntimeException('OpenSSL nepřijal klíče pro ECDH: ' . (string) openssl_error_string());
        }

        $secret = openssl_pkey_derive($public, $private);

        if ($secret === false || strlen($secret) !== 32) {
            throw new RuntimeException('ECDH selhalo: ' . (string) openssl_error_string());
        }

        return $secret;
    }
}
