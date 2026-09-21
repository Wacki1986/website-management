<?php

declare(strict_types=1);

namespace App\Core\View;

/**
 * Vykreslování formulářových prvků.
 *
 * Šablony měly každé pole rozepsané ručně — obal, popisek, hvězdička
 * u povinného, hláška u chyby. Bylo toho přes dvě stě kusů a všechny musely
 * vypadat stejně; stačilo u jednoho zapomenout `field--error` a pole se při
 * chybě nezvýraznilo. Přesně to, co se u rostoucí aplikace rozjede jako první.
 *
 * Pomocník nese **obal**, ne ovládací prvek: `<div class="form__field">`, `<label>`,
 * hvězdičku, nápovědu a chybovou hlášku. Prvek uvnitř je jen řetězec, takže
 * exotický případ (vlastní widget, mřížka barev) jde vložit přes `wrap()`
 * nebo `group()`, aniž by se sem musel přidávat další typ.
 *
 * ## Použití
 *
 * ```php
 * $form = new Form($errors, autofocus: array_key_first($errors));
 *
 * echo $form->text('phone', 'Telefon', $values['phone'], hint: 'Včetně předvolby');
 * echo $form->select('security', 'Zabezpečení', $options, $current);
 * echo $form->checkbox('active', 'Nabízet', $isActive);
 * ```
 *
 * ## Co pomocník nedělá
 *
 * **Nesestavuje celý formulář.** Rozvržení, `<form>`, tlačítka a pořadí polí
 * zůstávají v šabloně — tam je vidět, co stránka dělá. Generátor celých
 * formulářů z popisu by znamenal, že se každá odchylka řeší další volbou
 * v konfiguraci, a to je cesta, po které se nikdo nechce vracet.
 *
 * **Neescapuje popisky dvakrát.** Všechno, co jde dovnitř, se escapuje tady;
 * volající tedy předává **holý text**, ne HTML.
 */
final class Form
{
    /**
     * @param array<string, string> $errors klíč pole => hláška
     * @param string|null $autofocus na které pole skočit kurzorem (obvykle první chybné)
     */
    public function __construct(
        private readonly array $errors = [],
        private readonly ?string $autofocus = null,
    ) {
    }

    /**
     * Textové pole (a jeho příbuzní — e-mail, heslo, číslo, datum, čas…).
     *
     * `$required` dělá dvě věci najednou: přidá hvězdičku a atribut `required`.
     * Když je potřeba jen vynucení bez hvězdičky — třeba na přihlašovacím
     * formuláři, kde je zjevné, že se vyplňuje všechno — předá se `required`
     * mezi atributy. Hvězdička u každého pole ztrácí smysl přesně ve chvíli,
     * kdy je u všech.
     *
     * @param array<string, string|bool|null> $attributes další atributy (`placeholder`,
     *        `inputmode`, `step`, `maxlength`…); `true` znamená atribut bez hodnoty
     * @param int    $span    kolik z dvanácti sloupců řádku pole zabere; 0 = podle typu
     * @param string $measure šířka vstupu (`date`, `phone`…); '' = podle typu, `none` = bez omezení
     */
    public function text(
        string $name,
        string $label,
        mixed $value = '',
        string $type = 'text',
        bool $required = false,
        string $hint = '',
        array $attributes = [],
        ?string $id = null,
        string $class = '',
        int $span = 0,
        string $measure = '',
        string $suffix = '',
        string $keyLabel = '',
    ): string {
        $id ??= self::idFor($name);
        [$span, $measure] = self::layout($type, $span, $measure);

        // Jednotka v poli ukrajuje z jeho šířky (`padding-right`), takže číslo
        // se do měřítka odvozeného z typu nevejde — cena potřebuje svoje.
        if ($suffix !== '' && $measure === 'number') {
            $measure = 'price';
        }

        $control = sprintf(
            '<input type="%s" id="%s" name="%s"%s%s%s>',
            self::e($type),
            self::e($id),
            self::e($name),
            $this->controlClass($name, '', $attributes),
            // Výběr souboru hodnotu nenese — prohlížeč ji z bezpečnostních
            // důvodů předvyplnit nedovolí a atribut by jen mátl.
            $type === 'file' ? '' : sprintf(' value="%s"', self::e((string) ($value ?? ''))),
            $this->attributes($name, $required, $attributes),
        );

        // Jednotka uvnitř pole („Kč", „km"). Text si sedí vpravo a nedá se
        // označit ani do něj psát — je to popisek, ne hodnota.
        if ($suffix !== '') {
            $control .= sprintf('<span class="form__suffix">%s</span>', self::e($suffix));
        }

        return $this->wrap($name, $label, $control, $required, $hint, $id, $class, $span, $measure, $keyLabel);
    }

