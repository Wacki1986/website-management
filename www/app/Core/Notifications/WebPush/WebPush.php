<?php

declare(strict_types=1);

namespace App\Core\Notifications\WebPush;

use Closure;
use RuntimeException;

/**
 * Odeslání jedné push zprávy na jeden endpoint.
 *
 * Skládá požadavek podle RFC 8030/8291/8292: zašifrované tělo, hlavičky
 * `Content-Encoding: aes128gcm`, `TTL`, `Urgency` a VAPID `Authorization`.
 * Přenos dělá curl; v testech se podstrčí vlastní `$transport`, aby se
 * nevolala skutečná push služba.
 *
 * Výsledek je trojí, protože volající (`PushNotifier`) s každým naloží
 * jinak:
 *
 *  - `OK`   — služba zprávu přijala (201, u některých 200),
 *  - `GONE` — odběr už neexistuje (404/410): prohlížeč ho zrušil nebo
 *             uživatel odvolal oprávnění; řádek se má smazat, opakovat
 *             nemá smysl,
 *  - `FAILED` — cokoli jiného (síť, 5xx, 429): počítá se `failed_count`.
 */
final class WebPush
{
    public const OK = 'ok';
    public const GONE = 'gone';
    public const FAILED = 'failed';

    /** Jak dlouho má služba zprávu držet, když je telefon vypnutý. */
    private const TTL = 24 * 3600;

    /** @var Closure(string $endpoint, array<int, string> $headers, string $body): array{status: int, error: string} */
    private Closure $transport;

    /**
     * @param Closure(string, array<int, string>, string): array{status: int, error: string}|null $transport
     * @param int $connectTimeout vteřin na navázání spojení
     * @param int $timeout vteřin na celé odeslání
     */
    public function __construct(
        private readonly Vapid $vapid,
        ?Closure $transport = null,
        private readonly int $connectTimeout = 3,
        private readonly int $timeout = 5,
    ) {
        $this->transport = $transport ?? $this->curlTransport(...);
    }

    public function publicKey(): string
    {
        return $this->vapid->publicKey();
    }

    /**
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     * @param array<string, mixed> $payload co dostane service worker (JSON)
     *
     * @return array{result: string, status: int, error: string}
     */
    public function send(array $subscription, array $payload): array
    {
        $endpoint = $subscription['endpoint'];

        try {
            $body = MessageEncryption::encrypt(
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $subscription['p256dh'],
                $subscription['auth'],
            );
            $authorization = $this->vapid->authorization($endpoint);
        } catch (RuntimeException $e) {
            // Vadný odběr (nesmyslné klíče) — opakování nic nespraví.
            return ['result' => self::GONE, 'status' => 0, 'error' => $e->getMessage()];
        }

        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
            'TTL: ' . self::TTL,
            'Urgency: normal',
        ];

        $response = ($this->transport)($endpoint, $headers, $body);
        $status = $response['status'];

        if ($status === 200 || $status === 201 || $status === 202) {
            return ['result' => self::OK, 'status' => $status, 'error' => ''];
        }

        if ($status === 404 || $status === 410) {
            return ['result' => self::GONE, 'status' => $status, 'error' => 'Odběr už neexistuje.'];
        }

        return [
            'result' => self::FAILED,
            'status' => $status,
            'error' => $response['error'] !== '' ? $response['error'] : 'HTTP ' . $status,
        ];
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, error: string}
     */
    private function curlTransport(string $endpoint, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'error' => 'Chybí rozšíření curl.'];
        }

        $curl = curl_init($endpoint);

        if ($curl === false) {
            return ['status' => 0, 'error' => 'curl_init selhalo.'];
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = $responseBody === false ? curl_error($curl) : '';
        curl_close($curl);

        if ($error === '' && $status >= 400 && is_string($responseBody) && $responseBody !== '') {
            $error = 'HTTP ' . $status . ': ' . mb_substr(strip_tags($responseBody), 0, 160);
        }

        return ['status' => $status, 'error' => $error];
    }
}
