<?php
/**
 * Potvrzení smazání reportu — jedno okno na stránku; adresu, nadpis
 * a vysvětlení doplní `confirm-dialog.js` z koše v řádku
 * (partial `report-actions`).
 *
 * @var \App\Core\View\View $this
 * @var string              $back      kam se po smazání vrátit (`reporty` nebo `weby/{id}/reporty`)
 * @var string              $csrfToken
 */
?>
<dialog class="modal modal--danger" id="report-delete" aria-labelledby="report-delete-title">
    <form class="modal__body" method="post" action="" data-pending>
        <?php render_csrf($csrfToken) ?>
        <input type="hidden" name="back" value="<?= $this->e($back) ?>">
        <div>
            <div class="card__title modal__title" id="report-delete-title" data-confirm-title>Smazat report?</div>
            <div class="card__note" data-confirm-note></div>
        </div>
        <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-dialog-close>Zrušit</button>
            <button type="submit" class="btn btn--danger" data-pending-label="Mažu…"><?= get_btn_icon('trash') ?>Smazat report</button>
        </div>
    </form>
</dialog>