    /**
     * Víceřádkový text.
     *
     * Výchozí span je celá šířka: poznámka vedle úzkého pole vypadá jako chyba
     * sazby a text, kvůli kterému je pole víceřádkové, se do půlky řádku nevejde.
     *
     * @param array<string, string|bool|null> $attributes
     */
    public function textarea(
        string $name,
        string $label,
        mixed $value = '',
        bool $required = false,
        string $hint = '',
        array $attributes = [],
        ?string $id = null,
        string $class = '',
        int $span = 0,
    ): string {
        $id ??= self::idFor($name);
        $span = $span > 0 ? $span : self::SPAN_TEXTAREA;

        $control = sprintf(
            '<textarea id="%s" name="%s"%s%s>%s</textarea>',
            self::e($id),
            self::e($name),
            $this->controlClass($name, 'form__control--textarea', $attributes),
            $this->attributes($name, $required, $attributes),
            self::e((string) ($value ?? '')),
        );

        return $this->wrap($name, $label, $control, $required, $hint, $id, $class, $span);
    }

    /**
     * Rozbalovací seznam.
     *
     * @param array<string|int, string> $options hodnota => popisek
     * @param string|null $placeholder první položka bez hodnoty („— vše —");
     *                                 null = žádná
     * @param array<string, string|bool|null> $attributes
     */
    public function select(
        string $name,
        string $label,
        array $options,
        mixed $value = '',
        ?string $placeholder = null,
        bool $required = false,
        string $hint = '',
        array $attributes = [],
        ?string $id = null,
        string $class = '',
        int $span = 0,
        string $keyLabel = '',
    ): string {
        $id ??= self::idFor($name);
        // Výběr nemá měřítko: šířku určuje nejdelší nabízená hodnota a tu
        // z typu poznat nejde. Uříznutá položka je horší než široký seznam.
        $span = $span > 0 ? $span : self::SPAN_SELECT;
        $items = '';

        if ($placeholder !== null) {
            $items .= sprintf('<option value="">%s</option>', self::e($placeholder));
        }

        foreach ($options as $optionValue => $optionLabel) {
            $items .= sprintf(
                '<option value="%s"%s>%s</option>',
                self::e((string) $optionValue),
                // Porovnání jako řetězce: z formuláře i z databáze chodí čísla
                // jednou jako int, jednou jako string, a `===` by je minulo.
                (string) $optionValue === (string) ($value ?? '') ? ' selected' : '',
                self::e($optionLabel),
            );
        }

        $control = sprintf(
            '<select id="%s" name="%s"%s%s>%s</select>',
            self::e($id),
            self::e($name),
            $this->controlClass($name, 'form__control--select', $attributes),
            $this->attributes($name, $required, $attributes),
            $items,
        );

        return $this->wrap($name, $label, $control, $required, $hint, $id, $class, $span, '', $keyLabel);
    }

    /**
     * Zaškrtávátko.
     *
     * Má vlastní tvar — popisek je **vedle** prvku, ne nad ním, takže
     * `wrap()` se nepoužije. Bez toho by zaškrtávátka v mřížce polí poskakovala.
     *
     * @param array<string, string|bool|null> $attributes
     */
    public function checkbox(
        string $name,
        string $label,
        bool $checked = false,
        string $value = '1',
        string $hint = '',
        array $attributes = [],
        ?string $id = null,
        string $class = '',
    ): string {
        $id ??= self::idFor($name);

        $html = sprintf(
            '<div class="%s"><label class="form__check">'
                . '<input type="checkbox" id="%s" name="%s" value="%s"%s%s>'
                . '<span>%s</span></label>',
            self::e($this->wrapperClass($name, $class)),
            self::e($id),
            self::e($name),
            self::e($value),
            $checked ? ' checked' : '',
            $this->attributes($name, false, $attributes),
            self::e($label),
        );

        return $html . $this->hint($hint) . $this->error($name) . '</div>';
    }

