<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Views\Pagination;

/**
 * Stránkování Historie zásahů — přenesené z aplikace, takže musí dávat
 * stejné výsledky: zkrácený výčet s mezerami, šipky, volby 25/50/100,
 * filtry v odkazech a stránka za koncem srovnaná na poslední.
 */
// Velikost stránky se pamatuje v session (a tu nastartoval některý z dřívějších
// testů) — každý případ začíná bez paměti, jinak by si předávaly „100".
$request = static function (array $query): Request {
    unset($_SESSION['per_page']);

    return new Request('GET', '/historie', $query);
};

return [
    'výchozí je 25 na stránku a první strana' => function () use ($request): void {
        $p = Pagination::fromRequest($request([]), 0, '/historie');

        assertSame(25, $p->perPage);
        assertSame(1, $p->page);
        assertSame(0, $p->from());
        assertSame(0, $p->to());
        assertSame([], $p->links());
    },

    'neplatná velikost stránky spadne na výchozí' => function () use ($request): void {
        $p = Pagination::fromRequest($request(['na_strance' => '7']), 300, '/historie');

        assertSame(25, $p->perPage);
    },

    'povolené velikosti jsou 25, 50 a 100' => function () use ($request): void {
        assertSame([25, 50, 100], Pagination::PER_PAGE_OPTIONS);

        $p = Pagination::fromRequest($request(['na_strance' => '100']), 300, '/historie');
        assertSame(100, $p->perPage);
        assertSame(3, $p->pageCount());
    },

    'zkrácený výčet: první, aktuální ± 1, poslední, mezery' => function () use ($request): void {
        $p = Pagination::fromRequest($request(['strana' => '8']), 15 * 25, '/historie');
        $pages = array_map(static fn (array $l) => $l['gap'] ? '…' : $l['page'], $p->links());

        assertSame([1, '…', 7, 8, 9, '…', 15], $pages);
    },

    'rozsah a šipky' => function () use ($request): void {
        $p = Pagination::fromRequest($request(['strana' => '2']), 60, '/historie');

        assertSame(26, $p->from());
        assertSame(50, $p->to());
        assertSame('/historie', $p->steps()['prev']);
        assertSame('/historie?strana=3', $p->steps()['next']);

        $last = Pagination::fromRequest($request(['strana' => '3']), 60, '/historie');
        assertSame(null, $last->steps()['next']);
    },

    'stránka za koncem se srovná na poslední' => function () use ($request): void {
        $p = Pagination::fromRequest($request(['strana' => '99']), 60, '/historie');

        assertSame(3, $p->page);
    },

    'filtry zůstávají v odkazech, prázdné vypadnou' => function () use ($request): void {
        $p = Pagination::fromRequest($request(['strana' => '2']), 120, '/historie', ['instance' => 4, 'user' => null]);

        assertSame('/historie?instance=4&strana=3', $p->steps()['next']);

        $options = $p->perPageOptions();
        // Velikost se píše vždy, stránka se při přepnutí nuluje.
        assertSame('/historie?instance=4&na_strance=25', $options[0]['url']);
        assertTrue($options[0]['current']);
        assertSame('/historie?instance=4&na_strance=100', $options[2]['url']);
    },
];
