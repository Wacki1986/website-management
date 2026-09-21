<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/**
 * Chyba, která má přímý překlad do HTTP stavu.
 *
 * Validační chyby nesou pole `errors` (klíč pole => hláška), aby je formulář
 * mohl vypsat u konkrétního inputu.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function notFound(string $message = 'Stránka nebyla nalezena.'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'K této akci nemáte oprávnění.'): self
    {
        return new self(403, $message);
    }

    public static function unauthorized(string $message = 'Přihlaste se prosím.'): self
    {
        return new self(401, $message);
    }

    /** @param array<string, string> $errors */
    public static function validation(string $message, array $errors = []): self
    {
        return new self(422, $message, $errors);
    }

    public static function tooManyRequests(string $message = 'Příliš mnoho pokusů. Zkuste to za chvíli.'): self
    {
        return new self(429, $message);
    }
}