    /**
     * Obal kolem hotového prvku — pro případy, na které tenhle pomocník nemá typ.
     *
     * Escapuje se **všechno kromě `$control`**: ten dodává volající a nese HTML.
     *
     * @param string $class doplňkové třídy obalu
     * @param int    $span  kolik z dvanácti sloupců řádku pole zabere
     */
    public function wrap(
        string $name,
        string $label,
        string $control,
        bool $required = false,
        string $hint = '',
        ?string $id = null,
        string $class = '',
        int $span = 12,
        string $measure = '',
        string $keyLabel = '',
    ): string {
        $id ??= self::idFor($name);

        // Prvek má kolem sebe vlastní obal, aby se do něj daly umístit ikony
        // (šipka výběru, lupa u hledání). Kreslí je pseudoprvek obalu, ne
        // podklad prvku: podkladový obrázek je natvrdo černý a v tmavém
        // režimu by zmizel, kdežto maska bere barvu textu.
        return sprintf(
            '<div class="%s"><label class="form__label" for="%s">%s%s</label>'
                . '%s%s%s</div>',
            self::e($this->wrapperClass($name, $class, $span, $measure)),
            self::e($id),
            self::e($label),
            // Klíč pole navíc vedle popisku. Pod tímhle jménem se pole hledá
            // v nastavení, v adrese filtru i v datech — a kdo ho nastavuje,
            // ho potřebuje vidět, ne dohledávat.
            ($keyLabel !== '' ? sprintf(' <span class="form__label-optional">%s</span>', self::e($keyLabel)) : '')
                . ($required ? ' <span class="form__required">*</span>' : ''),
            $control,
            $this->hint($hint),
            $this->error($name),
        );
    }

    /**
     * Skupina prvků pod společným popiskem — přepínače barvy, seznam rolí.
     *
     * Popisek je `<span>`, ne `<label>`: `for` umí ukázat jen na jeden prvek
     * a u skupiny pěti přepínačů by odečítač obrazovky četl ten první jako
     * název celé skupiny.
     *
     * @param string $content hotové HTML skupiny (dodává volající, neescapuje se)
     * @param string $class   doplňkové třídy obalu
     */
    public function group(
        string $name,
        string $label,
        string $content,
        string $hint = '',
        string $class = '',
    ): string {
        return sprintf(
            '<div class="%s"><span class="form__label">%s</span>%s%s%s</div>',
            self::e($this->wrapperClass($name, $class)),
            self::e($label),
            $content,
            $this->hint($hint),
            $this->error($name),
        );
    }

    /**
     * Kolik místa pole zabere v řádku (`span`) a jak široký je vstup uvnitř
     * (`measure`).
     *
     * Jsou to **dvě nezávislé věci**. Span je rozvržení: adresa si zaslouží víc
     * místa než datum, ať do ní kdo píše cokoli. Měřítko je obsah: datum
     * potřebuje dvanáct znaků, i kdyby jeho sloupec byl přes celou obrazovku.
     * Roztažené pole na hodinu říká „sem se vejde věta" — a někdo to dřív nebo
     * později zkusí.
     *
     * **Odvozuje se z typu, nepíše se do šablon.** O polích záznamu rozhoduje
     * firma v nastavení a přibývají za běhu; kdyby span stál v šabloně, vyšlo
     * by každé nové pole přes celou šířku a rozvržení by se rozpadlo. Takhle se
     * i pole, o kterém nikdo nevěděl, vykreslí rozumně. Přebít to jde
     * parametrem — u pevných polí záznamu tudy chodí `FieldProfile`.
     *
     * Text a výběr měřítko nemají schválně: do jednoho se píše jméno a do
     * druhého adresa, a to se z typu poznat nedá.
     */
    private const SPAN_BY_TYPE = [
        'date' => 3,
        'time' => 3,
        'number' => 3,
        'datetime-local' => 4,
        // Zbytek textových typů — jméno, e-mail, telefon, heslo, hledání.
        // Půlka řádku: dvě taková pole vedle sebe jsou pořád čitelná.
        'text' => 6,
        'tel' => 6,
        'email' => 6,
        'password' => 6,
        'search' => 6,
        'url' => 6,
    ];

    /** Výchozí span výběru a víceřádkového textu — ty typ v `type=` nemají. */
    private const SPAN_SELECT = 6;
    private const SPAN_TEXTAREA = 12;

    /** @var array<string, string> typ vstupu => měřítko (`field--m-*`) */
    private const MEASURE_BY_TYPE = [
        'date' => 'date',
        'time' => 'time',
        'number' => 'number',
        'tel' => 'phone',
        'email' => 'email',
    ];

    /**
     * Třídy obalu pole.
     *
     * `$span` 0 znamená „odvoď z typu", 12 je výchozí celá šířka a nevypisuje
     * se — tu má `.field` sám. `$measure` `'none'` měřítko vypne i tam, kde by
     * z typu vyšlo.
     */
    private function wrapperClass(
        string $name,
        string $class,
        int $span = 12,
        string $measure = '',
    ): string {
        return implode(' ', array_filter([
            'form__field',
            $span !== 12 ? 'form__field--' . $span : '',
            $measure !== '' && $measure !== 'none' ? 'form__field--m-' . $measure : '',
            $class,
            $this->hasError($name) ? 'form__field--error' : '',
        ]));
    }

