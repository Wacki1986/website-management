<?php

declare(strict_types=1);

/**
 * Globální funkce k adresám.
 *
 * Konvence a pravidla o escapování popisuje `app/helpers/markup.php` — platí
 * pro všechny soubory s pomocníky stejně.
 *
 * Vazbu na generátor adres drží `App\Core\View\Urls`; proč zvlášť a proč
 * uzávěrami je napsané tam.
 */

use App\Core\View\Urls;

/**
 * Adresa v aplikaci, **escapovaná pro vložení do atributu**.
 *
 * ```php
 * <a href="<?= get_url('zaznamy/' . $id . '/upravit') ?>">Upravit</a>
 * ```
 *
 * Základní cestu instance (aplikace může běžet v podadresáři) doplní
 * `Kernel::url()`; absolutní adresa (`https://…`) projde beze změny.
 *
 * **Nepoužívat tam, kde adresa jde do pole pro jiného pomocníka** — akce
 * v `row-menu`, `action` v `create-popover`, `link` v `notice`. Ti si ji
 * escapují sami a tady by se escapovala dvakrát. Pro ně zůstává
 * `$kernel->url()`.
 */
function get_url(string $path = '/'): string
{
    return htmlspecialchars(Urls::url($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Adresa, ze které se sem přišlo — escapovaná, nebo `null`.
 *
 * Slouží tlačítku „Zpět" na chybových stránkách. Obvyklé `history.back()`
 * tady nejde použít: **ovládací prvek, který bez JavaScriptu nic nedělá, se
 * nemá vykreslit** a odkaz na předchozí adresu funguje i bez něj.
 *
 * Cizí adresa se zahodí. Hlavičku `Referer` posílá prohlížeč a dá se do ní
 * napsat cokoli — odkaz „Zpět" mířící ven z aplikace by byl past. Ze stejného
 * důvodu se přijímá jen cesta, ne celá adresa i s doménou.
 */
function back_url(): ?string
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer === '') {
        return null;
    }

    $host = parse_url($referer, PHP_URL_HOST);
    if ($host !== null && $host !== ($_SERVER['HTTP_HOST'] ?? '')) {
        return null;
    }

    $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
    $query = (string) (parse_url($referer, PHP_URL_QUERY) ?? '');

    return htmlspecialchars($path . ($query !== '' ? '?' . $query : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Adresa statického souboru s otiskem podle času změny, escapovaná.
 *
 * Otisk řeší `Kernel::asset()` — bez něj drží prohlížeč starý CSS i po
 * nasazení. Firemní soubory (`company/assets/`) se poznají podle prefixu.
 */
function get_asset(string $path): string
{
    return htmlspecialchars(Urls::asset($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
