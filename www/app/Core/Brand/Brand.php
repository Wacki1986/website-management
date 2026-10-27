<?php

declare(strict_types=1);

namespace App\Core\Brand;

use App\Core\Settings\Settings;
use Closure;
use Throwable;

/**
 * Značka instance — kdo správu provozuje.
 *
 * Jeden kód běží ve více nasazeních (studio, vlastní weby na volné noze)
 * a nikde v něm nesmí být natvrdo jméno jednoho z nich. Název firmy,
 * kontakt, název a složka pluginu se vyplňují v Nastavení → Základní
 * nastavení (tabulka `settings`), loga a ikonu drží `BrandAssets`.
 *
 * Složka pluginu (slug) je zvláštní případ: vzniká z ní složka pluginu na
 * webech a adresa jeho REST API. Po napojení prvního webu se nemá měnit —
 * weby by se odpojily. Stránka ji proto zamkne a změnu pustí jen
 * s výslovným potvrzením (`SettingsController::savePlugin()`).
 *
 * Bez databáze (přihlašovací stránka při výpadku, testy šablon) platí
 * prázdné hodnoty — značka nesmí být důvod, proč stránka nejde.
 */
final class Brand
{
    /**
     * Slug pluginu, když ho Nastavení (ani `env.php` vývoje a testů)
     * neurčí. Je to historický název z prvního nasazení: weby, které už
     * plugin mají, ho mají v této složce a pod tímto REST namespace —
     * změna výchozí hodnoty by je odpojila.
     */
    public const DEFAULT_PLUGIN_SLUG = 'mediagrafik-monitor';

    /** Název aplikace — v liště, v předmětech e-mailů, v manifestu. */
    public const APP_NAME = 'Správa webů';

    /** User-Agent bez webu studia — výchozí hodnota tříd, které jdou ven. */
    public const USER_AGENT = 'SpravaWebu/1.0';

    /** Klíče v tabulce `settings`. Telefon má historický klíč z reportů. */
    public const SETTINGS = [
        'name' => 'brand_name',
        'web' => 'brand_web',
        'email' => 'brand_email',
        'phone' => 'report_phone',
        'plugin' => 'brand_plugin_name',
    ];

    /** Klíč složky pluginu v `settings` — ukládá se zvlášť, viz `savePluginSlug()`. */
    public const SLUG_SETTING = 'plugin_slug';

    /** Pro `get_brand()` v šablonách — viz `bind()`. */
    private static ?Closure $current = null;

    /** @var array<string, string>|null */
    private ?array $values = null;

    /**
     * @param array<string, mixed> $pluginConfig `env.php` → `plugin` (záložní slug pro vývoj a testy)
     */
    public function __construct(
        private readonly ?Settings $settings,
        private readonly array $pluginConfig,
        private readonly BrandAssets $assets,
    ) {
    }

    /**
     * Navázání pro globální helpery (`get_brand()`). Uzávěrou ze stejného
     * důvodu jako `View\Urls`: šablony se v testech kreslí bez Kernelu.
     * Nenavázaná značka (test šablony) = prázdné hodnoty, ne chyba — jméno
     * studia na stránce je ozdoba, ne adresa, která by vedla jinam.
     */
    public static function bind(?Closure $resolver): void
    {
        self::$current = $resolver;
    }

    public static function current(): ?self
    {
        return self::$current !== null ? (self::$current)() : null;
    }

    /**
     * Název pluginu pro hlášky tříd bez Kernelu (statická logika, monitor).
     * Bez navázané značky výchozí název — hláška nesmí spadnout.
     */
    public static function currentPluginName(): string
    {
        try {
            return self::current()?->pluginName() ?? self::APP_NAME . ' – Monitor';
        } catch (Throwable) {
            return self::APP_NAME . ' – Monitor';
        }
    }

    /** Slug pluginu pro třídy bez Kernelu — viz `currentPluginName()`. */
    public static function currentPluginSlug(): string
    {
        return self::current()?->pluginSlug() ?? self::DEFAULT_PLUGIN_SLUG;
    }

    // -----------------------------------------------------------------
    // Hodnoty ze Základního nastavení
    // -----------------------------------------------------------------

    /** Název firmy (může být prázdný). */
    public function name(): string
    {
        return $this->value('name');
    }

    /** Název firmy, nebo náhrada, když není vyplněný. */
    public function studioName(string $fallback = ''): string
    {
        return $this->name() !== '' ? $this->name() : $fallback;
    }

