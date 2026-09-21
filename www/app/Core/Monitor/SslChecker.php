<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Platnost SSL certifikátu — přímé TLS spojení a přečtení certifikátu.
 *
 * `verify_peer` je vypnuté schválně: prošlý nebo nesedící certifikát se má
 * **přečíst a nahlásit**, ne skrýt za chybu spojení. Hodnotí se zvlášť:
 * `days_left < 0` = vypršel.
 */
final class SslChecker
{
    public function __construct(private readonly int $timeout = 6)
    {
    }

    /**
     * @return array{ok: bool, valid_to: ?string, valid_from: ?string, issuer: ?string, days_left: ?int, error: ?string}
     */
    public function check(string $host, int $port = 443, ?int $now = null): array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]);

        $socket = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            return self::failure($error !== '' ? $error : 'TLS spojení se nepodařilo navázat (' . $errno . ').');
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certificate === null) {
            return self::failure('Server nevydal certifikát.');
        }

        return self::parse($certificate, $now);
    }

    /**
     * Rozbor certifikátu (PEM řetězec nebo resource) — oddělené kvůli testům
     * s certifikátem ze souboru.
     *
     * @param mixed $certificate
     * @return array{ok: bool, valid_to: ?string, valid_from: ?string, issuer: ?string, days_left: ?int, error: ?string}
     */
    public static function parse(mixed $certificate, ?int $now = null): array
    {
        $parsed = openssl_x509_parse($certificate);

        if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            return self::failure('Certifikát se nepodařilo přečíst.');
        }

        $now ??= time();
        $validTo = (int) $parsed['validTo_time_t'];
        $issuer = (string) ($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? '');

        return [
            'ok' => true,
            'valid_to' => date('Y-m-d', $validTo),
            'valid_from' => isset($parsed['validFrom_time_t']) ? date('Y-m-d', (int) $parsed['validFrom_time_t']) : null,
            'issuer' => $issuer !== '' ? mb_substr($issuer, 0, 120) : null,
            'days_left' => (int) floor(($validTo - $now) / 86400),
            'error' => null,
        ];
    }

    /** @return array{ok: bool, valid_to: ?string, valid_from: ?string, issuer: ?string, days_left: ?int, error: ?string} */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'valid_to' => null, 'valid_from' => null, 'issuer' => null, 'days_left' => null, 'error' => mb_substr($error, 0, 255)];
    }
}
