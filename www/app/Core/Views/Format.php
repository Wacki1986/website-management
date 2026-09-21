<?php

declare(strict_types=1);

namespace App\Core\Views;

/**
 * Formátovače hodnot do tabulky.
 *
 * Doména sem nepatří — jsou to obecné převody, které potřebuje každý modul.
 * **Každá metoda vrací escapované HTML**, protože výstup se do šablony vypisuje
 * bez dalšího escapování.
 *
 * Formátování telefonu a ceny je převzaté ze staré aplikace: fungovalo,
 * uživatelé jsou na ten tvar zvyklí a není důvod ho měnit.
 */
final class Format
{
    /**
     * Prázdná hodnota — pomlčka, ne prázdno.
     *
     * Prázdná buňka nechává čtenáře hádat, jestli se pole nevyplnilo, nebo se
     * jen nevešlo. Dřív byla tahle značka v téhle třídě opsaná
     * **šestkrát**; sjednotila se, protože se podle ní pozná prázdný řádek
     * i jinde: mobilní karta ho vynechává celý (viz `TablePresenter`).
     */
    public const EMPTY = '<span class="text-faint">—</span>';

    /** Dny v týdnu — `strftime()` je od PHP 8.1 zastaralé a `IntlDateFormatter` nemusí být. */
    private const DAYS = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];

    /**
     * Den v týdnu a datum — „sobota 8. 8." nebo „sobota 8. 8. 2026".
     *
     * **Vrací holý text**, ne HTML, na rozdíl od zbytku téhle třídy: obojí
     * místo, kde se používá (pozdrav na nástěnce, mobilní lišta), ho sází
     * verzálkami přes CSS a předává si ho dál jako popisek.
     *
     * Rok se vypisuje jen tam, kde je místo. Na telefonu je hlavička úzká
     * a „která je zrovna sobota" nikdo v aplikaci neřeší.
     */
    public static function weekday(bool $withYear = false, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return self::DAYS[(int) date('w', $timestamp)]
            . ' ' . date($withYear ? 'j. n. Y' : 'j. n.', $timestamp);
    }

    public static function text(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY;
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Prostý text bez náhrady za pomlčku (pro atributy a titulky). */
    public static function plain(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function date(mixed $value, bool $withTime = true): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return '<span class="text-faint">bez termínu</span>';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return self::text($value);
        }

        return htmlspecialchars(
            date($withTime ? 'j. n. Y H:i' : 'j. n. Y', $timestamp),
            ENT_QUOTES,
            'UTF-8',
        );
    }

    /**
     * „dnes 09:41", „včera 17:50", „5. 8. 14:23" — čas vzhledem k dnešku.
     *
     * Pro seznamy toho, co se právě děje (auditní log, fronta pošty). Plné
     * datum by tam bylo přesnější a hůř čitelné: u řádku starého dvě hodiny
     * nikoho nezajímá rok.
     *
     * Vrací **holý text**, ne HTML — volající si ho escapuje sám podle toho,
     * kam ho staví.
     */
    public static function relativeDay(mixed $value): string
    {
        $timestamp = strtotime((string) ($value ?? ''));

        if ($timestamp === false) {
            return '—';
        }

        $day = date('Y-m-d', $timestamp);

        return match ($day) {
            date('Y-m-d') => 'dnes ' . date('H:i', $timestamp),
            date('Y-m-d', strtotime('-1 day')) => 'včera ' . date('H:i', $timestamp),
            default => date('j. n. H:i', $timestamp),
        };
    }

    /** „12 345 Kč" */
    public static function price(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY;
        }

        return htmlspecialchars(
            number_format((float) $value, 0, ',', ' ') . ' Kč',
            ENT_QUOTES,
            'UTF-8',
        );
    }

    /** Telefon jako odkaz `tel:`, zobrazený ve tvaru +420 xxx xxx xxx. */
    public static function phone(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            return self::EMPTY;
        }

        $national = self::national($raw);

        if (strlen($national) >= 11 && strlen($national) <= 12) {
            $prefix = substr($national, 0, strlen($national) - 9);
            $rest = substr($national, -9);
            $display = '+' . $prefix . ' ' . implode(' ', str_split($rest, 3));
        } else {
            $display = $raw;
        }

        return sprintf(
            '<a href="tel:%s">%s</a>',
            htmlspecialchars('+' . $national, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($display, ENT_QUOTES, 'UTF-8'),
        );
    }

    /**
     * Jen obsah atributu `href` — pro tlačítko „Zavolat", kde je popisek slovo.
     *
     * Odděleno od `phone()`, protože ta vrací hotový odkaz i s číslem jako
     * textem. Normalizace je společná: kdyby si ji tlačítko dělalo po svém,
     * dřív nebo později by vytáčelo jiné číslo než odkaz vedle.
     */
    public static function telHref(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));

        return $raw === '' ? '' : '+' . self::national($raw);
    }

    /** Číslo v mezinárodním tvaru bez plusu — společný základ obou metod výše. */
    private static function national(string $raw): string
    {
        $digits = preg_replace('/[^0-9+]/', '', $raw) ?? '';
        $normalized = str_starts_with($digits, '00') ? '+' . substr($digits, 2) : $digits;
        $national = ltrim($normalized, '+');

        // Devět číslic = české číslo bez předvolby.
        return strlen($national) === 9 ? '420' . $national : $national;
    }

    /**
     * Poznámka v řádku — jeden řádek s výpustkou, celý text v bublině.
     *
     * Návrh (`Záznamy v2.dc.html` 1a): text se ořízne na šířku sloupce a při
     * najetí nad ním vyskočí panel 290 px s popiskem „Poznámka" a celým
     * zněním. Bublinu ukazuje `:hover` v CSS, ne skript — je to jen značka,
     * takže funguje i s vypnutým JavaScriptem.
     *
     * **Kdy se bublina vykreslí.** Server nezná šířku sloupce, takže nemůže
     * vědět, jestli se text opravdu ořízl. Řídí se proto délkou: sloupec je
     * v návrhu `1.2fr` z mřížky ~1134 px, tedy ~120 px, do kterých se při
     * 12,5px písmu vejde zhruba dvacet znaků. Práh je o kus výš, aby bublina
     * spíš přebývala, než chyběla — chybějící bublina u oříznutého textu je
     * vada (nejde se dočíst), bublina navíc je jen zbytečnost.
     *
     * Přesně by to změřil JavaScript (`scrollWidth > clientWidth`), ale za
     * jednu bublinu to nestojí: znamenalo by to prvek, který bez skriptu nic
     * nedělá.
     */
    public const NOTE_TIP_FROM = 24;

    public static function note(mixed $value, string $label = 'Poznámka'): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return self::EMPTY;
        }

        if (mb_strlen($text) < self::NOTE_TIP_FROM) {
            return sprintf(
                '<span class="notecell"><span class="notecell__text">%s</span></span>',
                self::plain($text),
            );
        }

        /**
         * `title` je fallback pro vypnutý JavaScript — `modules/hovertip.js`
         * ho při startu odstraní, aby se nad bublinou neukázaly obě.
         * Bublinu **musí** umístit skript: tabulka má `overflow-x: auto`,
         * takže by se absolutně pozicovaná bublina o kontejner ustřihla.
         */
        return sprintf(
            '<span class="notecell" data-hovertip>'
                . '<span class="notecell__text" title="%s">%s</span>'
                . '<span class="notecell__tip" data-hovertip-panel>'
                . '<span class="notecell__tip-label">%s</span>%s</span></span>',
            self::plain($text),
            self::plain($text),
            self::plain($label),
            self::plain($text),
        );
    }

    /** Zkrácený text s plnou verzí v tooltipu. */
    public static function shorten(mixed $value, int $length = 60): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return self::EMPTY;
        }

        if (mb_strlen($text) <= $length) {
            return self::text($text);
        }

        return sprintf(
            '<span title="%s">%s…</span>',
            self::plain($text),
            self::plain(mb_substr($text, 0, $length)),
        );
    }

    /**
     * Štítek kategorie — druh nepřítomnosti.
     *
     * **Není to stav**, a proto to není `get_pill()`. Slovník stavů odpovídá na
     * „jak na tom ta věc je" (čeká, hotovo, chyba); kategorie odpovídá na „co to
     * je". Nemoc není chyba a dovolená není hotová věc — kdyby se to psalo
     * odznakem stavu, muselo by se pro každý druh vybrat, který stav mu
     * „nejvíc sedí", a to je přesně ta záměna, kvůli které slovník vznikl.
     *
     * Barva sama nic neurčuje: štítek nese vždy i slovo.
     *
     * @param string $color jméno z palety osob (`red`, `green`, `orange`…)
     */
    public static function tag(mixed $value, string $color = 'gray'): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return self::EMPTY;
        }

        return sprintf(
            '<span class="pill pill--tag" data-color="%s">%s</span>',
            self::plain($color),
            self::plain($text),
        );
    }

    /**
     * Text s ikonou před ním — zdroj docházky, druh nepřítomnosti.
     *
     * Ikona **doplňuje slovo, nenahrazuje ho**: „Stopky" a „Ruční zápis" se od
     * sebe liší kresbou i textem, takže se rozliší i v tisku, i barvoslepému,
     * i tomu, kdo tu kresbu vidí poprvé. Totéž pravidlo jako u slovníku stavů.
     *
     * Jméno ikony putuje do `--item-icon` a překlep se nijak neprojeví — maska
     * prostě nic nenakreslí. Hlídá to test ikon.
     *
     * @param string $icon jméno ze sady `--i-*` bez předpony (`play`, `pencil`)
     * @param bool   $muted vedlejší význam — ruční zápis proti běžícím stopkám
     */
    public static function withIcon(mixed $value, string $icon, bool $muted = false): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return self::EMPTY;
        }

        return sprintf(
            '<span class="icon-label%s" style="--item-icon: var(--icon-%s)">%s</span>',
            $muted ? ' icon-label--muted' : '',
            self::plain($icon),
            self::plain($text),
        );
    }

    /**
     * Odznak osoby — kolečko s iniciálami v její barvě a jméno vedle.
     *
     * Dřív to byla obyčejná barevná pilulka. Návrh z ní udělal samostatný tvar
     * schválně: barva osoby se tím přestane plést se **stavem**. Odznaky stavu
     * jsou plné plochy v šesti významových barvách; osoba je obrys s kolečkem.
     *
     * Prázdná hodnota je tichá — nepřiřazený záznam nemá mít odznak
     * „nepřiřazen", jen prázdné místo. Řeší to volající, sem se nedostane.
     */
    /**
     * Počet příloh — spona s číslem (návrh 1a).
     *
     * **Není to odznak.** Odznak nese stav („prošlé", „uzavřeno") a barvu
     * k němu; počet fotek je vlastnost záznamu. Pilulka s číslem by ho
     * postavila naroveň termínu po splatnosti.
     *
     * Nula se nekreslí vůbec: „žádné fotky" je běžný stav, ne údaj.
     */
    public static function files(mixed $value): string
    {
        $count = (int) $value;

        if ($count <= 0) {
            return self::EMPTY;
        }

        return sprintf('<span class="filecount">%d</span>', $count);
    }

    public static function person(
        string $label,
        string $color = 'gray',
        string $title = '',
        ?string $avatar = null,
    ): string {
        // Fotka, když ji člověk má; jinak iniciály na jeho barvě. Iniciály
        // nejsou nouzové řešení — fotku má málokdo a prázdné kolečko vypadá
        // jako chyba. Stejné pravidlo jako v `partials/avatar`.
        $mark = $avatar !== null && $avatar !== ''
            ? sprintf(
                '<img class="avatar avatar--xs avatar--photo" src="%s" alt="" loading="lazy">',
                self::plain($avatar),
            )
            : sprintf(
                '<span class="avatar avatar--xs" data-color="%s" aria-hidden="true">%s</span>',
                self::plain($color),
                self::plain(self::initials($label)),
            );

        return sprintf(
            '<span class="person"%s>%s<span class="person__name">%s</span></span>',
            $title !== '' ? ' title="' . self::plain($title) . '"' : '',
            $mark,
            self::plain($label),
        );
    }

    /**
     * Iniciály z hotového jména.
     *
     * Naschvál nebere řádek uživatele jako `UserRepository::initials()` — sem
     * chodí i jméno poskládané jinde (osoba u časového záznamu) a rozebírat ho
     * zpátky na křestní a příjmení by bylo horší než vzít první písmena slov.
     */
    private static function initials(string $label): string
    {
        $words = preg_split('/\s+/u', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
    }
}