    /**
     * Span a měřítko pro jeden prvek: co dodal volající, jinak co plyne z typu.
     *
     * @return array{0: int, 1: string}
     */
    private static function layout(string $type, int $span, string $measure): array
    {
        return [
            $span > 0 ? $span : (self::SPAN_BY_TYPE[$type] ?? 12),
            $measure !== '' ? $measure : (self::MEASURE_BY_TYPE[$type] ?? ''),
        ];
    }

    private function hasError(string $name): bool
    {
        return isset($this->errors[$name]);
    }

    /**
     * Atribut `class` prvku: `control` (vzhled pole, components/_form.scss),
     * varianta podle druhu a případná třída z `$attributes` — ta se tu
     * spotřebuje, aby `attributes()` nevypsala `class` podruhé.
     *
     * @param array<string, string|bool|null> $attributes
     */
    private function controlClass(string $name, string $variant, array &$attributes): string
    {
        $extra = trim((string) ($attributes['class'] ?? ''));
        unset($attributes['class']);

        return sprintf(' class="%s"', self::e(implode(' ', array_filter(['form__control', $variant, $this->hasError($name) ? 'form__control--error' : '', $extra]))));
    }

    /**
     * Atributy prvku: povinnost, autofocus a to, co dodá volající.
     *
     * `aria-invalid` a `aria-describedby` se doplňují samy — u ručně psaných
     * polí na ně nikdo nemyslel a odečítač obrazovky pak o chybě nevěděl.
     *
     * @param array<string, string|bool|null> $extra
     */
    private function attributes(string $name, bool $required, array $extra): string
    {
        $attributes = [];

        if ($required) {
            $attributes['required'] = true;
        }

        if ($this->autofocus !== null && $this->autofocus === $name) {
            $attributes['autofocus'] = true;
        }

        if ($this->hasError($name)) {
            $attributes['aria-invalid'] = 'true';
            $attributes['aria-describedby'] = self::idFor($name) . '-error';
        }

        $html = '';

        foreach ($extra + $attributes as $attribute => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }

            $html .= $value === true
                ? ' ' . self::e($attribute)
                : sprintf(' %s="%s"', self::e($attribute), self::e((string) $value));
        }

        return $html;
    }

    /**
     * Nápověda pod polem.
     *
     * Rozumí dvěma zápisům převzatým z Markdownu:
     *
     * - `` `klíč` `` → `<code>klíč</code>` — v nápovědách se často objeví název
     *   nastavení nebo klíč sloupce a bez odlišení splyne s větou,
     * - `[Nastavení](/nastaveni)` → odkaz — nápověda typu „seznam vozidel se
     *   nastavuje v Nastavení" má smysl jen jako klikací.
     *
     * Escapuje se **před** převodem, takže HTML v textu zůstane neškodným
     * textem a do odkazu se nedá propašovat uvozovka.
     *
     * **Nápověda zůstává i u chybného pole.** Chvíli ji hláška nahrazovala, aby
     * pole po odeslání nenarostlo o řádek — jenže validace tu běží na serveru
     * a chyba přichází s celým načtením stránky, takže žádný skok pod kurzorem
     * nevzniká. Zbylo by jen to, že „Prázdné = stopky poběží dál" zmizí přesně
     * ve chvíli, kdy člověk pole opravuje a tu větu potřebuje nejvíc.
     */
    private function hint(string $hint): string
    {
        if ($hint === '') {
            return '';
        }

        $html = self::e($hint);
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html) ?? $html;
        $html = preg_replace('/\[([^\]]+)]\(([^)\s]+)\)/', '<a href="$2">$1</a>', $html) ?? $html;

        return sprintf('<span class="form__hint">%s</span>', $html);
    }

    private function error(string $name): string
    {
        if (!$this->hasError($name)) {
            return '';
        }

        return sprintf(
            '<span class="form__error" id="%s-error">%s</span>',
            self::e(self::idFor($name)),
            self::e($this->errors[$name]),
        );
    }

    /**
     * Id z názvu pole.
     *
     * `items[3][label]` → `items_3_label`: hranaté závorky v `id` sice HTML
     * dovolí, ale v CSS selektoru se musí escapovat a `<label for>` na ně bývá
     * citlivý. Pole záznamu tudy neprochází — ta se jmenují prostě `car_type`.
     *
     * Veřejné kvůli šablonám, které pole vykreslují ve smyčce: název je u všech
     * stejný (`types[]`), takže si id musí vyrobit samy — a musí vzniknout
     * stejným pravidlem, jinak by `<label for>` ukazoval vedle.
     */
    public static function idFor(string $name): string
    {
        return trim(preg_replace('/[^a-zA-Z0-9_-]+/', '_', $name) ?? $name, '_');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
