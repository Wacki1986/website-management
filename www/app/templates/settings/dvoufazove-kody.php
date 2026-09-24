<?php
/**
 * Záložní kódy dvoufázového přihlášení — ukážou se jen jednou, hned po
 * zapnutí nebo po vytvoření nové sady. V databázi jsou jen jako hash.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<int, string>  $codes
 * @var string              $codesText kódy po řádcích — pro tlačítko kopírování
 * @var string              $nextUrl   kam dál (po prvním spárování do aplikace)
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--padded">
            <div class="form">
                <div>
                    <div class="card__title">Záložní kódy</div>
                    <div class="card__note">Každý kód jde při přihlášení použít jednou místo kódu z aplikace — pro případ, že nemáte telefon po ruce, nebo ho ztratíte.</div>
                </div>

                <?php render_notice($this, 'warning', 'Uložte si je hned', 'Po opuštění stránky se už neukážou. Nejlépe do správce hesel, nebo vytisknout — jen ne do telefonu, ve kterém je aplikace.') ?>

                <ol class="recovery-codes">
                    <?php foreach ($codes as $code): ?>
                        <li class="u-mono"><?= $this->e($code) ?></li>
                    <?php endforeach; ?>
                </ol>

                <div class="row">
                    <button type="button" class="btn btn--secondary" data-copy="<?= $this->e($codesText) ?>"><?= get_btn_icon('copy') ?>Zkopírovat kódy</button>
                    <a class="btn btn--primary" href="<?= $nextUrl ?>">Mám je uložené, pokračovat</a>
                </div>
            </div>
        </section>
    </div>
</div>
