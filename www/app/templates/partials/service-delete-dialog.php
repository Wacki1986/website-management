<?php
/**
 * Potvrzení smazání služby projektu — jedno okno na stránku; adresu
 * a nadpis doplní `confirm-dialog.js` z koše v řádku (partial `service-row`).
 *
 * @var \App\Core\View\View $this
 * @var string              $back      '' = zpět na projekt, `obnovy` = na přehled
 * @var string              $csrfToken
 */
?>
<dialog class="modal modal--danger" id="service-delete" aria-labelledby="service-delete-title">
    <form class="modal__body" method="post" action="" data-pending>
        <?php render_csrf($csrfToken) ?>
        <input type="hidden" name="back" value="<?= $this->e($back) ?>">
        <div>
            <div class="card__title modal__title" id="service-delete-title" data-confirm-title>Smazat službu?</div>
            <div class="card__note">Služba zmizí z evidence projektu i z přehledu obnov. U registrátora ani poskytovatele se nic nemění.</div>
        </div>
        <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-dialog-close>Zrušit</button>
            <button type="submit" class="btn btn--danger" data-pending-label="Mažu…"><?= get_btn_icon('trash') ?>Smazat</button>
        </div>
    </form>
</dialog>
