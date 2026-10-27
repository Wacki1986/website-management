<?php

declare(strict_types=1);

/**
 * Globální funkce ke značce instance (Nastavení → Základní nastavení).
 *
 * Konvence a pravidla o escapování popisuje `app/helpers/markup.php`.
 * Jméno studia a název pluginu jdou do šablon jako sdílené proměnné
 * `$studioName` a `$pluginName` (Kernel), ne přes helper — často se
 * skládají do textu pro jiného pomocníka (`render_empty`…), který si
 * escapuje sám, a escapovaný řetězec by se tam escapoval podruhé.
 */

use App\Core\Brand\Brand;
use App\Core\Brand\BrandAssets;
use App\Core\View\Urls;

/**
 * Adresa loga, **escapovaná pro atribut** — nahrané přes `/znacka/…`,
 * jinak výchozí z `assets/`. Prázdný řetězec = slot nemá ani výchozí
 * logo (logo studia), místo obrázku patří jméno textem.
 *
 * `$slot`: `app`, `app_dark`, `studio`, `studio_inverse` (`BrandAssets::SLOTS`).
 */
function get_brand_logo(string $slot): string
{
    $target = Brand::current()?->assets()->logoTarget($slot);

    if ($target === null && Brand::current() === null) {
        // Bez navázané značky (test šablony) výchozí soubor.
        $default = BrandAssets::SLOTS[$slot]['default'] ?? null;
        $target = $default !== null ? ['kind' => 'asset', 'path' => $default] : null;
    }

    return $target !== null ? get_brand_target_url($target) : '';
}

/**
 * Adresa ikony ze sady (`favicon.ico`, `icon-192.png`…), escapovaná.
 * Nahraná ikona jde přes `/znacka/…`, jinak výchozí z `assets/favicons/`.
 * Prázdný řetězec = soubor v nahrané sadě není (`favicon.svg`).
 */
function get_brand_icon(string $file): string
{
    $brand = Brand::current();
    $target = $brand !== null ? $brand->assets()->iconTarget($file) : ['kind' => 'asset', 'path' => 'favicons/' . $file];

    return $target !== null ? get_brand_target_url($target) : '';
}

/**
 * Vnitřní: cíl z `BrandAssets` na escapovanou adresu.
 *
 * @param array{kind: string, path: string} $target
 */
function get_brand_target_url(array $target): string
{
    $url = $target['kind'] === 'asset' ? Urls::asset($target['path']) : Urls::url($target['path']);

    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
