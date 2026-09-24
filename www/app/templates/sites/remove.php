<?php
/**
 * Potvrzení odebrání webu z monitoringu — opsáním domény.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var string              $host
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Odebrat']);
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--danger">
            <form method="post" action="<?= get_url('weby/' . (int) $site['id'] . '/odebrat') ?>" class="form">
                <?php render_csrf($csrfToken) ?>
                <div>
                    <div class="card__title" style="color:var(--color-status-error-text)">Odebrat web z monitoringu</div>
                    <div class="card__note">Web přestane být kontrolován a zmizí ze seznamů. Historie kontrol, události a reporty zůstanou 12 měsíců v archivu, pak se smažou. Uložené přístupy (FTP, hosting, databáze) se smažou hned. Klient ani plugin na webu se o tom nedozví — plugin můžete na webu odinstalovat.</div>
                </div>
                <div class="form__field">
                    <label class="form__label form__label--caps" for="confirm">Pro potvrzení opište doménu webu</label>
                    <input class="form__control form__control--mono" id="confirm" name="confirm" type="text" placeholder="<?= $this->e($host) ?>" autocomplete="off" autofocus>
                </div>
                <div class="row">
                    <button type="submit" class="btn btn--danger"><?= get_btn_icon('trash') ?>Odebrat web</button>
                    <a class="btn btn--ghost" href="<?= get_url('weby/' . (int) $site['id'] . '/nastaveni') ?>">Zrušit</a>
                </div>
            </form>
        </section>
    </div>
</div>
