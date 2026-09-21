<?php

declare(strict_types=1);

namespace App\Core\Notifications\WebPush;

use RuntimeException;

/**
 * VAPID — totožnost serveru vůči push službě (RFC 8292).
 *
 * Push služba (Google, Apple, Mozilla) chce vědět, kdo jí zprávy posílá:
 * prohlížeč si při odběru zapamatuje veřejný klíč správy a služba pak
 * přijme jen požadavky podepsané tím soukromým. Pár klíčů drží `VapidKeys`
 * v tabulce `settings` — na rozdíl od klientské aplikace, kde je
 * v `config/env.php`.
 *
 * Podpis je JWT s `ES256`: hlavička a nároky v base64url, podpis ECDSA
 * P-256/SHA-256 přes OpenSSL, převedený z DER na `r || s`. Nárok `aud` je
 * původ push služby (ne celý endpoint), `exp` nejvýš 24 h — služba jinak
 * odmítne.
 */
final class Vapid
{
    /** Platnost tokenu. RFC dovoluje 24 h; 12 h nechává rezervu na posun hodin. */
    private const LIFETIME = 12 * 3600;

    public function __construct(
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $subject,
    ) {
    }

    /**
     * @param array<string, mixed> $config klíče `public_key`, `private_key`, `subject`
     */
    public static function fromConfig(array $config): ?self
    {
        $public = trim((string) ($config['public_key'] ?? ''));
        $private = trim((string) ($config['private_key'] ?? ''));
        $subject = trim((string) ($config['subject'] ?? ''));

        if ($public === '' || $private === '') {
            return null;
        }

        if ($subject === '' || (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https://'))) {
            return null;
        }

        return new self($public, $private, $subject);
    }

    /** Veřejný klíč (base64url) — jde do `<meta>` a odtud do `pushManager.subscribe()`. */
    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Hodnota hlavičky `Authorization` pro daný endpoint.
     *
     * @param int|null $now jen pro test
     */
    public function authorization(string $endpoint, ?int $now = null): string
    {
        return 'vapid t=' . $this->token($endpoint, $now) . ', k=' . $this->publicKey;
    }

    public function token(string $endpoint, ?int $now = null): string
    {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host = parse_url($endpoint, PHP_URL_HOST);

        if (!is_string($scheme) || !is_string($host)) {
            throw new RuntimeException('Endpoint odběru není platná adresa.');
        }

        $port = parse_url($endpoint, PHP_URL_PORT);
        $audience = $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');

        $header = Keys::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = Keys::base64UrlEncode((string) json_encode([
            'aud' => $audience,
            'exp' => ($now ?? time()) + self::LIFETIME,
            'sub' => $this->subject,
        ]));

        $signingInput = $header . '.' . $claims;

        $point = Keys::base64UrlDecode($this->publicKey);
        $scalar = Keys::base64UrlDecode($this->privateKey);
        $key = openssl_pkey_get_private(Keys::privatePem($scalar, $point));

        if ($key === false) {
            throw new RuntimeException('Uložený VAPID klíč není platný klíč P-256.');
        }

        $der = '';
        if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Podpis VAPID selhal: ' . (string) openssl_error_string());
        }

        return $signingInput . '.' . Keys::base64UrlEncode(Keys::derSignatureToRaw($der));
    }

    /**
     * Nový pár do nastavení — obě hodnoty base64url, jak je chce protokol.
     *
     * @return array{public_key: string, private_key: string}
     */
    public static function generateKeys(): array
    {
        $pair = Keys::generate();

        return [
            'public_key' => Keys::base64UrlEncode($pair['public']),
            'private_key' => Keys::base64UrlEncode($pair['private']),
        ];
    }
}
