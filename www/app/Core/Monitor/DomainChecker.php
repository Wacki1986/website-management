<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Expirace domény přes RDAP (nástupce WHOIS, JSON).
 *
 * `.cz` obsluhuje `rdap.nic.cz`, ostatní přes `rdap.org`, který přesměruje
 * na správný registr. Pravidlo je ve výchozím stavu vypnuté (návrh:
 * „Expirace domény" s vypnutým přepínačem) — kontrola běží jen s ním.
 */
final class DomainChecker
{
    /** @param callable|null $fetcher pro testy: fn(string $url): array{status: int, body: string} */
    public function __construct(private readonly int $timeout = 8, private $fetcher = null)
    {
    }

    /** Registrovatelná doména z hostname: `rezervace.hotelzlatylev.cz` → `hotelzlatylev.cz`. */
    public static function registrableDomain(string $host): ?string
    {
        $host = strtolower(trim($host));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) {
            return null;
        }

        $parts = explode('.', $host);
        $count = count($parts);

        // Dvouúrovňové veřejné přípony, se kterými se u klientů dá potkat.
        $secondLevel = ['co.uk', 'org.uk', 'com.au', 'co.nz', 'com.br', 'co.jp', 'com.pl', 'edu.pl', 'org.pl'];
        $lastTwo = $parts[$count - 2] . '.' . $parts[$count - 1];

        if (in_array($lastTwo, $secondLevel, true) && $count >= 3) {
            return $parts[$count - 3] . '.' . $lastTwo;
        }

        return $lastTwo;
    }

    /**
     * @return array{ok: bool, domain: ?string, expires_on: ?string, days_left: ?int, error: ?string}
     */
    public function check(string $host, ?int $now = null): array
    {
        $domain = self::registrableDomain($host);

        if ($domain === null) {
            return ['ok' => false, 'domain' => null, 'expires_on' => null, 'days_left' => null, 'error' => 'Adresa nemá registrovatelnou doménu.'];
        }

        $url = str_ends_with($domain, '.cz')
            ? 'https://rdap.nic.cz/domain/' . $domain
            : 'https://rdap.org/domain/' . $domain;

        $response = $this->fetcher !== null ? ($this->fetcher)($url) : $this->fetch($url);

        if (($response['status'] ?? 0) !== 200) {
            return ['ok' => false, 'domain' => $domain, 'expires_on' => null, 'days_left' => null,
                'error' => 'RDAP neodpověděl (HTTP ' . (int) ($response['status'] ?? 0) . ').'];
        }

        $expires = self::expirationFrom((string) ($response['body'] ?? ''));

        if ($expires === null) {
            return ['ok' => false, 'domain' => $domain, 'expires_on' => null, 'days_left' => null, 'error' => 'RDAP nevrátil datum expirace.'];
        }

        return [
            'ok' => true,
            'domain' => $domain,
            'expires_on' => $expires,
            'days_left' => (int) floor((strtotime($expires . ' 23:59:59') - ($now ?? time())) / 86400),
            'error' => null,
        ];
    }

    /** Datum expirace z RDAP JSONu (`events[].eventAction = expiration`). */
    public static function expirationFrom(string $json): ?string
    {
        $data = json_decode($json, true);

        foreach ((array) ($data['events'] ?? []) as $event) {
            if (is_array($event) && ($event['eventAction'] ?? '') === 'expiration' && isset($event['eventDate'])) {
                $timestamp = strtotime((string) $event['eventDate']);

                return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
            }
        }

        return null;
    }

    /** @return array{status: int, body: string} */
    private function fetch(string $url): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json, application/json'],
            CURLOPT_USERAGENT => UptimeClient::USER_AGENT,
        ]);

        $body = curl_exec($handle);

        return ['status' => is_string($body) ? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0, 'body' => is_string($body) ? $body : ''];
    }
}
