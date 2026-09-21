<?php

declare(strict_types=1);

namespace App\Core\Clients;

/**
 * Načtení firmy z ARESu podle IČO (návrh: „Načíst z ARESu" u nového klienta).
 *
 * Veřejné REST API bez klíče: `GET /ekonomicke-subjekty/{ico}` vrací JSON
 * s `obchodniJmeno`, `dic` a `sidlo.textovaAdresa`. Odpověď se nikam
 * neukládá — jen předvyplní formulář, člověk ji zkontroluje a uloží.
 */
final class Ares
{
    public const ENDPOINT = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/';

    /** @param callable|null $fetcher pro testy: fn(string $url): array{status: int, body: string} */
    public function __construct(private readonly int $timeout = 8, private $fetcher = null)
    {
    }

    /** IČO má 8 číslic; kratší se doplňuje nulami zleva (ARES to tak chce). */
    public static function normalizeIco(string $ico): string
    {
        $digits = preg_replace('/\D+/', '', $ico) ?? '';

        return $digits === '' || strlen($digits) > 8 ? '' : str_pad($digits, 8, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{name: string, vat_id: string, address: string}|null null = nenalezeno / nedostupné
     */
    public function lookup(string $ico): ?array
    {
        $ico = self::normalizeIco($ico);

        if ($ico === '') {
            return null;
        }

        $response = $this->fetcher !== null
            ? ($this->fetcher)(self::ENDPOINT . $ico)
            : $this->fetch(self::ENDPOINT . $ico);

        if (($response['status'] ?? 0) !== 200) {
            return null;
        }

        $data = json_decode((string) ($response['body'] ?? ''), true);

        if (!is_array($data) || !isset($data['obchodniJmeno'])) {
            return null;
        }

        return [
            'name' => trim((string) $data['obchodniJmeno']),
            'vat_id' => trim((string) ($data['dic'] ?? '')),
            'address' => trim((string) ($data['sidlo']['textovaAdresa'] ?? '')),
        ];
    }

    /** @return array{status: int, body: string} */
    private function fetch(string $url): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'MEDIAGRAFIK-Sprava-webu/1.0',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => is_string($body) ? $status : 0, 'body' => is_string($body) ? $body : ''];
    }
}
