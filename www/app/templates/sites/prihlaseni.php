<?php
/**
 * Přechod z tlačítka „wp-admin" na web klienta (nové okno).
 *
 * Proč stránka a ne přesměrování: tlačítko je formulář (POST kvůli CSRF)
 * a hlavička CSP `form-action 'self'` v index.php platí i pro přesměrování
 * po odeslání formuláře — prohlížeč by přesměrování na cizí doménu tiše
 * zablokoval a nové okno zůstalo prázdné. Meta refresh je obyčejná
 * navigace, na tu se `form-action` nevztahuje.
 *
 * Stojí sama, bez layoutu: meta refresh patří do <head> a stránka je
 * vidět jen na okamžik.
 *
 * @var \App\Core\View\View $this
 * @var string              $target  kam okno přejde (jednorázový odkaz nebo wp-admin)
 * @var string              $siteName
 * @var string              $appName
 */
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="0;url=<?= $this->e($target) ?>">
    <title><?= $this->e('wp-admin ' . $siteName . ' — ' . $appName) ?></title>
    <link rel="stylesheet" href="<?= get_asset('css/app.css') ?>">
</head>
<body>
<div class="auth">
    <div class="auth__box">
        <p>Otevírám administraci webu <?= $this->e($siteName) ?>…</p>
        <p><a class="btn btn--primary" href="<?= $this->e($target) ?>">Pokračovat do wp-admin</a></p>
    </div>
</div>
</body>
</html>
