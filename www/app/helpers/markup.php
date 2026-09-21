<?php

declare(strict_types=1);

/**
 * Globální funkce pro opakované značky v šablonách.
 *
 * Proč globální funkce a ne partialy: ikona, stavová tečka nebo odznak se
 * v šablonách píšou pořád a `$this->partial('partials/…', ['klíč' => …])`
 * má dvě nevýhody — editor nenapoví názvy klíčů a překlep se tiše ignoruje.
 * Typované parametry obojí řeší a zápis je kratší.
 *
 * ## Konvence, která platí bez výjimky
 *
 * - `render_*` vypisuje (echo) a vrací `void`
 * - `get_*` vrací řetězec a nic nevypisuje
 *
 * ## Escapování
 *
 * Řeší si ho funkce samy uvnitř — v šablonách po nich nesmí zůstat ruční
 * `$this->e()`. Parametry, které naopak **přijímají hotové HTML**, to mají
 * napsané u sebe v docblocku.
 *
 * Třídy odpovídají design systému v `dev/design` (BEM, viz README balíčku).
 */

use App\Core\View\View;
use App\Core\Views\Format;

/**
 * Číslo se správným tvarem podstatného jména — `get_count(4, 'web', 'weby', 'webů')`.
 * Nula bere tvar množný („0 webů“), stejně jako pět a víc.
 */
function get_count(int $count, string $one, string $few, string $many): string
{
    return str_replace(' ', "\u{00A0}", number_format($count, 0, ',', ' ')) . "\u{00A0}" . get_plural($count, $one, $few, $many);
}

/** Jen tvar slova bez čísla — pro případy, kdy číslo stojí ve vlastní značce. */
function get_plural(int $count, string $one, string $few, string $many): string
{
    return match (true) {
        $count === 1 => $one,
        $count >= 2 && $count <= 4 => $few,
        default => $many,
    };
}

/**
 * Ikona z tokenů `--icon-*` (masky v `abstracts/_icons.scss`).
 *
 * Design píše masku inline ke každé ikoně; tady je to jedno místo.
 * `$class` doplňuje modifikátory: `icon--sm`, `icon--lg`, `icon--subtle`,
 * `icon--warning`, `icon--error`, `icon--brand`.
 */
function get_icon(string $name, string $class = ''): string
{
    $token = 'var(--icon-' . preg_replace('/[^a-z0-9-]/', '', $name) . ')';

    return sprintf(
        '<span class="%s" style="-webkit-mask:%2$s center/contain no-repeat;mask:%2$s center/contain no-repeat" aria-hidden="true"></span>',
        Format::plain(trim('icon ' . $class)),
        $token,
    );
}

/** Ikona uvnitř tlačítka (`.btn__icon`) — před textem tlačítka. */
function get_btn_icon(string $name): string
{
    $token = 'var(--icon-' . preg_replace('/[^a-z0-9-]/', '', $name) . ')';

    return sprintf(
        '<span class="btn__icon" style="-webkit-mask:%1$s center/contain no-repeat;mask:%1$s center/contain no-repeat" aria-hidden="true"></span>',
        $token,
    );
}

/**
 * Semafor: tečka + text. Stav se nikdy nesděluje jen barvou (STAVY.md).
 *
 * @param string $tone ok | warning | error | muted
 */
function get_status(string $tone, string $text): string
{
    $tone = in_array($tone, ['ok', 'warning', 'error', 'muted'], true) ? $tone : 'muted';

    return sprintf(
        '<span class="status status--%s"><span class="status__dot"></span>%s</span>',
        $tone,
        Format::plain($text),
    );
}

function render_status(string $tone, string $text): void
{
    echo get_status($tone, $text);
}

/** Samostatná stavová tečka bez textu (historie, monitor v menu). */
function get_dot(string $tone): string
{
    $tone = in_array($tone, ['ok', 'warning', 'error', 'muted'], true) ? $tone : 'muted';

    return '<span class="dot dot--' . $tone . '" aria-hidden="true"></span>';
}

/**
 * Odznak — pilulka s textem (počet aktualizací, role, štítek u záložky).
 *
 * @param string $tone '' | warning | error | ok | brand
 */
function get_badge(string $text, string $tone = ''): string
{
    $tone = in_array($tone, ['warning', 'error', 'ok', 'brand'], true) ? ' badge--' . $tone : '';

    return '<span class="badge' . $tone . '">' . Format::plain($text) . '</span>';
}

/**
 * Kolečko s iniciálami — web, klient nebo uživatel v tabulce.
 *
 * Barva je odvozená z `$seed` (typicky název), takže tentýž web má všude
 * stejnou barvu, aniž by se kam ukládala. Paleta sedí k návrhu (tmavší
 * syté tóny, na kterých je bílý text čitelný).
 *
 * @param string $size '' | sm
 */
