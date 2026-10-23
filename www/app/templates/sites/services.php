<?php
/**
 * Detail webu — Služby (návrh `handoff-sluzby/detail-webu-sluzby.html`):
 * nasazené služby a procesy po spuštění webu. Řádek je tlačítko
 * s požadovaným novým stavem; skript `site-services.js` ho uloží na
 * pozadí a přepíše počty, bez skriptu se formulář odešle obyčejně.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var string              $action        adresa pro přepnutí služby / úkolu
 * @var array<int, array{key: string, label: string, items: array<int, array{name: string, label: string, on: bool, next: string}>}> $serviceGroups
 * @var string              $servicesBadge „5 z 8 nasazeno"
 * @var array<int, array{key: string, label: string, open: bool, badge: array{text: string, tone: string}, items: array<int, array{name: string, label: string, on: bool, next: string, meta: string}>}> $taskGroups
 * @var array{percent: int, count: string, note: string} $progress
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Služby']);
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content stack">
    <section class="card" data-services-card>
        <div class="card__header">
            <div>
                <h2 class="card__title">Nasazené služby</h2>
                <div class="card__note">Zaškrtněte, co na webu běží. Podle toho se zobrazují data v ostatních záložkách.</div>
            </div>
            <span class="badge" data-services-badge><?= $this->e($servicesBadge) ?></span>
        </div>
        <form class="service-grid" method="post" action="<?= $action ?>" data-site-services>
            <?php render_csrf($csrfToken) ?>
            <?php foreach ($serviceGroups as $group): ?>
                <div class="service-grid__group">
                    <div class="service-grid__label"><?= $this->e($group['label']) ?></div>
                    <?php foreach ($group['items'] as $item): ?>
                        <button class="service<?= $item['on'] ? ' service--on' : '' ?>" type="submit" name="<?= $this->e($item['name']) ?>" value="<?= $item['next'] ?>" role="checkbox" aria-checked="<?= $item['on'] ? 'true' : 'false' ?>" data-toggle="service"><span class="checkbox<?= $item['on'] ? ' checkbox--checked' : '' ?>"><span class="checkbox__mark"></span></span><?= $this->e($item['label']) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </form>
    </section>

    <section class="card">
        <div class="card__header">
            <div>
                <h2 class="card__title">Procesy po spuštění</h2>
                <div class="card__note" data-launch-note><?= $this->e($progress['note']) ?></div>
            </div>
            <div class="progress" style="--progress:<?= $progress['percent'] ?>%" data-launch-progress>
                <div class="progress__bar"><div class="progress__value"></div></div>
                <span class="progress__count"><?= $this->e($progress['count']) ?></span>
            </div>
        </div>
        <form class="launch-list" method="post" action="<?= $action ?>" data-site-services>
            <?php render_csrf($csrfToken) ?>
            <?php foreach ($taskGroups as $group): ?>
                <details class="launch-list__group" data-launch-group="<?= $this->e($group['key']) ?>"<?= $group['open'] ? ' open' : '' ?>>
                    <summary class="launch-list__summary">
                        <span class="launch-list__chevron"></span>
                        <span class="launch-list__title"><?= $this->e($group['label']) ?></span>
                        <?= get_badge($group['badge']['text'], $group['badge']['tone']) ?>
                    </summary>
                    <div class="launch-list__body">
                        <?php foreach ($group['items'] as $item): ?>
                            <button class="launch-list__item<?= $item['on'] ? ' launch-list__item--done' : '' ?>" type="submit" name="<?= $this->e($item['name']) ?>" value="<?= $item['next'] ?>" role="checkbox" aria-checked="<?= $item['on'] ? 'true' : 'false' ?>" data-toggle="task"><span class="checkbox<?= $item['on'] ? ' checkbox--checked' : '' ?>"><span class="checkbox__mark"></span></span><span class="launch-list__label"><?= $this->e($item['label']) ?></span><?php if ($item['meta'] !== ''): ?><span class="launch-list__meta"><?= $this->e($item['meta']) ?></span><?php endif; ?></button>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </form>
    </section>
</div>
