<?php

declare(strict_types=1);

/**
 * Globální funkce k formulářům.
 *
 * Konvence a pravidla o escapování popisuje `app/helpers/markup.php` — platí
 * pro všechny soubory s pomocníky stejně.
 *
 * Jednotlivá **pole** tenhle soubor neřeší: ta kreslí `App\Core\View\Form`
 * (`$form->text()`, `$form->select()`…) a je to tak správně. Tady je jen to,
 * co pole obklopuje — token, hláška o chybách, formulář nesoucí jednu akci.
 */

use App\Core\View\View;
use App\Core\Views\Format;

/**
 * Klíč prvního pole s chybou — kam skočí kurzor po odeslání formuláře.
 *
 * Klíč `_` se přeskakuje: je to chyba celého formuláře, ne pole, a kurzor
 * by neměl kam skočit. Tenhle výraz byl opsaný ve třech formulářích.
 *
 * ```php
 * $form = $this->form($errors, get_first_error($errors) ?? 'customer_name');
 * ```
 *
 * @param array<string, string> $errors klíč pole => hláška
 */
function get_first_error(array $errors): ?string
{
    return array_key_first(array_diff_key($errors, ['_' => null]));
}

// `get_user_options()` z klientské aplikace tu není: opírala se o
// `Users\UserRepository::options()`, kterou správa nemá — uživatel je jeden
// a žádný <select> osob se nikde nekreslí.

/**
 * Nabídka, kde je klíč i popisek tatáž hodnota.
 *
 * Pro číselníky a pole navíc: do záznamu se ukládá samotná hodnota, ne id.
 * `array_combine($x, $x) ?: []` byla čtyřikrát opsaná hádanka — `?: []` je
 * tam kvůli prázdnému seznamu, u kterého `array_combine()` vrací prázdné
 * pole, jež je `false`-ové.
 *
 * @param array<int, string> $values
 * @return array<string, string>
 */
function get_self_options(array $values): array
{
    return array_combine($values, $values) ?: [];
}

/**
 * Skryté pole s CSRF tokenem.
 *
 * Bylo opsané na 49 místech ve 30 šablonách, vždy doslova stejně. Řádků to
 * neubere — jde o to, že `name="_token"` ani `$csrfToken` už nejde přepsat
 * ani zapomenout. Kontrola je centrální (`Kernel::handle`), takže chybějící
 * token se pozná až odmítnutým formulářem: hláškou „Platnost formuláře
 * vypršela" na akci, která je v pořádku.
 *
 * Pozor, **netýká se systémových tokenů** — `system/setup` a `system/migrate`
 * posílají `name="token"` z `config/env.php`, což je něco jiného a zůstává
 * napsané ručně.
 */
function get_csrf(string $token): string
{
    return sprintf('<input type="hidden" name="_token" value="%s">', Format::plain($token));
}

function render_csrf(string $token): void
{
    echo get_csrf($token);
}

/**
 * Formulář, který nese jen akci a token — pro tlačítka mimo něj.
 *
 * Vzniká proto, že formuláře nejdou vnořovat: lišta `form-actions-bar` stojí
 * mimo formulář a tlačítka se k němu hlásí atributem `form="…"`. Osm takových
 * formulářů v šablonách bylo doslova stejných až na `data-confirm`.
 *
 * **Odchylky od původního HTML** (obojí bez vlivu na vzhled i chování):
 *
 * - Atributy i vnořený vstup jdou na jednom řádku. Osm původních míst mělo
 *   dvě různá odsazení a jedno z nich mezi atributy i komentář, takže se
 *   bílé znaky zachovat nedají.
 * - `nastaveni/ciselniky` mělo `id` před `method`; tady je pořadí jednotné
 *   (`method`, `id`, `data-confirm`, `action`).
 *
 * @param string $id      hodnota `id`, na kterou se odkazuje `form="…"` u tlačítka
 * @param string $url     cíl formuláře (adresa už poskládaná volajícím)
 * @param string $confirm text potvrzení; prázdný = potvrzovat se nebude
 */
function render_action_form(string $id, string $url, string $token, string $confirm = ''): void
{
    // `action-form` je `display: contents` — formulář nese jen skryté pole
    // a tlačítko na něj míří zvenčí přes `form="id"`. Bez toho by prázdný
    // prvek zabral místo v rytmu stránky (`gap`) a udělal díru pod obsahem.
    printf(
        '<form class="action-form" method="post" id="%s"%s action="%s">%s</form>',
        Format::plain($id),
        $confirm !== '' ? sprintf(' data-confirm="%s"', Format::plain($confirm)) : '',
        Format::plain($url),
        get_csrf($token),
    );
}

/**
 * Hláška „něco se neuložilo" nad formulářem, s výčtem chyb.
 *
 * Dřív byl tenhle blok o osmi řádcích opsaný v pěti šablonách
 * (profil, uživatel, nepřítomnost, záznam, docházka) a lišil se jen nadpisem.
 *
 * Věta pod nadpisem je **záměrně stejná pro všechny počty chyb**. Formulář
 * záznamu ji dřív skloňoval podle počtu („tuto položku" / „tyto položky");
 * sjednocení do neutrálního tvaru je jediná změna textu, kterou tahle funkce
 * přinesla, a je vědomá — skloňování se nikam jinam nerozšířilo.
 *
 * Prázdné pole chyb nevykreslí nic, takže se dá volat i bez podmínky.
 *
 * @param array<string, string> $errors klíč pole => hláška
 * @param string $title nadpis hlášky („Změny se neuložily", „Záznam se neuložil")
 */
function render_form_errors(View $view, array $errors, string $title): void
{
    if ($errors === []) {
        return;
    }

    // Přes render_notice(), ne přímo přes partial — s dílčí šablonou hlášky
    // mluví jedno místo, takže se změna jejího rozhraní řeší taky jen na jednom.
    render_notice(
        $view,
        'error',
        $title,
        'Opravte prosím označené položky. Ostatní vyplněné údaje zůstaly zachované.',
        array_values($errors),
    );
}
