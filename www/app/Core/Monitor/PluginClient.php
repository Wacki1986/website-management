<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Klient pluginu MEDIAGRAFIK Monitor na straně WordPress webu.
 *
 * Model je **pull**: hub volá web (WP-Cron je nespolehlivý, hub řídí čas
 * i zátěž a z odpovědi pozná stav pluginu). Endpointy jsou GET pod
 * `/wp-json/mediagrafik-monitor/v1/…`, pověření nese hlavička `X-MG-Key`.
 *
 * Výsledek má vždy stejný tvar a `code` říká, **co se stalo**, ne jen že
 * to nevyšlo — na tom stojí stav `api_status` u webu:
 *
 *  - `ok`          JSON s `ok: true`
 *  - `bad_key`     web odpověděl 401/403 s naší obálkou — plugin je, klíč nesedí
 *  - `no_plugin`   REST API běží, ale náš namespace nezná (`rest_no_route`)
 *  - `error`       jiná odpověď (HTML, 500, cizí JSON)
 *  - `unreachable` spojení selhalo (timeout, DNS) — web je možná celý dole
 *
 * Web bez hezkých adres odpovídá na `/wp-json/` 404 HTML; druhý pokus jde
 * přes `?rest_route=`, který funguje vždy.
 */
final class PluginClient
{
    public const NAMESPACE = 'mediagrafik-monitor/v1';
    public const HEADER = 'X-MG-Key';

    public function __construct(
        private readonly int $timeout = 25,
        private readonly string $userAgent = 'MEDIAGRAFIK-Monitor/1.0 (+https://mediagrafik.cz)',
    ) {
    }

