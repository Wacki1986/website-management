<?php
/**
 * Hlavní layout přihlášené části — boční menu podle návrhu (`.app` +
 * `.sidebar` + `.app__main`).
 *
 * V menu jsou počty (weby, otevřené alerty) a v patičce stav monitoru —
 * všechno spočítané v `Kernel::shareViewGlobals()`, šablona jen vypisuje.
 * Na telefonu se menu schová pod tlačítko: `<details data-popover>` funguje
 * i bez JavaScriptu, skript jen doplní zavření kliknutím vedle.
 *
 * @var \App\Core\View\View $this
 * @var \App\Core\Kernel    $kernel
 * @var string              $content
 * @var string              $title
 * @var string              $appName
 * @var array|null          $currentUser
 * @var array               $flashes
 * @var string              $csrfToken
 * @var string              $currentPath
 * @var int                 $siteCount        weby v monitoringu
 * @var int                 $openAlertCount   otevřené alerty
 * @var array|null          $monitorStatus    poslední běh cronu (`settings.monitor_last_run`)
 */

use App\Core\Auth\UserRepository;

$theme = is_string($currentUser['theme'] ?? null) ? $currentUser['theme'] : 'auto';

$navItems = [
    ['url' => '/', 'label' => 'Dashboard', 'icon' => 'dashboard', 'active' => $currentPath === '/'],
    ['url' => 'weby', 'label' => 'Weby', 'icon' => 'globe', 'active' => str_starts_with($currentPath, '/weby'),
        'count' => (int) ($siteCount ?? 0), 'alert' => false],
    ['url' => 'alerty', 'label' => 'Alerty', 'icon' => 'alert', 'active' => str_starts_with($currentPath, '/alerty'),
        'count' => (int) ($openAlertCount ?? 0), 'alert' => true],
    ['url' => 'klienti', 'label' => 'Klienti', 'icon' => 'users', 'active' => str_starts_with($currentPath, '/klienti')],
    ['url' => 'reporty', 'label' => 'Reporty', 'icon' => 'report', 'active' => str_starts_with($currentPath, '/reporty')],
    ['url' => 'knihovna', 'label' => 'Knihovna pluginů', 'icon' => 'plugin', 'active' => str_starts_with($currentPath, '/knihovna')],
    ['url' => 'nastaveni', 'label' => 'Nastavení', 'icon' => 'settings', 'active' => str_starts_with($currentPath, '/nastaveni')],
];

$monitor = $monitorStatus ?? null;
$state = (string) (($monitorState ?? [])['state'] ?? 'never');
$monitorTone = match ($state) { 'ok' => 'ok', 'never' => 'muted', default => 'error' };
$monitorTitle = match ($state) { 'ok' => 'Monitor běží', 'stale' => 'Monitor neběží', 'failed' => 'Monitor selhal', default => 'Monitor čeká' };
$monitorNote = is_array($monitor)
    ? 'Poslední běh ' . get_when((string) ($monitor['at'] ?? '')) . ' · ' . get_count((int) ($siteCount ?? 0), 'web', 'weby', 'webů')
    : 'Cron zatím neběžel · ' . get_count((int) ($siteCount ?? 0), 'web', 'weby', 'webů');
