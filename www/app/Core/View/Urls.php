<?php

declare(strict_types=1);

namespace App\Core\View;

use Closure;
use RuntimeException;

/**
 * Vazba globálních funkcí `get_url()` a `get_asset()` na generátor adres.
 *
 * Šablony skládaly adresy zápisem `$this->e($kernel->url('zaznamy'))` — 107×
 * ve 46 souborech. Vedle toho existuje **20 míst, kde `e()` být nesmí**,
 * protože adresa jde do pole a escapuje si ji příjemce (`row-menu`,
 * `create-popover`, `notice`). Obojí vypadalo v kódu k nerozeznání a záměna
 * znamenala dvojité escapování — v adrese s parametrem by se objevilo `&amp;`.
 * Globální funkce ten rozdíl pojmenovává: `get_url()` escapuje vždy, surová
 * adresa se dál bere z `$kernel->url()`.
 *
 * ## Proč uzávěry a ne Kernel
 *
 * Testy renderují šablony bez Kernelu — posílají do nich anonymní třídu
 * s jedinou metodou `url()` (viz `dev/tests/SortableTest.php`). Kdyby tahle
 * třída chtěla `Kernel`, musely by si ho vyrobit, a ten potřebuje databázi.
 * Uzávěra vezme obojí: `Urls::bind($kernel->url(...), $kernel->asset(...))`
 * v aplikaci, `Urls::bind($stub->url(...), …)` v testu.
 *
 * Váže se v `app/bootstrap.php` hned po vytvoření Kernelu, tedy dřív, než se
 * může cokoli vykreslit.
 */
final class Urls
{
    private static ?Closure $url = null;

    private static ?Closure $asset = null;

    public static function bind(Closure $url, Closure $asset): void
    {
        self::$url = $url;
        self::$asset = $asset;
    }

    /**
     * Zruší vazbu.
     *
     * Testy běží všechny v jednom procesu, takže statická vazba přežívá mezi
     * soubory. Bez možnosti ji uklidit by test omylem kreslil adresy podle
     * náhrady Kernelu z jiného testu — a to je přesně ta chyba, která projde
     * a pozná se až u zákazníka.
     */
    public static function reset(): void
    {
        self::$url = null;
        self::$asset = null;
    }

    public static function url(string $path = '/'): string
    {
        return (self::$url ?? self::missing('url'))($path);
    }

    public static function asset(string $path): string
    {
        return (self::$asset ?? self::missing('asset'))($path);
    }

    /**
     * Nenavázaný stav je chyba programátora, ne stav k ošetření.
     *
     * Tiché vrácení `$path` by vyrobilo adresu bez základní cesty — aplikace
     * v podadresáři by odkazovala mimo sebe a nikde by nebylo proč.
     */
    private static function missing(string $what): never
    {
        throw new RuntimeException(sprintf(
            'Urls::%s() se volá bez navázání. Zavolejte Urls::bind() '
                . '(aplikace to dělá v app/bootstrap.php, test si ji naváže sám).',
            $what,
        ));
    }
}
