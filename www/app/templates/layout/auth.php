<?php
/**
 * Layout nepřihlášených stránek (přihlášení, zapomenuté heslo, chybové
 * stránky pro nepřihlášené). Návrh přihlášení nemá — používá se karta
 * z design systému na podkladu stránky.
 *
 * @var \App\Core\View\View $this
 * @var \App\Core\Kernel    $kernel
 * @var string              $content
 * @var string              $title
 * @var string              $appName
 * @var array               $flashes
 * @var string              $csrfToken
 */
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->e(($title ?? '') . ' — ' . $appName) ?></title>
    <?= $this->partial('partials/head-icons') ?>
    <link rel="stylesheet" href="<?= get_asset('css/app.css') ?>">
</head>
<body>
<div class="auth">
    <div class="auth__box">
        <a class="auth__brand" href="<?= get_url('/') ?>">
            <img class="auth__logo" src="<?= get_asset('img/logo-mediagrafik.svg') ?>" alt="MEDIAGRAFIK">
            <span class="sidebar__logo-badge auth__badge"><?= $this->e($appName) ?></span>
        </a>

        <?php // Nepřihlášené stránky nemají toasty — hlášky se vypisují rovnou. ?>
        <?php foreach ($flashes ?? [] as $flash): ?>
            <?php render_notice($this, (string) $flash['type'], message: (string) $flash['message']) ?>
        <?php endforeach; ?>

        <?= $content ?>
    </div>
</div>

<?php // Skript kvůli přepínači viditelnosti hesla. Import mapa musí předcházet module skriptu. ?>
<script type="importmap" nonce="<?= $this->e($kernel->scriptNonce()) ?>"><?= json_encode($kernel->jsImportMap(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="module" src="<?= get_asset('js/app.js') ?>"></script>
</body>
</html>
