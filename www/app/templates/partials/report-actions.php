<?php
/**
 * Akce v řádku reportu (fronta Reporty i záložka Reporty u webu): ikony
 * vpravo podle pravidla o akcích v tabulkách. Koš otevře okno
 * `report-delete` (partial `report-delete-dialog`) s adresou tohoto reportu.
 *
 * @var \App\Core\View\View $this
 * @var array<string, mixed> $row řádek z `ReportController::row()` / `scheduledRow()`
 */
?>
<div class="row-actions">
    <a class="btn btn--ghost btn--icon" href="<?= $row['previewUrl'] ?>" title="<?= $this->e($row['actionLabel']) ?>" aria-label="<?= $this->e($row['actionLabel'] . ' — ' . $row['period']) ?>"><?= get_icon($row['actionIcon'], 'icon--sm') ?></a>
    <?php if ($row['htmlUrl'] !== null): ?>
        <a class="btn btn--ghost btn--icon" href="<?= $row['htmlUrl'] ?>" target="_blank" rel="noopener" title="Otevřít odeslané HTML" aria-label="Otevřít odeslané HTML — <?= $this->e($row['period']) ?>"><?= get_icon('external', 'icon--sm') ?></a>
    <?php endif; ?>
    <?php if ($row['deleteUrl'] !== null): ?>
        <a class="btn btn--ghost btn--icon" href="<?= $row['previewUrl'] ?>" title="Smazat report" aria-label="Smazat report <?= $this->e($row['period']) ?>" data-confirm="report-delete" data-confirm-action="<?= $row['deleteUrl'] ?>" data-confirm-title="<?= $this->e($row['deleteTitle']) ?>" data-confirm-note="<?= $this->e($row['deleteNote']) ?>"><?= get_icon('trash', 'icon--sm') ?></a>
    <?php endif; ?>
</div>
