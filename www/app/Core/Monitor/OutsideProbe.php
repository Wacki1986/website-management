<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Kontroly zabezpečení, které jde udělat jen **zvenku** — jako útočník,
 * bez pluginu: je přihlašovací stránka veřejná? Chrání administraci
 * HTTP Basic auth?
 *
 * Výsledek každé sondy: `status` ok | error | unknown, `value` (co bylo
 * vidět) a `note` (věta do tabulky). `unknown` = web neodpověděl; nikdy
 * se nehlásí „chybí" jen proto, že selhalo spojení.
 */
final class OutsideProbe
{
    /** @param callable|null $fetcher pro testy: fn(string $url, bool $follow): array{status: int, headers: array<string,string>, body: string, error: string} */
    public function __construct(private readonly int $timeout = 8, private $fetcher = null)
    {
    }

    /**
     * `/wp-login.php` veřejně dostupné? Formulář s `id="loginform"` = ano.
     * 404/403/410 nebo přesměrování pryč = adresa je změněná/schovaná.
     *
     * @return array{status: string, value: string, note: string}
     */
    public function loginPage(string $siteUrl): array
    {
        $response = $this->fetch(rtrim($siteUrl, '/') . '/wp-login.php', follow: false);

        if ($response['status'] === 0) {
            return ['status' => 'unknown', 'value' => '—', 'note' => 'Web neodpověděl: ' . $response['error']];
        }

        if ($response['status'] === 200 && str_contains($response['body'], 'id="loginform"')) {
            return ['status' => 'error', 'value' => '/wp-login.php', 'note' => '/wp-login.php je veřejně dostupný'];
        }

        if ($response['status'] === 401 && self::asksForPassword($response)) {
            return ['status' => 'ok', 'value' => 'za heslem', 'note' => 'Přihlašovací stránku kryje HTTP Basic auth'];
        }

        // 401 bez výzvy k heslu posílají firewally hostingu (např. WEDOS
        // Global Protection) jen serveru monitoru — z prohlížeče může být
        // stránka dál veřejná. Proto nezjištěno, ne „nasazeno".
        if ($response['status'] === 401) {
            return ['status' => 'unknown', 'value' => 'HTTP 401', 'note' => 'Server odmítl monitor bez výzvy k heslu — nejspíš firewall hostingu, ověř ručně'];
        }

        if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
            $target = (string) ($response['headers']['location'] ?? '');

            // Přesměrování na tutéž adresu (http → https) není schování.
            if (str_contains($target, 'wp-login.php')) {
                return $this->loginPage(preg_replace('#/wp-login\.php.*$#', '', $target) ?? $siteUrl);
            }

            return ['status' => 'ok', 'value' => 'přesměrováno', 'note' => 'Výchozí adresa přihlášení přesměrovává jinam'];
        }

        return ['status' => 'ok', 'value' => 'HTTP ' . $response['status'], 'note' => 'Výchozí adresa přihlášení není dostupná'];
    }

    /**
     * `/wp-admin/` bez přihlášení: 401 s `WWW-Authenticate: Basic` = Basic
     * auth nasazená. Přesměrování na login nebo 200 = není.
     *
     * @return array{status: string, value: string, note: string}
     */
    public function adminBasicAuth(string $siteUrl): array
    {
        $response = $this->fetch(rtrim($siteUrl, '/') . '/wp-admin/', follow: false);

        if ($response['status'] === 0) {
            return ['status' => 'unknown', 'value' => '—', 'note' => 'Web neodpověděl: ' . $response['error']];
        }

        if ($response['status'] === 401 && self::asksForPassword($response)) {
            return ['status' => 'ok', 'value' => 'Zapnuto', 'note' => 'Administrace je za HTTP Basic auth'];
        }

        // Stejný důvod jako u loginPage(): 401 bez výzvy k heslu je spíš
        // firewall, který odmítl monitor, než ochrana platná pro každého.
        if ($response['status'] === 401) {
            return ['status' => 'unknown', 'value' => 'HTTP 401', 'note' => 'Server odmítl monitor bez výzvy k heslu — nejspíš firewall hostingu, ověř ručně'];
        }

        if ($response['status'] === 403) {
            return ['status' => 'ok', 'value' => 'HTTP 403', 'note' => 'Administraci kryje jiná serverová ochrana'];
        }

        return ['status' => 'error', 'value' => 'Vypnuto', 'note' => 'Na hostingu není nastavena'];
    }

    /**
     * Opravdová ochrana heslem se ohlásí hlavičkou `WWW-Authenticate`
     * (Basic, případně Digest) — prohlížeč pak ukáže okno na jméno a heslo.
     *
     * @param array{headers: array<string, string>} $response
     */
    private static function asksForPassword(array $response): bool
    {
        $scheme = strtolower((string) ($response['headers']['www-authenticate'] ?? ''));

        return str_starts_with($scheme, 'basic') || str_starts_with($scheme, 'digest');
    }

    /** @return array{status: int, headers: array<string, string>, body: string, error: string} */
    private function fetch(string $url, bool $follow): array
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url, $follow);
        }

        $handle = curl_init($url);
        $headers = [];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'MEDIAGRAFIK-Monitor/1.0 (+https://mediagrafik.cz)',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);

        if (!is_string($body)) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => curl_error($handle)];
        }

        return [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            // Stačí začátek stránky — formulář přihlášení je v prvních kilobajtech.
            'body' => substr($body, 0, 65536),
            'error' => '',
        ];
    }
}