    /** Web firmy (https://…), nebo ''. */
    public function web(): string
    {
        return $this->value('web');
    }

    /** Kontaktní e-mail do patičky reportů, nebo ''. */
    public function email(): string
    {
        return $this->value('email');
    }

    public function phone(): string
    {
        return $this->value('phone');
    }

    /** Název pluginu, jak ho uvidí WordPress i texty správy. */
    public function pluginName(): string
    {
        $custom = $this->value('plugin');

        return $custom !== '' ? $custom : $this->defaultPluginName();
    }

    /** Název pluginu, když vlastní není vyplněný: „{studio} Monitor“. */
    public function defaultPluginName(): string
    {
        return $this->name() !== '' ? $this->name() . ' Monitor' : self::APP_NAME . ' – Monitor';
    }

    /** Uložené hodnoty pro formulář (bez náhrad). @return array<string, string> */
    public function forForm(): array
    {
        $values = [];

        foreach (array_keys(self::SETTINGS) as $key) {
            $values[$key] = $this->value($key);
        }

        return $values;
    }

    /**
     * Uloží hodnoty z formuláře (oříznuté, se stropem délky).
     *
     * @param array<string, string> $values
     */
    public function save(array $values): void
    {
        if ($this->settings === null) {
            return;
        }

        foreach (self::SETTINGS as $key => $setting) {
            if (array_key_exists($key, $values)) {
                // Řídicí znaky pryč: jméno se propisuje do hlavičky pluginu
                // (jeden řádek komentáře) a do hlaviček e-mailů.
                $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $values[$key]);
                $this->settings->set($setting, mb_substr(trim($clean), 0, 150));
            }
        }

        $this->values = null;
    }

    // -----------------------------------------------------------------
    // Plugin a technické identifikátory
    // -----------------------------------------------------------------

    /**
     * Složka pluginu na webech a začátek REST namespace: z Nastavení,
     * jinak z `env.php`, jinak historická výchozí.
     */
    public function pluginSlug(): string
    {
        try {
            $stored = $this->settings?->get(self::SLUG_SETTING) ?? '';
        } catch (Throwable) {
            $stored = '';
        }

        foreach ([$stored, (string) ($this->pluginConfig['slug'] ?? '')] as $slug) {
            if (self::isValidSlug($slug)) {
                return $slug;
            }
        }

        return self::DEFAULT_PLUGIN_SLUG;
    }

    /** Slug = jméno složky a kus adresy: malá písmena, číslice, pomlčky, 3–40 znaků. */
    public static function isValidSlug(string $slug): bool
    {
        return strlen($slug) >= 3 && strlen($slug) <= 40 && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1;
    }

    /** Uloží novou složku pluginu. Kontrolu napojených webů dělá volající. */
    public function savePluginSlug(string $slug): void
    {
        if ($this->settings !== null && self::isValidSlug($slug)) {
            $this->settings->set(self::SLUG_SETTING, $slug);
        }
    }

    /** REST namespace pluginu (`{slug}/v1`). */
    public function pluginNamespace(): string
    {
        return $this->pluginSlug() . '/v1';
    }

    /** Hlavní soubor pluginu, jak ho zná WordPress (`slug/slug.php`). */
    public function pluginFile(): string
    {
        return $this->pluginSlug() . '/' . $this->pluginSlug() . '.php';
    }

    /**
     * User-Agent všech požadavků správy ven (weby, ARES, wordpress.org…) —
     * v logu hlídaného webu je podle něj vidět, kdo se ptá.
     */
    public function userAgent(): string
    {
        return self::USER_AGENT . ($this->web() !== '' ? ' (+' . $this->web() . ')' : '');
    }

    /** Popisek účtu v aplikaci Authenticator. */
    public function twoFactorIssuer(): string
    {
        return self::APP_NAME . ($this->name() !== '' ? ' · ' . $this->name() : '');
    }

    public function assets(): BrandAssets
    {
        return $this->assets;
    }

    private function value(string $key): string
    {
        if ($this->values === null) {
            $this->values = [];

            try {
                foreach (self::SETTINGS as $name => $setting) {
                    $this->values[$name] = $this->settings !== null ? trim($this->settings->get($setting)) : '';
                }
            } catch (Throwable) {
                $this->values = array_fill_keys(array_keys(self::SETTINGS), '');
            }
        }

        return $this->values[$key] ?? '';
    }
}
