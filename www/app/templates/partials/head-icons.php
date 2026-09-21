<?php
/**
 * Ikony a hlavičky pro instalaci na plochu (PWA) — sdílí je oba layouty
 * (shell i auth), aby šla aplikace nainstalovat i z přihlašovací stránky.
 *
 * **Manifest je statický soubor v `assets/`, ne routa** — projde whitelistem
 * v `.htaccess` a za HTTP Basic auth se dá uvolnit jedním pravidlem.
 * Adresy v něm se počítají od `assets/`, proto `start_url`/`scope` = `../`.
 *
 * `crossorigin="use-credentials"` je nutnost: manifest se jinak stahuje
 * bez přihlašovacích údajů a za Basic auth vrátí 401 — prohlížeč pak
 * instalaci na plochu tiše nenabídne.
 *
 * `theme-color` barví stavový řádek na Androidu; sleduje podklad stránky
 * (tokeny `--color-background-page` z `dev/scss/abstracts/_tokens.scss`).
 *
 * @var \App\Core\View\View $this
 * @var string              $theme 'light' | 'dark' | 'auto' — volitelné, výchozí 'auto'
 */
$theme = $theme ?? 'auto';
$light = '#eef0f8';
$dark = '#0e1020';
?>
<link rel="icon" href="<?= get_asset('favicons/favicon.ico') ?>" sizes="48x48">
<link rel="icon" href="<?= get_asset('favicons/favicon.svg') ?>" type="image/svg+xml">
<link rel="icon" href="<?= get_asset('favicons/favicon-96x96.png') ?>" type="image/png" sizes="96x96">
<link rel="apple-touch-icon" href="<?= get_asset('favicons/apple-touch-icon.png') ?>">
<link rel="manifest" href="<?= get_asset('manifest.webmanifest') ?>" crossorigin="use-credentials">
<?php if ($theme === 'dark'): ?>
    <meta name="theme-color" content="<?= $dark ?>">
<?php elseif ($theme === 'light'): ?>
    <meta name="theme-color" content="<?= $light ?>">
<?php else: ?>
    <meta name="theme-color" content="<?= $light ?>" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="<?= $dark ?>" media="(prefers-color-scheme: dark)">
<?php endif; ?>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Správa webů">
<?php // Adresu service workeru nese meta, ať ji assets/js/modules/pwa.js
      // nemusí skládat z basePath. Soubor leží v kořeni schválně: scope
      // service workeru je daný jeho umístěním. ?>
<meta name="app-sw" content="<?= get_url('sw.js') ?>">
