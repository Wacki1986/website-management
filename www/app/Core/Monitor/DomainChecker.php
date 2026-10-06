<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Expirace a registrátor domény přes RDAP (nástupce WHOIS, JSON).
 *
 * `.cz` obsluhuje `rdap.nic.cz`, ostatní přes `rdap.org`, který přesměruje
 * na správný registr. Pravidlo je ve výchozím stavu vypnuté (návrh:
 * „Expirace domény" s vypnutým přepínačem) — kontrola běží jen s ním.
 */
final class DomainChecker
{
    /**
     * Registrátoři `.cz`: rdap.nic.cz vrací jen zkratku (`REG-WEDOS`), jméno
     * ne — a dotaz na zkratku vrací kontaktní osobu, ne firmu. Neznámá
     * zkratka se ukáže bez `REG-` (`REG-EXONHOST` → „EXONHOST").
     */
    private const CZ_REGISTRARS = [
        'REG-WEDOS' => 'WEDOS',
        'REG-ACTIVE24' => 'Active24',
        'REG-INTERNET-CZ' => 'Forpsi',
        'REG-GRANSY' => 'Subreg',
        'REG-SEZNAM' => 'Seznam.cz',
        'REG-ZONER' => 'Zoner',
        'REG-IPI' => 'IPI',
        'REG-WEBGLOBE' => 'Webglobe',
    ];

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
     * @return array{ok: bool, domain: ?string, expires_on: ?string, days_left: ?int, registrar: ?string, error: ?string}
     */
    public function check(string $host, ?int $now = null): array
    {
        $domain = self::registrableDomain($host);

        if ($domain === null) {
            return ['ok' => false, 'domain' => null, 'expires_on' => null, 'days_left' => null, 'registrar' => null, 'error' => 'Adresa nemá registrovatelnou doménu.'];
        }

        $url = str_ends_with($domain, '.cz')
            ? 'https://rdap.nic.cz/domain/' . $domain
            : 'https://rdap.org/domain/' . $domain;

        $response = $this->fetcher !== null ? ($this->fetcher)($url) : $this->fetch($url);

        if (($response['status'] ?? 0) !== 200) {
            return ['ok' => false, 'domain' => $domain, 'expires_on' => null, 'days_left' => null, 'registrar' => null,
                'error' => 'RDAP neodpověděl (HTTP ' . (int) ($response['status'] ?? 0) . ').'];
        }

        $expires = self::expirationFrom((string) ($response['body'] ?? ''));

        if ($expires === null) {
            return ['ok' => false, 'domain' => $domain, 'expires_on' => null, 'days_left' => null, 'registrar' => self::registrarFrom((string) ($response['body'] ?? '')), 'error' => 'RDAP nevrátil datum expirace.'];
        }

        return [
            'ok' => true,
            'domain' => $domain,
            'expires_on' => $expires,
            'days_left' => (int) floor((strtotime($expires . ' 23:59:59') - ($now ?? time())) / 86400),
            'registrar' => self::registrarFrom((string) ($response['body'] ?? '')),
            'error' => null,
        ];
    }

    /**
     * Registrátor z RDAP JSONu: entita s rolí `registrar` — jméno z vCard
     * (`fn`, gTLD), jinak zkratka (`.cz`) přeložená přes `CZ_REGISTRARS`.
     */
    public static function registrarFrom(string $json): ?string
    {
        $data = json_decode($json, true);

        foreach ((array) ($data['entities'] ?? []) as $entity) {
            if (!is_array($entity) || !in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }

            foreach ((array) ($entity['vcardArray'][1] ?? []) as $property) {
                if (is_array($property) && ($property[0] ?? '') === 'fn' && is_string($property[3] ?? null) && trim($property[3]) !== '') {
                    return mb_substr(trim($property[3]), 0, 120);
                }
            }

            $handle = strtoupper(trim((string) ($entity['handle'] ?? '')));

            if ($handle !== '') {
                return self::CZ_REGISTRARS[$handle] ?? mb_substr(str_replace('-', ' ', (string) preg_replace('/^REG-/', '', $handle)), 0, 120);
            }
        }

        return null;
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
