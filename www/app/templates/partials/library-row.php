<?php
/**
 * Řádek tabulky v Knihovně pluginů — plugin z knihovny i náš MEDIAGRAFIK
 * Monitor v samostatné kartě. Weby jako pilulky: oranžová s verzí = čeká
 * na aktualizaci, šedá = aktuální (u víc webů schované za „+ N aktuálních",
 * rozbalí `library-update.js`). Ikona „Aktualizovat všude" otevře okno
 * z partialu `library-update-dialog`.
 *
 * @var \App\Core\View\View  $this
 * @var array<string, mixed> $plugin řádek z `PluginLibraryController::row()`: name, file, version,
 *                                   `sites` (je vůbec na nějakém webu), `outdated` (počet webů se starší verzí),
 *                                   `listedSites` (vypsané) a `hiddenSites` (pod „+ N aktuálních", popisek `hiddenLabel`)
 *                                   — id, name, version, url, outdated, blocked (proč ho „všude" přeskočí, null = ne);
 *                                   `targets` / `skipped`, `dialogId`; k tomu sizeLabel, uploaded, downloadUrl,
 *                                   removeAction (null = z knihovny nejde odebrat — Monitor)
 * @var string               $csrfToken
 */
?>
<div class="table__row table__row--top">
    <div class="table__cell">
        <div class="table__primary u-truncate"><?= $this->e((string) $plugin['name']) ?></div>
        <div class="table__secondary u-truncate u-mono"><?= $this->e((string) $plugin['file']) ?></div>
    </div>
    <div class="table__cell table__cell--mono">
        <?= $this->e((string) $plugin['version']) ?>
        <div class="table__secondary"><?= $this->e($plugin['sizeLabel']) ?></div>
    </div>
    <div class="table__cell">
        <?php if (!$plugin['sites']): ?>
            <span class="text-faint">zatím na žádném webu</span>
        <?php else: ?>
            <?= $plugin['outdated'] > 0 ? get_status('warning', get_count($plugin['outdated'], 'web čeká', 'weby čekají', 'webů čeká') . ' na aktualizaci') : get_status('ok', 'všude aktuální') ?>
            <div class="library-sites">
                <?php foreach ($plugin['listedSites'] as $site): ?>
                    <?php if ($site['outdated']): ?>
                        <a class="pill pill--sm pill--button pill--warning" href="<?= $site['url'] ?>" title="<?= $this->e($site['blocked'] !== null ? 'Verze ' . $site['version'] . ' — Aktualizovat všude web přeskočí: ' . $site['blocked'] : 'Verze ' . $site['version'] . ', čeká na aktualizaci') ?>"><?= $this->e($site['name']) ?> <span class="u-mono"><?= $this->e($site['version']) ?></span></a>
                    <?php else: ?>
                        <a class="pill pill--sm pill--button pill--muted" href="<?= $site['url'] ?>" title="Verze <?= $this->e($site['version']) ?>, aktuální"><?= $this->e($site['name']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($plugin['hiddenSites'] as $site): ?>
                    <a class="pill pill--sm pill--button pill--muted" href="<?= $site['url'] ?>" title="Verze <?= $this->e($site['version']) ?>, aktuální" data-library-hidden hidden><?= $this->e($site['name']) ?></a>
                <?php endforeach; ?>
                <?php if ($plugin['hiddenSites'] !== []): ?>
                    <button type="button" class="pill pill--sm pill--button library-sites__more" data-library-more aria-label="Ukázat <?= $this->e($plugin['hiddenLabel']) ?>"><?= $this->e($plugin['hiddenLabel']) ?></button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="table__cell text-secondary" style="font-size:var(--font-size-label)"><?= $this->e($plugin['uploaded']) ?></div>
    <div class="table__cell table__cell--right">
        <div class="row-actions">
            <?php if ($plugin['targets'] !== []): ?>
                <a class="btn btn--secondary btn--icon" href="#" data-confirm="<?= $this->e($plugin['dialogId']) ?>" title="Aktualizovat všude na <?= $this->e((string) $plugin['version']) ?>" aria-label="Aktualizovat <?= $this->e((string) $plugin['name']) ?> všude na <?= $this->e((string) $plugin['version']) ?>"><?= get_icon('refresh', 'icon--sm') ?></a>
            <?php elseif ($plugin['skipped'] !== []): ?>
                <button type="button" class="btn btn--secondary btn--icon" disabled title="Žádný z webů se starší verzí teď nejde aktualizovat ze správy — důvod je v bublině u webu." aria-label="Aktualizovat všude teď nejde"><?= get_icon('refresh', 'icon--sm') ?></button>
            <?php endif; ?>
            <a class="btn btn--ghost btn--icon" href="<?= $plugin['downloadUrl'] ?>" title="Stáhnout ZIP pro ruční instalaci na nový web" aria-label="Stáhnout <?= $this->e((string) $plugin['name']) ?>"><?= get_icon('download', 'icon--sm') ?></a>
            <?php if ($plugin['removeAction'] !== null): ?>
                <form class="row-actions__form" method="post" action="<?= $plugin['removeAction'] ?>">
                    <?php render_csrf($csrfToken) ?>
                    <button type="submit" class="btn btn--ghost btn--icon" title="Odebrat z knihovny (na webech plugin zůstane)" aria-label="Odebrat <?= $this->e((string) $plugin['name']) ?> z knihovny"><?= get_icon('trash', 'icon--sm') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
