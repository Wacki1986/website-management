<?php
/**
 * Potvrzení smazání kontaktní osoby — jedno okno na stránku; adresu, jméno
 * a od kterých klientů osoba zmizí doplní `confirm-dialog.js` z koše
 * (seznam Kontaktní osoby, karta Smazat osobu).
 *
 * @var \App\Core\View\View $this
 * @var string              $csrfToken
 */
?>
<dialog class="modal modal--danger" id="contact-delete" aria-labelledby="contact-delete-title">
    <form class="modal__body" method="post" action="" data-pending>
        <?php render_csrf($csrfToken) ?>
        <div>
            <div class="card__title modal__title" id="contact-delete-title" data-confirm-title>Smazat osobu?</div>
            <div class="card__note" data-confirm-note></div>
        </div>
        <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-dialog-close>Zrušit</button>
            <button type="submit" class="btn btn--danger" data-pending-label="Mažu…"><?= get_btn_icon('trash') ?>Smazat osobu</button>
        </div>
    </form>
</dialog>
