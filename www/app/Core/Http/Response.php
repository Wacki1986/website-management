<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Odpověď HTTP — tělo, stav a hlavičky.
 *
 * Controllery ji **vracejí, neodesílají**: díky tomu jde odpověď v jádru ještě
 * změnit (bezpečnostní hlavičky, režim údržby) a v testu se dá zkontrolovat,
 * aniž by se cokoli poslalo do prohlížeče. `send()` volá jen `Kernel`.
 */
final class Response
{
    /**
     * Tělo, které se vyrábí až při odesílání.
     *
     * @var (callable(): void)|null
     */
    private $stream = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    /**
     * Odpověď, jejíž tělo se vypisuje po kusech.
     *
     * Kvůli zálohám: rozšifrovaný dump má desítky megabajtů a načíst ho do
     * řetězce by na sdíleném hostingu narazilo na `memory_limit`. Callback
     * dostane slovo až ve chvíli odesílání a píše rovnou na výstup.
     *
     * Pozor: u streamované odpovědi **nejde zpětně změnit stav ani hlavičky**
     * poté, co se začne psát. Callback proto nesmí házet výjimky — musí si
     * chyby ošetřit sám, nebo se do těla dostane půlka souboru a půlka
     * chybové stránky.
     *
     * @param callable(): void $writer
     * @param array<string, string> $headers
     */
    public static function stream(callable $writer, array $headers = [], int $status = 200): self
    {
        $response = new self('', $status, $headers);
        $response->stream = $writer;

        return $response;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self($encoded === false ? '{}' : $encoded, $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Doplní hlavičku k hotové odpovědi.
     *
     * Pro případy, kdy odpověď vyrobil pomocník (`Controller::view()`)
     * a volající k ní má co dodat — třeba `Vary` u stránky, která se podle
     * hlavičky umí vrátit i po kusech.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->stream !== null) {
            ($this->stream)();

            return;
        }

        echo $this->body;
    }
}
