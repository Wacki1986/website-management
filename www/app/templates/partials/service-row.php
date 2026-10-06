<?php
/**
 * Řádek služby projektu (doména, hosting) — detail projektu i přehled
 * Obnovy a fakturace. Akce vpravo: Vyfakturováno a Obnoveno jako malé
 * formuláře, úprava, smazání přes okno `service-delete`
 * (partial `service-delete-dialog`).
 *
 * @var \App\Core\View\View  $this
 * @var array<string, mixed> $row         řádek z `ProjectServiceController::row()` (`mail` = stav DNS pošty u domény)
 * @var bool                 $showProject sloupec Projekt (přehled obnov)
 * @var string               $back        '' = zpět na projekt, `obnovy` = na přehled
 * @var string               $csrfToken
 */
?>
<div class="table__row<?= $row['renewal']['tone'] === 'error' ? ' table__row--error' : '' ?>">
    <div class="table__cell row" style="flex-wrap:nowrap;gap:11px">
        <?= get_icon($row['icon'], 'icon--sm icon--subtle') ?>
        <div style="min-width:0">
            <div class="table__primary u-truncate" style="font-size:var(--font-size-label)"><?= $this->e($row['title']) ?></div>
            <div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($row['kindLabel'] . ($row['subtitle'] !== '' ? ' · ' . $row['subtitle'] : '')) ?></div>
            <?php if ($row['mail'] !== null): ?><div class="service-mail"><?= get_status($row['mail']['tone'], $row['mail']['label']) ?></div><?php endif; ?>
        </div>
    </div>
    <?php if ($showProject): ?>
        <div class="table__cell" style="min-width:0">
            <a class="u-truncate" style="display:block;font-size:var(--font-size-label)" href="<?= get_url('projekty/' . (int) $row['project_id']) ?>"><?= $this->e((string) $row['project_name']) ?></a>
            <div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e((string) ($row['client_name'] ?? 'bez klienta')) ?></div>
        </div>
    <?php endif; ?>
    <div class="table__cell"><?= get_status($row['renewal']['tone'], $row['renewal']['label']) ?></div>
    <div class="table__cell" style="min-width:0">
        <div class="u-truncate" style="font-size:var(--font-size-label)"><?= $row['price'] !== '' ? $this->e($row['price']) : '<span class="text-faint">—</span>' ?></div>
        <?php if ($row['cost'] !== ''): ?><div class="table__secondary u-truncate" style="margin-top:0">nákup <?= $this->e($row['cost']) ?></div><?php endif; ?>
    </div>
    <div class="table__cell"><?= get_status($row['billing']['tone'], $row['billing']['label']) ?></div>
    <div class="table__cell table__cell--right">
        <div class="row-actions">
            <?php if ($row['billing']['due']): ?>
                <form class="row-actions__form" method="post" action="<?= get_url($row['base'] . '/vyfakturovano') ?>">
                    <?php render_csrf($csrfToken) ?>
                    <input type="hidden" name="back" value="<?= $this->e($back) ?>">
                    <button type="submit" class="btn btn--ghost btn--icon" title="Vyfakturováno — zapsat fakturu za příští období" aria-label="Vyfakturováno: <?= $this->e($row['title']) ?>"><?= get_icon('check', 'icon--sm') ?></button>
                </form>
            <?php endif; ?>
            <?php if ($row['soon']): ?>
                <form class="row-actions__form" method="post" action="<?= get_url($row['base'] . '/obnoveno') ?>">
                    <?php render_csrf($csrfToken) ?>
                    <input type="hidden" name="back" value="<?= $this->e($back) ?>">
                    <button type="submit" class="btn btn--ghost btn--icon" title="Obnoveno — posunout obnovu o periodu" aria-label="Obnoveno: <?= $this->e($row['title']) ?>"><?= get_icon('refresh', 'icon--sm') ?></button>
                </form>
            <?php endif; ?>
            <a class="btn btn--ghost btn--icon" href="<?= get_url($row['base'] . '/upravit') ?>" title="Upravit" aria-label="Upravit <?= $this->e($row['title']) ?>"><?= get_icon('edit', 'icon--sm') ?></a>
            <a class="btn btn--ghost btn--icon" href="<?= get_url($row['base'] . '/upravit') ?>" title="Smazat" aria-label="Smazat <?= $this->e($row['title']) ?>" data-confirm="service-delete" data-confirm-action="<?= get_url($row['base'] . '/smazat') ?>" data-confirm-title="Smazat <?= $this->e(mb_strtolower($row['kindLabel']) . ' ' . $row['title']) ?>?"><?= get_icon('trash', 'icon--sm') ?></a>
        </div>
    </div>
</div>
