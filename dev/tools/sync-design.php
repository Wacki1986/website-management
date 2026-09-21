<?php

declare(strict_types=1);

/**
 * Přenos SCSS z handoff balíčku designu do zdrojů aplikace.
 *
 * Spuštění z kořene projektu:  php dev/tools/sync-design.php
 *
 * Kopíruje `dev/design/scss/*` → `dev/scss/*` (kromě vstupního souboru
 * a složky `app/`, které patří aplikaci). Partialy designu volají mixiny
 * (`@include caps-label`) bez `@use` — balíček vznikl nad jiným
 * překladačem. Moderní Sass s modulovým systémem mixin bez `@use` nevidí,
 * proto se každému partialu s `@include` doplní na začátek
 * `@use "../abstracts/mixins" as *;`. Je to jediná úprava a dělá ji tenhle
 * skript, ne člověk — po nové předávce designu stačí spustit znovu.
 */

$root = dirname(__DIR__, 2);
$source = $root . '/dev/design/scss';
$target = $root . '/dev/scss';

if (!is_dir($source)) {
    fwrite(STDERR, "Chybí $source\n");
    exit(1);
}

$copied = 0;
$patched = 0;

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));

    // Vstupní soubor balíčku (main.scss) a doplňky aplikace se nepřenášejí.
    if ($relative === 'main.scss' || str_starts_with($relative, 'app/')) {
        continue;
    }

    $content = (string) file_get_contents($file->getPathname());

    // Mixiny leží v abstracts/_mixins.scss; ten sám žádný @use nepotřebuje.
    if ($relative !== 'abstracts/_mixins.scss' && str_contains($content, '@include') && !str_contains($content, '@use')) {
        $depth = substr_count($relative, '/');
        $prefix = str_repeat('../', $depth);
        $content = '@use "' . $prefix . 'abstracts/mixins" as *;' . "\n\n" . $content;
        $patched++;
    }

    $destination = $target . '/' . $relative;

    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0775, true);
    }

    file_put_contents($destination, $content);
    $copied++;
}

echo "Přeneseno $copied souborů, @use doplněno do $patched partialů.\n";
echo "Vstupní soubor dev/scss/app.scss se nemění — zkontrolujte, jestli v dev/design/scss/main.scss nepřibyl partial.\n";
