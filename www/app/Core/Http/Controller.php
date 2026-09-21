<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Kernel;

/**
 * Základ controllerů — přístup ke Kernelu a pár zkratek.
 *
 * Oproti jádru klientské aplikace chybí druhá vrstva autorizace
 * (`requireCap()`): správa nemá role ani capabilities, autorizace je jen
 * „přihlášen / nepřihlášen" a tu řeší deklarace na routě.
 */
abstract class Controller
{
    public function __construct(protected readonly Kernel $kernel)
    {
    }

    /**
     * Odepřený přístup nikdy nepatří zpátky do formuláře — viz jádro.
     * Volá se na začátku catch bloků, které chytají validační chyby.
     */
    protected function rethrowIfForbidden(HttpException $e): void
    {
        if ($e->status() === 403) {
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->kernel->view()->render($template, $data), $status);
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($this->kernel->url($path));
    }

    /** Přesměrování s jednorázovou hláškou (zobrazí se jako toast po načtení). */
    protected function redirectWithFlash(string $path, string $message, string $type = 'success'): Response
    {
        $this->kernel->flash($message, $type);

        return $this->redirect($path);
    }

    protected function request(): Request
    {
        return $this->kernel->request();
    }
}
