<?php

declare(strict_types=1);

namespace App\Core\Views;

use App\Core\Http\Request;

/**
 * Stránkování výpisu — **přenesené z klientské aplikace** (tam `Criteria`
 * + `TablePresenter::pagination()/pageSteps()/perPageOptions()`).
 *
 * Správa má jediný dlouhý výpis (Historie zásahů), takže filtr a řazení
 * zůstávají věcí controlleru a tahle třída řeší jen stránku: parametry
 * `strana` a `na_strance` v adrese (stejné názvy jako v aplikaci), výčet
 * odkazů se zkrácením `1 … 7 8 9 … 15`, šipky a volbu velikosti stránky.
 *
 * Zvolená velikost se **pamatuje v session**: kdo si jednou přepne na 100,
 * má 100 i po návratu z detailu. Aplikace si ji ukládá k uživateli do
 * databáze; správa má pár správců a session stačí — žádná migrace kvůli
 * jednomu číslu.
 */
final class Pagination
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const PER_PAGE_DEFAULT = 25;

    private const SESSION_KEY = 'per_page';

    /**
     * @param array<string, scalar|null> $query ostatní parametry adresy (filtry), které se mají zachovat
     */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        private readonly string $baseUrl,
        private readonly array $query = [],
    ) {
    }

    /**
     * Z requestu: `strana` (výchozí 1), `na_strance` (jen povolené hodnoty;
     * neplatná se tiše nahradí zapamatovanou nebo výchozí).
     *
     * @param array<string, scalar|null> $query
     */
    public static function fromRequest(Request $request, int $total, string $baseUrl, array $query = []): self
    {
        $requested = $request->int('na_strance');

        if ($requested !== null && in_array($requested, self::PER_PAGE_OPTIONS, true)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[self::SESSION_KEY] = $requested;
            }

            $perPage = $requested;
        } else {
            $stored = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION[self::SESSION_KEY] ?? null) : null;
            $perPage = is_int($stored) && in_array($stored, self::PER_PAGE_OPTIONS, true)
                ? $stored
                : self::PER_PAGE_DEFAULT;
        }

        $pageCount = max(1, (int) ceil($total / $perPage));

        // Stránka za koncem (smazané záznamy, starý odkaz) → poslední, ne prázdná.
        $page = max(1, min((int) $request->int('strana', 1), $pageCount));

        return new self($page, $perPage, $total, $baseUrl, $query);
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** První zobrazený záznam (1-based), 0 u prázdného výpisu. */
    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    /**
     * Odkazy na stránky — zkrácený výčet: první, aktuální ± `$window`,
     * poslední; vynechané nahradí jedna mezera `['gap' => true]`.
     *
     * @return array<int, array{gap: bool, page?: int, url?: string, current?: bool}>
     */
    public function links(int $window = 1): array
    {
        $pageCount = $this->pageCount();

        if ($pageCount <= 1) {
            return [];
        }

        $links = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $keep = $page === 1 || $page === $pageCount || abs($page - $this->page) <= $window;

            if (!$keep) {
                if (($links[count($links) - 1]['gap'] ?? false) === false) {
                    $links[] = ['gap' => true];
                }

                continue;
            }

            $links[] = [
                'gap' => false,
                'page' => $page,
                'url' => $this->pageUrl($page),
                'current' => $page === $this->page,
            ];
        }

        return $links;
    }

    /**
     * Adresa předchozí a další stránky — `null`, když už není kam.
     *
     * @return array{prev: ?string, next: ?string}
     */
    public function steps(): array
    {
        return [
            'prev' => $this->page > 1 ? $this->pageUrl($this->page - 1) : null,
            'next' => $this->page < $this->pageCount() ? $this->pageUrl($this->page + 1) : null,
        ];
    }

    /**
     * Volby velikosti stránky. Hodnota se do adresy píše vždy, i výchozí —
     * bez parametru by se odkaz vrátil k zapamatované volbě a přepnout
     * zpět na 25 by nešlo.
     *
     * @return array<int, array{value: int, url: string, current: bool}>
     */
    public function perPageOptions(): array
    {
        $options = [];

        foreach (self::PER_PAGE_OPTIONS as $value) {
            $options[] = [
                'value' => $value,
                'url' => $this->url(['na_strance' => $value, 'strana' => null]),
                'current' => $value === $this->perPage,
            ];
        }

        return $options;
    }

    private function pageUrl(int $page): string
    {
        return $this->url(['strana' => $page > 1 ? $page : null]);
    }

    /**
     * Adresa s filtry a zadanými změnami; `null` parametr vypadne.
     *
     * @param array<string, scalar|null> $changes
     */
    private function url(array $changes): string
    {
        $params = array_filter(
            array_merge($this->query, $changes),
            static fn ($value): bool => $value !== null && $value !== '',
        );

        return $params === [] ? $this->baseUrl : $this->baseUrl . '?' . http_build_query($params);
    }
}