?>
<!doctype html>
<html lang="cs"<?= $theme !== 'auto' ? ' data-theme="' . $this->e($theme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $this->e($csrfToken) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->e(($title ?? '') . ' — ' . $appName) ?></title>
    <?= $this->partial('partials/head-icons', ['theme' => $theme]) ?>
    <link rel="stylesheet" href="<?= get_asset('css/app.css') ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <a class="sidebar__logo" href="<?= get_url('/') ?>">
            <img src="<?= get_asset('img/logo-mediagrafik.svg') ?>" alt="MEDIAGRAFIK">
            <span class="sidebar__logo-badge"><?= $this->e($appName) ?></span>
        </a>

        <?php // Telefon: tlačítko s ikonou menu; na počítači je nav vidět stále (CSS). ?>
        <details class="sidebar__mobile" data-popover>
            <summary class="btn btn--secondary btn--sm sidebar__mobile-toggle" aria-label="Hlavní nabídka"><?= get_btn_icon('menu') ?>Menu</summary>
        </details>

        <nav class="sidebar__nav" aria-label="Hlavní navigace">
            <?php foreach ($navItems as $item): ?>
                <a class="sidebar__item<?= $item['active'] ? ' sidebar__item--active' : '' ?>"
                   href="<?= get_url($item['url']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                    <?= get_icon($item['icon']) ?>
                    <span><?= $this->e($item['label']) ?></span>
                    <?php if (($item['count'] ?? 0) > 0): ?>
                        <span class="sidebar__count<?= $item['alert'] ? ' sidebar__count--alert' : '' ?>"><?= (int) $item['count'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar__footer">
            <div class="sidebar__monitor">
                <div class="sidebar__monitor-title"><?= get_dot($monitorTone) ?><?= $monitorTitle ?></div>
                <div class="text-subtle"><?= $this->e($monitorNote) ?></div>
            </div>

            <?php // Nabídka účtu: motiv, profil, odhlášení. <details> funguje bez JS. ?>
            <details class="sidebar__account" data-popover data-popover-flip>
                <summary class="sidebar__user">
                    <?= $this->partial('partials/avatar', ['user' => is_array($currentUser) ? $currentUser : []]) ?>
                    <?php if (is_array($currentUser)): ?>
                        <span>
                            <span class="sidebar__user-name"><?= $this->e(UserRepository::displayName($currentUser)) ?></span>
                            <span class="text-caption"><?= $this->e(UserRepository::roleLabel((string) ($currentUser['role'] ?? ''))) ?></span>
                        </span>
                    <?php endif; ?>
                </summary>
                <div class="sidebar__menu">
                    <?php if (($currentUser['email'] ?? '') !== ''): ?>
                        <div class="sidebar__menu-mail text-caption"><?= $this->e((string) $currentUser['email']) ?></div>
                    <?php endif; ?>
                    <form method="post" action="<?= get_url('motiv') ?>">
                        <?php render_csrf($csrfToken) ?>
                        <span class="caps">Motiv</span>
                        <div class="segmented" style="margin-top:6px">
                            <button type="submit" name="theme" value="light" data-theme-set="light"
                                    class="segmented__item<?= $theme === 'light' ? ' segmented__item--active' : '' ?>">Světlý</button>
                            <button type="submit" name="theme" value="auto" data-theme-set="auto"
                                    class="segmented__item<?= $theme === 'auto' ? ' segmented__item--active' : '' ?>">Auto</button>
                            <button type="submit" name="theme" value="dark" data-theme-set="dark"
                                    class="segmented__item<?= $theme === 'dark' ? ' segmented__item--active' : '' ?>">Tmavý</button>
                        </div>
                    </form>
                    <a class="sidebar__menu-item" href="<?= get_url('nastaveni/uzivatele') ?>"><?= get_icon('users', 'icon--sm') ?>Můj účet</a>
                    <form method="post" action="<?= get_url('odhlaseni') ?>">
                        <?php render_csrf($csrfToken) ?>
                        <button type="submit" class="sidebar__menu-item"><?= get_icon('external', 'icon--sm') ?>Odhlásit se</button>
                    </form>
                </div>
            </details>
        </div>
    </aside>

    <main class="app__main">
        <?= $content ?>
    </main>
</div>

<div class="toasts" data-toasts aria-live="polite">
    <?php foreach ($flashes ?? [] as $flash): ?>
        <?php $type = in_array($flash['type'], ['success', 'error', 'warning', 'info'], true) ? $flash['type'] : 'info'; ?>
        <div class="toast toast--<?= $this->e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
            <span><?= $this->e($flash['message']) ?></span>
            <button type="button" class="toast__close" data-toast-close aria-label="Zavřít hlášku">×</button>
        </div>
    <?php endforeach; ?>
</div>

<?php // Import mapa musí předcházet module skriptu — verzuje jeho importy. ?>
<script type="importmap" nonce="<?= $this->e($kernel->scriptNonce()) ?>"><?= json_encode($kernel->jsImportMap(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="module" src="<?= get_asset('js/app.js') ?>"></script>
</body>
</html>