    /** @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string} */
    public function ping(string $siteUrl, #[\SensitiveParameter] string $apiKey): array
    {
        return $this->call($siteUrl, $apiKey, 'ping');
    }

    /** @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string} */
    public function summary(string $siteUrl, #[\SensitiveParameter] string $apiKey): array
    {
        return $this->call($siteUrl, $apiKey, 'summary');
    }

    /** @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string} */
    public function security(string $siteUrl, #[\SensitiveParameter] string $apiKey): array
    {
        return $this->call($siteUrl, $apiKey, 'security');
    }

    /**
     * Souhrn z několika webů najednou (curl_multi) — cron jich stahuje
     * po dávkách. Klíčem výsledku je klíč vstupu (id webu).
     *
     * @param array<int|string, array{url: string, key: string}> $sites
     * @return array<int|string, array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}>
     */
    public function summaryMany(array $sites, int $concurrency = 4): array
    {
        $results = [];

        foreach (array_chunk($sites, max(1, $concurrency), true) as $chunk) {
            $results += $this->fetchMany($chunk, 'summary');
        }

        return $results;
    }

    /** @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string} */
    public function call(string $siteUrl, #[\SensitiveParameter] string $apiKey, string $endpoint): array
    {
        $results = $this->fetchMany(['single' => ['url' => $siteUrl, 'key' => $apiKey]], $endpoint);

        return $results['single'];
    }

    /** Adresa endpointu — hezká, nebo přes `?rest_route=` (záložka). */
    public static function endpointUrl(string $siteUrl, string $endpoint, bool $fallback = false): string
    {
        $base = rtrim($siteUrl, '/');

        return $fallback
            ? $base . '/?rest_route=/' . self::NAMESPACE . '/' . $endpoint
            : $base . '/wp-json/' . self::NAMESPACE . '/' . $endpoint;
    }

    /**
     * @param array<int|string, array{url: string, key: string}> $sites
     * @return array<int|string, array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}>
     */
    private function fetchMany(array $sites, string $endpoint, bool $fallback = false): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($sites as $id => $site) {
            $handle = curl_init();
            curl_setopt_array($handle, [
                CURLOPT_URL => self::endpointUrl($site['url'], $endpoint, $fallback),
                CURLOPT_RETURNTRANSFER => true,
                // Přesměrování se nesleduje: přesměrovaný požadavek by nesl
                // klíč jinam, než kam patří.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    self::HEADER . ': ' . $site['key'],
                ],
                CURLOPT_USERAGENT => $this->userAgent,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$id] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Výsledek spojení dává curl_multi frontou zpráv — curl_errno() na
        // handle uvnitř multi zůstává 0 i po selhání.
        $errors = [];

        while (($message = curl_multi_info_read($multi)) !== false) {
            if ($message['result'] !== CURLE_OK) {
                $errors[(int) $message['handle']] = curl_strerror($message['result']) ?: ('curl ' . $message['result']);
            }
        }

        $results = [];
        $retry = [];

        foreach ($handles as $id => $handle) {
            $body = curl_multi_getcontent($handle);
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = $errors[(int) $handle] ?? ($httpStatus === 0 ? 'Spojení se nepodařilo navázat.' : null);
            $result = $error !== null
                ? self::result(false, 'unreachable', $httpStatus, null, $error)
                : self::classify($httpStatus, is_string($body) ? $body : '');

            // HTML 404 (nebo přesměrování) na hezké adrese: web nejspíš nemá
            // zapnuté hezké trvalé odkazy — zkusí se `?rest_route=`.
            if (!$fallback && $result['code'] === 'error' && in_array($httpStatus, [404, 301, 302], true)) {
                $retry[$id] = $sites[$id];
            }

            $results[$id] = $result;
            curl_multi_remove_handle($multi, $handle);
        }

        curl_multi_close($multi);

        if ($retry !== []) {
            foreach ($this->fetchMany($retry, $endpoint, true) as $id => $second) {
                // Záložka se použije, jen když řekla něco přesnějšího než HTML 404.
                if ($second['code'] !== 'error' && $second['code'] !== 'unreachable') {
                    $results[$id] = $second;
                }
            }
        }

        return $results;
    }

    /** @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string} */
    private static function classify(int $status, string $body): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return self::result(false, 'error', $status, null, 'Odpověď není JSON (HTTP ' . $status . ') — REST API webu neodpovídá, nebo web běží bez hezkých adres.');
        }

        // Naše obálka.
        if (array_key_exists('ok', $decoded)) {
            if ($decoded['ok'] === true && is_array($decoded['data'] ?? null)) {
                return self::result(true, 'ok', $status, $decoded['data'], null, (string) ($decoded['plugin_version'] ?? ''));
            }

            $code = (string) ($decoded['error']['code'] ?? '');
            $message = (string) ($decoded['error']['message'] ?? 'Plugin odmítl požadavek.');

            return in_array($code, ['invalid_key', 'missing_key', 'too_many_attempts'], true)
                ? self::result(false, 'bad_key', $status, null, $message)
                : self::result(false, 'error', $status, null, $message);
        }

        // Odmítnutí z `permission_callback` pluginu balí WordPress jako
        // `{"code":"invalid_key","message":…,"data":{"status":401}}`.
        $wpCode = (string) ($decoded['code'] ?? '');

        if (in_array($wpCode, ['invalid_key', 'missing_key', 'too_many_attempts'], true)) {
            return self::result(false, 'bad_key', $status, null, (string) ($decoded['message'] ?? 'Plugin odmítl klíč.'));
        }

        // Odpověď WordPressu bez našeho pluginu (`{"code":"rest_no_route",…}`).
        if ($wpCode === 'rest_no_route') {
            return self::result(false, 'no_plugin', $status, null, 'Plugin MEDIAGRAFIK Monitor na webu není nainstalovaný nebo aktivní.');
        }

        // Cizí zásah do REST (bezpečnostní plugin) — 401/403 s WP obálkou.
        if (in_array($status, [401, 403], true)) {
            return self::result(false, 'error', $status, null, 'REST API webu odmítlo požadavek (' . (string) ($decoded['message'] ?? 'HTTP ' . $status) . ').');
        }

        return self::result(false, 'error', $status, null, 'Neočekávaná odpověď (HTTP ' . $status . ').');
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}
     */
    private static function result(bool $ok, string $code, int $status, ?array $data, ?string $error, string $pluginVersion = ''): array
    {
        return ['ok' => $ok, 'code' => $code, 'status' => $status, 'data' => $data, 'error' => $error, 'plugin_version' => $pluginVersion];
    }
}