function get_avatar(string $name, string $seed = '', string $size = ''): string
{
    $palette = ['#8A6A1E', '#7C3A1D', '#1F4E79', '#2F6F5E', '#4A4A8A', '#1E293B', '#3F6212', '#C2410C', '#9D174D', '#A0522D', '#6B4F2A', '#0F766E'];
    $seed = $seed !== '' ? $seed : $name;
    $color = $palette[hexdec(substr(md5($seed), 0, 2)) % count($palette)];

    $words = preg_split('/[\s\-–.]+/u', trim($name)) ?: [];
    $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
    $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1) . mb_substr($words[1] ?? '', 0, 1));

    return sprintf(
        '<span class="avatar%s" style="background:%s" title="%s" aria-hidden="true">%s</span>',
        $size === 'sm' ? ' avatar--sm' : '',
        $color,
        Format::plain($name),
        Format::plain($initials),
    );
}

/**
 * Prázdný stav tabulky nebo seznamu (`.empty` z návrhu).
 *
 * @param string $actions hotové HTML tlačítek pod textem (nebo prázdné)
 */
function render_empty(string $title, string $text, string $icon = 'search', string $actions = ''): void
{
    printf(
        '<div class="empty">%s<div class="empty__title">%s</div><div class="empty__text">%s</div>%s</div>',
        get_icon($icon, 'empty__icon'),
        Format::plain($title),
        Format::plain($text),
        $actions !== '' ? '<div class="row" style="justify-content:center;margin-top:6px">' . $actions . '</div>' : '',
    );
}

/**
 * Hláška v obsahu stránky — pás `.alert.alert--row` z návrhu.
 *
 * Na rozdíl od toastu zůstává, dokud je relevantní (chyba formuláře,
 * potvrzení po uložení). Typy: success | error | warning | info.
 *
 * @param array<int, string> $list výčet pod textem (typicky chyby formuláře)
 */
function render_notice(
    View $view,
    string $type,
    string $title = '',
    string $message = '',
    array $list = [],
    string $linkUrl = '',
    string $linkLabel = '',
): void {
    echo $view->partial('partials/notice', [
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'list' => $list,
        'link' => $linkUrl !== '' ? ['url' => $linkUrl, 'label' => $linkLabel] : null,
    ]);
}

/**
 * Hlavička karty — nadpis, poznámka a akce vpravo (`.card__header`).
 *
 * @param string $actions hotové HTML (tlačítka, odkaz „Zobrazit vše")
 */
function render_card_head(string $title, string $note = '', string $actions = ''): void
{
    printf(
        '<div class="card__header"><div><h2 class="card__title">%s</h2>%s</div>%s</div>',
        Format::plain($title),
        $note !== '' ? '<div class="card__note">' . Format::plain($note) . '</div>' : '',
        $actions,
    );
}

/**
 * Hlavička stránky seznamu (`.page-header--list`): nadpis, meta řádek
 * a akce vpravo. Detail webu má vlastní partial `partials/site-header`.
 *
 * @param string $meta    hotové HTML meta řádku (spočítané v controlleru)
 * @param string $actions hotové HTML tlačítek
 * @param string $tabs    hotové HTML záložek (`get_tabs()`), nebo prázdné
 */
function render_page_head(string $title, string $meta = '', string $actions = '', string $tabs = ''): void
{
    printf(
        '<header class="page-header page-header--list"><div class="page-header__top"><div><h1 class="page-header__title">%s</h1>%s</div>%s</div>%s</header>',
        Format::plain($title),
        $meta !== '' ? '<div class="page-header__meta">' . $meta . '</div>' : '',
        $actions !== '' ? '<div class="page-header__actions">' . $actions . '</div>' : '',
        $tabs,
    );
}

/**
 * Záložky jako odkazy na vlastní URL (`.tabs`) — fungují bez JavaScriptu
 * a každá záložka se dá odkázat.
 *
 * @param array<int, array{key: string, label: string, url: string, badge?: string}> $tabs `badge` je hotové HTML
 */
function get_tabs(array $tabs, string $active): string
{
    $html = '<nav class="tabs">';

    foreach ($tabs as $tab) {
        $isActive = $tab['key'] === $active;
        $html .= sprintf(
            '<a class="tabs__item%s" href="%s"%s>%s%s</a>',
            $isActive ? ' tabs__item--active' : '',
            Format::plain($tab['url']),
            $isActive ? ' aria-current="page"' : '',
            Format::plain($tab['label']),
            isset($tab['badge']) && $tab['badge'] !== '' ? ' ' . $tab['badge'] : '',
        );
    }

    return $html . '</nav>';
}

/**
 * Přepínač zapnuto/vypnuto pro formulář bez JavaScriptu.
 *
 * Návrh má `.toggle` jako tlačítko; tady je to `<label>` se skrytým
 * zaškrtávátkem, stav kreslí CSS přes `:has(:checked)`. Odesílá se jako
 * obyčejné pole formuláře — nic se neděje bez uložení.
 */
function get_toggle(string $name, bool $on, string $label = ''): string
{
    return sprintf(
        '<label class="toggle%s"><input class="toggle__input" type="checkbox" name="%s" value="1"%s aria-label="%s"><span class="toggle__knob"></span></label>',
        $on ? ' toggle--on' : '',
        Format::plain($name),
        $on ? ' checked' : '',
        Format::plain($label),
    );
}
