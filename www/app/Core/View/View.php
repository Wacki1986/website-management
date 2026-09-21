<?php

declare(strict_types=1);

namespace App\Core\View;

use RuntimeException;

/**
 * Šablony jsou obyčejné PHP soubory.
 *
 * Pravidlo: veškerý výstup jde přes $this->e() — helper raw() existuje jen pro
 * případy, kdy se HTML vyrábí záměrně, a musí u něj být komentář proč.
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var array<string, string> prefix => adresář se šablonami modulu */
    private array $paths = [];

    private ?string $layout = null;

    /** @var array<string, mixed> */
    private array $layoutData = [];

    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * Šablony modulu leží u modulu, ne v jádru.
     *
     * `addPath('entries', __DIR__ . '/templates')` způsobí, že se
     * `render('entries/index')` hledá v adresáři modulu.
     */
    public function addPath(string $prefix, string $directory): void
    {
        $this->paths[trim($prefix, '/')] = rtrim($directory, '/\\');
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Pomocník na formulářová pole.
     *
     * Zkratka, aby šablona nemusela importovat třídu jen kvůli jednomu řádku:
     * `$form = $this->form($errors ?? []);`
     *
     * Nesdílí se jako proměnná, protože chyby a autofocus jsou pro každou
     * stránku jiné — sdílená instance by je nesla z předchozího vykreslení.
     *
     * @param array<string, string> $errors klíč pole => hláška
     */
    public function form(array $errors = [], ?string $autofocus = null): Form
    {
        // Bez určení skočí kurzor do prvního chybného pole — jinak ho uživatel
        // po odeslání formuláře musí hledat sám.
        return new Form($errors, $autofocus ?? (array_key_first($errors) ?: null));
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $this->layout = null;
        $this->layoutData = [];

        $content = $this->capture($template, $data);

        if ($this->layout === null) {
            return $content;
        }

        $layout = $this->layout;
        $layoutData = array_merge($this->layoutData, $data, ['content' => $content]);
        $this->layout = null;

        return $this->capture($layout, $layoutData);
    }

    /** Volá se ze šablony: `$this->extend('layout/shell', ['title' => 'Uživatelé'])`. */
    public function extend(string $layout, array $data = []): void
    {
        $this->layout = $layout;
        $this->layoutData = $data;
    }

    /** Vloží dílčí šablonu (partial) bez layoutu. */
    public function partial(string $template, array $data = []): string
    {
        $savedLayout = $this->layout;
        $savedData = $this->layoutData;

        $output = $this->capture($template, $data);

        $this->layout = $savedLayout;
        $this->layoutData = $savedData;

        return $output;
    }

    public function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Data pro inline <script> — bezpečné vůči rozbití kontextu značkou. */
    public function json(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
        );

        return $encoded === false ? 'null' : $encoded;
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->resolve($template);

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Šablona "%s" neexistuje.', $template));
        }

        $vars = array_merge($this->shared, $data);

        ob_start();
        (function () use ($file, $vars): void {
            extract($vars, EXTR_SKIP);
            require $file;
        })();

        return (string) ob_get_clean();
    }

    /** Šablona modulu (`zaznamy/index`) má přednost před stejně nazvanou v jádru. */
    private function resolve(string $template): string
    {
        $template = ltrim($template, '/');
        $prefix = strstr($template, '/', true);

        if ($prefix !== false && isset($this->paths[$prefix])) {
            $candidate = $this->paths[$prefix] . '/' . substr($template, strlen($prefix) + 1) . '.php';

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $this->templateDir . '/' . $template . '.php';
    }
}
