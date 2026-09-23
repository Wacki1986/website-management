<?php
/**
 * Potvrzení smazání neaktivního pluginu z webu (návrh ho nemá; skládá se
 * jako odebrání webu — karta `card--danger`).
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array<string, mixed> $plugin řádek `site_plugins`
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Smazat plugin']);

$base = 'weby/' . (int) $site['id'];
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--danger">
            <form method="post" action="<?= get_url($base . '/pluginy/smazat') ?>" class="form" data-pending>
                <?php render_csrf($csrfToken) ?>
                <input type="hidden" name="plugin" value="<?= $this->e((string) $plugin['file']) ?>">
                <div>
                    <div class="card__title" style="color:var(--color-status-error-text)">Smazat plugin <?= $this->e((string) $plugin['name']) ?> <?= $this->e((string) $plugin['version']) ?></div>
                    <div class="card__note">Plugin je na webu neaktivní. Smazání odstraní jeho soubory a spustí jeho odinstalaci — plugin tím obvykle smaže i svá nastavení a data v databázi. Vrátit to nejde, jedině ze zálohy.</div>
                </div>
                <div class="summary-list">
                    <div class="summary-list__row"><span class="summary-list__label">Autor</span><span class="summary-list__value"><?= $this->e((string) $plugin['author']) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Soubor</span><span class="summary-list__value text-mono"><?= $this->e((string) $plugin['file']) ?></span></div>
                </div>
                <div class="row">
                    <button type="submit" class="btn btn--danger" data-pending-label="Mažu…"><?= get_btn_icon('trash') ?>Smazat plugin</button>
                    <a class="btn btn--ghost" href="<?= get_url($base . '/pluginy') ?>">Zrušit</a>
                </div>
            </form>
        </section>
    </div>
</div>
