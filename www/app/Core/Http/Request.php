<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Neměnný obal nad příchozím requestem.
 *
 * Vstupy se čtou výhradně přes tuto třídu — nikde jinde v aplikaci se nesmí
 * sáhnout na $_GET/$_POST/$_SERVER, aby šly requesty v testech podstrčit.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $files
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $files = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly array $server = [],
        /**
         * Tělo se tvářilo jako JSON, ale nešlo přečíst.
         *
         * Bez tohohle příznaku se rozbité tělo chová jako prázdné a volající
         * dostane hlášku „chybí povinné pole" — což je u rozhraní pro cizí
         * server ta nejhorší nápověda, jakou může dostat. Nejčastější příčina
         * je špatné kódování: `json_decode` neplatné UTF-8 odmítne celé.
         */
        public readonly bool $jsonError = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = '/' . trim(rawurldecode($path), '/');

        // Hosting bez mod_rewrite: adresy mají tvar /index.php/prihlaseni.
        // Aplikace se tím chová stejně, jen jsou ošklivější URL.
        if (str_starts_with($path, '/index.php')) {
            $path = '/' . ltrim(substr($path, strlen('/index.php')), '/');
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $body = $_POST;
        $jsonError = false;

        // JSON tělo (AJAX) se chová stejně jako formulářový POST.
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $body = $decoded;
                } else {
                    $jsonError = true;
                }
            }
        }

        return new self(
            method: $method,
            path: $path === '//' ? '/' : $path,
            query: $_GET,
            body: $body,
            files: $_FILES,
            cookies: $_COOKIE,
            headers: $headers,
            server: $_SERVER,
            jsonError: $jsonError,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        return in_array($this->input($key), ['1', 1, true, 'true', 'on', 'yes'], true);
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** Odpověď se má poslat jako JSON (AJAX volání, ne klasický formulář). */
    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json')
            || str_contains($this->header('content-type'), 'application/json')
            || strtolower($this->header('x-requested-with')) === 'xmlhttprequest';
    }

    public function isSecure(): bool
    {
        $https = (string) ($this->server['HTTPS'] ?? '');

        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        // Reverzní proxy na sdíleném hostingu.
        return strtolower($this->header('x-forwarded-proto')) === 'https';
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
