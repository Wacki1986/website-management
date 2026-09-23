<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Klient pluginu MEDIAGRAFIK Monitor na straně WordPress webu.
 *
 * Model je **pull**: hub volá web (WP-Cron je nespolehlivý, hub řídí čas
 * i zátěž a z odpovědi pozná stav pluginu). Endpointy jsou GET pod
 * `/wp-json/mediagrafik-monitor/v1/…`, pověření nese hlavička `X-MG-Key`.
 * Akce (aktualizace a mazání pluginů, aktualizace WordPressu) jsou POST
 * s podpisem navíc.
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

    public const ACTION_PLUGIN_UPDATE = 'plugin-update';
    public const ACTION_PLUGIN_DELETE = 'plugin-delete';
    public const ACTION_CORE_UPDATE = 'core-update';
    public const ACTION_LOGIN_LINK = 'login-link';

    /** Od které verze pluginu akci web zná — starší plugin by odpověděl `rest_no_route`. */
    public const ACTIONS_SINCE = [
        self::ACTION_PLUGIN_UPDATE => '1.1.0',
        self::ACTION_PLUGIN_DELETE => '1.2.0',
        self::ACTION_CORE_UPDATE => '1.2.0',
        self::ACTION_LOGIN_LINK => '1.3.0',
    ];

    /** Od této verze si plugin na webu bere aktualizace z knihovny pluginů správy. */
    public const LIBRARY_SINCE = '1.4.0';

    /** Kolik pluginů plugin přijme v jednom požadavku (`MG_Plugin_Updates`). */
    public const MAX_UPDATES = 10;

    /** Chybové kódy akcí z pluginu — jejich zpráva je srozumitelná, ukáže se, jak je. */
    private const ACTION_ERRORS = [
        'invalid_signature', 'file_mods_disabled', 'filesystem', 'busy',
        'nothing_to_update', 'too_many_plugins', 'nothing_to_delete',
        'no_core_update', 'core_offer_changed', 'php_too_old', 'db_too_old', 'core_update_failed',
        'login_disabled', 'login_user_missing', 'login_not_admin',
    ];

    /**
     * @param int $actionTimeout aktualizace stahuje balíčky z wordpress.org
     *                           a rozbaluje je — trvá řádově déle než čtení
     */
    public function __construct(
        private readonly int $timeout = 25,
        private readonly string $userAgent = 'MEDIAGRAFIK-Monitor/1.0 (+https://mediagrafik.cz)',
        private readonly int $actionTimeout = 240,
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
     * Aktualizace pluginů na webu. `data.plugins` = výsledek po pluginech
     * `{file, name, status: updated|up_to_date|failed, from, to, message}`.
     *
     * @param array<int, string> $files cesty pluginů (`slozka/soubor.php`)
     * @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}
     */
    public function updatePlugins(string $siteUrl, #[\SensitiveParameter] string $apiKey, array $files): array
    {
        return $this->action($siteUrl, $apiKey, self::ACTION_PLUGIN_UPDATE, ['plugins' => array_values($files)]);
    }

    /**
     * Smazání neaktivních pluginů. `data.plugins` = výsledek po pluginech
     * `{file, name, version, status: deleted|skipped|failed, message}`.
     *
     * @param array<int, string> $files
     * @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}
     */
    public function deletePlugins(string $siteUrl, #[\SensitiveParameter] string $apiKey, array $files): array
    {
        return $this->action($siteUrl, $apiKey, self::ACTION_PLUGIN_DELETE, ['plugins' => array_values($files)]);
    }

    /**
     * Aktualizace WordPressu na `$version` — tu, kterou člověk viděl a
     * potvrdil. Nabízí-li web mezitím jinou, plugin odmítne. `data.core` =
     * `{from, to}`.
     *
     * @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}
     */
    public function updateCore(string $siteUrl, #[\SensitiveParameter] string $apiKey, string $version): array
    {
        return $this->action($siteUrl, $apiKey, self::ACTION_CORE_UPDATE, ['version' => $version]);
    }

    /**
     * Jednorázový odkaz, který v prohlížeči přihlásí účet `$user` do
     * administrace webu. `data.login` = `{url, user, expires_in}`.
     *
     * @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}
     */
    public function loginLink(string $siteUrl, #[\SensitiveParameter] string $apiKey, string $user): array
    {
        return $this->action($siteUrl, $apiKey, self::ACTION_LOGIN_LINK, ['user' => $user]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}
     */
    private function action(string $siteUrl, #[\SensitiveParameter] string $apiKey, string $action, array $body): array
    {
        $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $results = $this->fetchMany(['single' => ['url' => $siteUrl, 'key' => $apiKey]], 'actions/' . $action, false, $payload, time());

        return $results['single'];
    }

    /**
     * Podpis mutujícího požadavku — protějšek `MG_Api_Key::verify_signature()`
     * v pluginu. Cesta je routa WordPressu (bez `/wp-json`), takže podpis
     * platí pro hezkou adresu i pro záložku `?rest_route=`.
     */
    public static function signature(string $method, string $endpoint, int $timestamp, string $body, #[\SensitiveParameter] string $apiKey): string
    {
        $payload = $method . "\n/" . self::NAMESPACE . '/' . $endpoint . "\n" . $timestamp . "\n" . $body;

        return hash_hmac('sha256', $payload, $apiKey);
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
     * S `$payload` jde o podepsaný POST (akce), jinak o GET (čtení).
     *
     * @param array<int|string, array{url: string, key: string}> $sites
     * @return array<int|string, array{ok: bool, code: string, status: int, data: ?array<string, mixed>, error: ?string, plugin_version: string}>
     */
    private function fetchMany(array $sites, string $endpoint, bool $fallback = false, ?string $payload = null, int $timestamp = 0): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($sites as $id => $site) {
            $headers = [
                'Accept: application/json',
                self::HEADER . ': ' . $site['key'],
            ];
            $options = [
                CURLOPT_URL => self::endpointUrl($site['url'], $endpoint, $fallback),
                CURLOPT_RETURNTRANSFER => true,
                // Přesměrování se nesleduje: přesměrovaný požadavek by nesl
                // klíč jinam, než kam patří.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $payload === null ? $this->timeout : $this->actionTimeout,
                CURLOPT_USERAGENT => $this->userAgent,
            ];

            if ($payload !== null) {
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'X-MG-Timestamp: ' . $timestamp;
                $headers[] = 'X-MG-Signature: ' . self::signature('POST', $endpoint, $timestamp, $payload, $site['key']);
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $payload;
            }

            $handle = curl_init();
            curl_setopt_array($handle, $options + [CURLOPT_HTTPHEADER => $headers]);
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
            foreach ($this->fetchMany($retry, $endpoint, true, $payload, $timestamp) as $id => $second) {
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

        if (in_array($wpCode, self::ACTION_ERRORS, true)) {
            return self::result(false, 'error', $status, null, (string) ($decoded['message'] ?? $wpCode));
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
