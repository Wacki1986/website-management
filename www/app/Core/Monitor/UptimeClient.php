<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * Kontrola dostupnosti — paralelní GET na kořeny webů (curl_multi).
 *
 * Odvozeno z `HealthClient` správy instancí. Rozdíly: GET místo HEAD
 * (řada hostingů odpovídá na HEAD 405), tělo se zahazuje průběžně
 * (`WRITEFUNCTION`), sleduje se až 3× přesměrování (http → https, www).
 * Dostupný = HTTP 200–399, nebo 401 (celý web za Basic auth — staging).
 */
final class UptimeClient
{
    public const USER_AGENT = 'MEDIAGRAFIK-Monitor/1.0 (+https://mediagrafik.cz)';

    public function __construct(private readonly int $timeout = 10, private readonly int $batchSize = 30)
    {
    }

    /**
     * @param array<int|string, string> $urls klíč => URL
     * @return array<int|string, array{ok: bool, status: int, ms: int, error: ?string}>
     */
    public function checkMany(array $urls): array
    {
        $results = [];

        // Po dávkách: 84 otevřených socketů najednou by sdílený hosting
        // nemusel dovolit.
        foreach (array_chunk($urls, max(1, $this->batchSize), true) as $chunk) {
            $results += $this->fetchBatch($chunk);
        }

        return $results;
    }

    /** @return array{ok: bool, status: int, ms: int, error: ?string} */
    public function check(string $url): array
    {
        return $this->checkMany(['single' => $url])['single'];
    }

    /** Je odpověď „web běží"? Veřejná kvůli testům pravidel. */
    public static function isUp(int $status): bool
    {
        return ($status >= 200 && $status < 400) || $status === 401;
    }

    /**
     * @param array<int|string, string> $urls
     * @return array<int|string, array{ok: bool, status: int, ms: int, error: ?string}>
     */
    private function fetchBatch(array $urls): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($urls as $id => $url) {
            $handle = curl_init();
            curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => max(2, min(5, $this->timeout)),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: text/html,*/*', 'Cache-Control: no-cache'],
                // Tělo se nikam neukládá — stačí stavový kód a čas.
                CURLOPT_WRITEFUNCTION => static fn ($curl, string $chunk): int => strlen($chunk),
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

        // Chyby spojení dává curl_multi frontou zpráv (viz PluginClient).
        $errors = [];

        while (($message = curl_multi_info_read($multi)) !== false) {
            if ($message['result'] !== CURLE_OK) {
                $errors[(int) $message['handle']] = curl_strerror($message['result']) ?: ('curl ' . $message['result']);
            }
        }

        $results = [];

        foreach ($handles as $id => $handle) {
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $ms = (int) round(((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000);
            $error = $errors[(int) $handle] ?? null;

            if ($error !== null || $httpStatus === 0) {
                $results[$id] = ['ok' => false, 'status' => $httpStatus, 'ms' => $ms, 'error' => $error ?? 'Spojení se nepodařilo navázat.'];
            } else {
                $results[$id] = [
                    'ok' => self::isUp($httpStatus),
                    'status' => $httpStatus,
                    'ms' => $ms,
                    'error' => self::isUp($httpStatus) ? null : 'HTTP ' . $httpStatus,
                ];
            }

            curl_multi_remove_handle($multi, $handle);
        }

        curl_multi_close($multi);

        return $results;
    }
}
