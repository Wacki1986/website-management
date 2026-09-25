<?php
/**
 * Hlavička detailu webu (návrh `detail-webu-*.html`): drobeček, název,
 * stav, doména, klient, interval kontrol, akce a záložky. Data připravuje
 * `SiteController::header()`.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var string              $tab           klíč aktivní záložky — „Zkontrolovat teď" se na ni vrátí
 * @var string              $tabsHtml      hotové záložky (get_tabs)
 * @var array{tone: string, label: string} $headerStatus
 * @var string              $host
 * @var string              $adminUrl
 * @var array{action: string, user: string}|null $login přihlášení jedním klikem (null = jen odkaz na přihlášení)
 * @var string              $intervalLabel
 * @var array{title: string, text: string}|null $apiWarning
 * @var string              $csrfToken
 */
?>
<header class="page-header">
    <div class="page-header__breadcrumb">
        <a href="<?= get_url('weby') ?>">Weby</a><span>/</span><span><?= $this->e((string) $site['name']) ?></span>
    </div>

    <div class="page-header__top">
        <div>
            <h1 class="page-header__title"><?= $this->e((string) $site['name']) ?></h1>
            <div class="page-header__meta">
                <?= get_status($headerStatus['tone'], $headerStatus['label']) ?>
                <a href="<?= $this->e((string) $site['url']) ?>" target="_blank" rel="noopener"><?= $this->e($host) ?></a>
                <?php if (($site['client_name'] ?? null) !== null): ?>
                    <a href="<?= get_url('klienti/' . (int) $site['client_id']) ?>"><?= $this->e((string) $site['client_name']) ?></a>
                <?php else: ?>
                    <span class="text-faint">bez klienta</span>
                <?php endif; ?>
                <span><?= $this->e($intervalLabel) ?></span>
            </div>
        </div>
        <div class="page-header__actions">
            <?php if ($login !== null): ?>
                <form method="post" action="<?= $login['action'] ?>" target="_blank">
                    <?php render_csrf($csrfToken) ?>
                    <button type="submit" class="btn btn--secondary btn--sm" title="Přihlásit jako <?= $this->e($login['user']) ?> — bez hesla"><?= get_btn_icon('external') ?>wp-admin</button>
                </form>
            <?php else: ?>
                <a class="btn btn--secondary btn--sm" href="<?= $this->e($adminUrl) ?>" target="_blank" rel="noopener"><?= get_btn_icon('external') ?>wp-admin</a>
            <?php endif; ?>
            <form method="post" action="<?= get_url('weby/' . (int) $site['id'] . '/zkontrolovat') ?>" data-pending data-pending-overlay="Kontroluji web…" data-pending-note="Dostupnost, SSL, data z pluginu a zabezpečení. Obvykle to trvá pár sekund, u pomalého webu až půl minuty.">
                <?php render_csrf($csrfToken) ?>
                <input type="hidden" name="back" value="<?= $this->e($tab) ?>">
                <button type="submit" class="btn btn--primary btn--sm"><?= get_btn_icon('refresh') ?>Zkontrolovat teď</button>
            </form>
        </div>
    </div>

    <?= $tabsHtml ?>
</header>

<?php if ($apiWarning !== null): ?>
    <div class="app__content" style="padding-bottom:0">
        <div class="alert alert--row">
            <?= get_dot('error') ?>
            <div style="flex:1;min-width:0">
                <div style="font-weight:var(--font-weight-semibold);color:var(--color-status-error-text)"><?= $this->e($apiWarning['title']) ?></div>
                <div class="text-caption"><?= $this->e($apiWarning['text']) ?></div>
            </div>
            <form method="post" action="<?= get_url('weby/' . (int) $site['id'] . '/zkontrolovat') ?>" data-pending data-pending-overlay="Kontroluji web…" data-pending-note="Zkouším znovu spojení s pluginem MEDIAGRAFIK Monitor.">
                <?php render_csrf($csrfToken) ?>
                <input type="hidden" name="back" value="<?= $this->e($tab) ?>">
                <button type="submit" class="btn btn--secondary btn--sm">Zkusit znovu</button>
            </form>
        </div>
    </div>
<?php endif; ?>
