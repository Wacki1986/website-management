<?php

declare(strict_types=1);

namespace App\Core\Http;

use LogicException;

/**
 * Routovací tabulka.
 *
 * Každá routa nese oprávnění, které se vyžaduje ještě před spuštěním
 * controlleru — autorizace tak nemůže „vypadnout" tím, že ji někdo zapomene
 * napsat do těla akce. Mutační routa (POST/PUT/PATCH/DELETE) musí oprávnění
 * deklarovat vždy: buď konkrétní capability, nebo explicitně PUBLIC_ACCESS.
 * To je poučení ze staré aplikace, kde mazání záznamů chránilo jen skryté
 * tlačítko v šabloně.
 */
final class Router
{
    /** Routa je dostupná i nepřihlášenému (login, reset hesla, systémové endpointy). */
    public const PUBLIC_ACCESS = '@public';

    /** Routa vyžaduje přihlášení, ale žádnou konkrétní capability (profil, dashboard). */
    public const AUTH_ONLY = '@auth';

    /** @var array<int, array{method: string, regex: string, handler: array{0: class-string, 1: string}, capability: string, name: string, path: string, csrf: bool}> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param bool $csrf vyžadovat token? Vypíná se **jen** u rozhraní, které
     *                   nevolá prohlížeč (server → server s vlastním tokenem
     *                   v hlavičce). CSRF chrání před tím, aby cizí stránka
     *                   zneužila přihlášenou session; kde žádná session není,
     *                   nemá co chránit — zato by token neměl kdo poslat.
     */
    public function add(
        string $method,
        string $path,
        array $handler,
        ?string $capability = null,
        string $name = '',
        bool $csrf = true,
    ): void {
        $method = strtoupper($method);
        $mutating = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if ($mutating && $capability === null) {
            throw new LogicException(
                "Mutační routa {$method} {$path} musí deklarovat oprávnění "
                . '(capability, Router::AUTH_ONLY nebo Router::PUBLIC_ACCESS).'
            );
        }

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'regex' => $this->compile($path),
            'handler' => $handler,
            'capability' => $capability ?? self::AUTH_ONLY,
            'name' => $name,
            'csrf' => $csrf,
        ];
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, capability: string, params: array<string, string>, name: string, csrf: bool}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        $pathMatchedOtherMethod = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $pathMatchedOtherMethod = true;
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return [
                'handler' => $route['handler'],
                'capability' => $route['capability'],
                'params' => $params,
                'name' => $route['name'],
                'csrf' => $route['csrf'],
            ];
        }

        if ($pathMatchedOtherMethod) {
            throw new HttpException(405, 'Tato metoda není pro danou adresu povolena.');
        }

        return null;
    }

    /** @return array<int, array{method: string, path: string, capability: string, name: string}> */
    public function all(): array
    {
        return array_map(
            static fn (array $r): array => [
                'method' => $r['method'],
                'path' => $r['path'],
                'capability' => $r['capability'],
                'name' => $r['name'],
            ],
            $this->routes,
        );
    }

    /**
     * `/uzivatele/{id}` → regex s pojmenovanou skupinou `id`.
     *
     * Cesta se zpracovává po segmentech: parametr je vždy celý segment, zbytek
     * se escapuje. (Escapovat celou cestu najednou nejde — preg_quote escapuje
     * i složené závorky, takže by se parametr nikdy nerozpoznal.)
     */
    private function compile(string $path): string
    {
        $segments = explode('/', '/' . trim($path, '/'));

        $compiled = array_map(
            static function (string $segment): string {
                if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                    return '(?P<' . $matches[1] . '>[^/]+)';
                }

                return preg_quote($segment, '#');
            },
            $segments,
        );

        return '#^' . implode('/', $compiled) . '$#';
    }
}
