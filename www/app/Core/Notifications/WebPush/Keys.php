<?php

declare(strict_types=1);

namespace App\Core\Notifications\WebPush;

use RuntimeException;

/**
 * Klíče P-256 pro web push — převody mezi „surovou" podobou z protokolu
 * a PEM, kterému rozumí `ext-openssl`.
 *
 * Protokol (RFC 8291/8292) posílá klíče jako base64url bez paddingu:
 * veřejný je nekomprimovaný bod (65 bajtů, začíná 0x04), soukromý je
 * 32bajtový skalár. OpenSSL chce DER/PEM, takže se obalují pevnými
 * hlavičkami ASN.1 — struktura je pro P-256 vždy stejná, proto jsou to
 * konstanty a ne ASN.1 parser.
 *
 * Žádná matematika křivky se tu nepíše: generování, ECDH i podpis dělá
 * OpenSSL. Tahle třída jen překládá formáty.
 */
final class Keys
{
    /** SubjectPublicKeyInfo pro P-256, před 65 bajtů bodu. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Neplatné base64url.');
        }

        return $decoded;
    }

    /**
     * Nový pár klíčů (VAPID nebo jednorázový pro šifrování zprávy).
     *
     * @return array{public: string, private: string} surové bajty (65 / 32)
     */
    public static function generate(): array
    {
        /**
         * `config` ukazuje na přiložený minimální soubor: bez openssl.cnf
         * OpenSSL na Windows (vývoj) klíč nevytvoří a ohlásí „No such
         * process". Na Linuxu je to neškodné — soubor nic nenastavuje.
         */
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'ec' => ['curve_name' => 'prime256v1'],
            'config' => __DIR__ . '/openssl.cnf',
        ]);

        if ($key === false) {
            throw new RuntimeException('OpenSSL neumí vytvořit klíč P-256: ' . (string) openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);
        $ec = $details['ec'] ?? null;

        if (!is_array($ec) || !isset($ec['x'], $ec['y'], $ec['d'])) {
            throw new RuntimeException('OpenSSL nevrátil parametry klíče P-256.');
        }

        return [
            'public' => "\x04" . self::pad($ec['x']) . self::pad($ec['y']),
            'private' => self::pad($ec['d']),
        ];
    }

    /** Veřejný bod (65 bajtů) → PEM pro `openssl_pkey_get_public()`. */
    public static function publicPem(string $point): string
    {
        self::assertPoint($point);

        return self::pem('PUBLIC KEY', hex2bin(self::SPKI_PREFIX) . $point);
    }

    /**
     * Skalár + bod → PEM ECPrivateKey (RFC 5915) pro podpis a ECDH.
     *
     * Bod se do struktury přikládá, i když by šel spočítat — OpenSSL ho
     * při načtení chce a počítat ho sami nebudeme.
     */
    public static function privatePem(string $scalar, string $point): string
    {
        if (strlen($scalar) !== 32) {
            throw new RuntimeException('Soukromý klíč P-256 má mít 32 bajtů.');
        }
        self::assertPoint($point);

        $der = "\x30\x77"
            . "\x02\x01\x01"
            . "\x04\x20" . $scalar
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00" . $point;

        return self::pem('EC PRIVATE KEY', $der);
    }

    /**
     * Podpis z OpenSSL (DER SEQUENCE of r, s) → 64 bajtů `r || s`, jak
     * ho chce JWS (ES256).
     */
    public static function derSignatureToRaw(string $der): string
    {
        $offset = 2; // 0x30 len
        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            if (($der[$offset] ?? '') !== "\x02") {
                throw new RuntimeException('Neočekávaný formát podpisu.');
            }
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;
            $parts[] = self::pad(ltrim($value, "\x00"));
        }

        return $parts[0] . $parts[1];
    }

    private static function pad(string $value): string
    {
        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    }

    private static function assertPoint(string $point): void
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('Veřejný klíč P-256 má být nekomprimovaný bod o 65 bajtech.');
        }
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }
}
