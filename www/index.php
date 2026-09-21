<?php

declare(strict_types=1);

/**
 * Jediný vstupní bod aplikace Správa webů.
 *
 * Stejný princip jako u klientské aplikace: instaluje se do jednoho adresáře,
 * který je zároveň web rootem, a zdrojové soubory chrání přepisovací pravidlo
 * v .htaccess — cokoli mimo /assets/ jde přes tento skript.
 *
 * Ověření po nasazení (checklist fáze 6):
 *   - https://sprava-webu/app/Core/Kernel.php   → 404
 *   - https://sprava-webu/config/env.php        → 404
 *   - https://sprava-webu/storage/logs/         → 404
 *   - https://sprava-webu/assets/css/app.css    → 200
 */

use App\Core\Http\Request;

/** @var \App\Core\Kernel $kernel */
$kernel = require __DIR__ . '/app/bootstrap.php';

$request = Request::fromGlobals();

// Bezpečnostní hlavičky centrálně — ne v šablonách. Nonce povoluje jediný
// inline skript aplikace: import mapu JS modulů (Kernel::jsImportMap).
// Písma Instrument Sans a Geist Mono jdou z Google Fonts (rozhodnutí
// z návrhu), proto výjimka pro fonts.googleapis.com a fonts.gstatic.com.
//
// `worker-src` a `manifest-src` by propadly pod `default-src 'self'`
// a fungovaly by i tak — jsou tu vypsané schválně, aby bylo vidět, že
// se s nimi počítá (service worker a manifest PWA).
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; script-src 'self' 'nonce-" . $kernel->scriptNonce() . "'; font-src 'self' https://fonts.gstatic.com; "
        . "connect-src 'self'; worker-src 'self'; manifest-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if ($request->isSecure()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

$kernel->handle($request)->send();
