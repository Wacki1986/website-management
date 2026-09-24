<?php
/**
 * Knihovna pluginů — placené a vlastní pluginy mimo wordpress.org (návrh
 * stránku nemá; skládá se jako seznam klientů: hlavička, karta, tabulka).
 *
 * Nahrání ZIPu nahradí předchozí verzi. Weby s pluginem MEDIAGRAFIK
 * Monitor od verze `$since` si novou verzi nabídnou k aktualizaci samy.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<int, array<string, mixed>> $rows  plugin knihovny + `sites` (id, name, version, outdated, url),
 *                                              `outdated`, `sizeLabel`, `uploaded`, `downloadUrl`, `removeAction`
 * @var string              $since      od které verze MEDIAGRAFIK Monitoru weby knihovnu znají
 * @var string              $maxUpload  limit nahrávání na hostingu (upload_max_filesize)
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?php render_page_head('Knihovna pluginů', $this->e('Placené a vlastní pluginy, které nejsou na wordpress.org — od každého jen nejnovější verze')) ?>

<div class="app__content">
    <div class="split split--settings">
        <form method="post" action="<?= get_url('knihovna') ?>" enctype="multipart/form-data" class="card card--padded form" data-pending>
            <?php render_csrf($csrfToken) ?>
            <div>
                <div class="card__title">Nahrát plugin</div>
                <div class="card__note">ZIP tak, jak ho vydává autor (nebo jak jde nahrát ve wp-admin). Název a verzi si správa přečte z pluginu sama; novější verze nahradí starší.</div>
            </div>
            <div class="row" style="gap:10px;flex-wrap:wrap">
                <input class="form__control" type="file" name="plugin" accept=".zip,application/zip" required aria-label="ZIP s pluginem" style="flex:1;min-width:240px">
                <button type="submit" class="btn btn--primary" data-pending-label="Nahrávám…"><?= get_btn_icon('plus') ?>Nahrát do knihovny</button>
            </div>
            <div class="form__hint">Hosting dovolí nahrát nejvýš <?= $this->e($maxUpload) ?>.</div>
        </form>

        <aside class="split__aside">
            <div class="card card--note">Weby si novou verzi nabídnou k aktualizaci samy (ve správě i ve wp-admin), když mají plugin MEDIAGRAFIK Monitor <?= $this->e($since) ?> nebo novější. ZIPy nejsou veřejně ke stažení — každý web dostane odkaz podepsaný jen pro sebe.</div>
        </aside>
    </div>

    <section class="card card--scroll-x">
        <?php render_card_head('Pluginy v knihovně', get_count(count($rows), 'plugin', 'pluginy', 'pluginů')) ?>

        <?php if ($rows === []): ?>
            <?php render_empty('Knihovna je prázdná', 'Nahrajte první plugin — třeba placený, ke kterému máte licenci, ale WordPress ho sám aktualizovat neumí.', 'plugin') ?>
        <?php else: ?>
            <div class="table table--library">
                <div class="table__head"><div>Plugin</div><div>Verze</div><div>Na webech</div><div>Nahráno</div><div class="table__cell table__cell--right">Akce</div></div>
                <?php foreach ($rows as $plugin): ?>
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
                            <?php if ($plugin['sites'] === []): ?>
                                <span class="text-faint">zatím na žádném webu</span>
                            <?php else: ?>
                                <?= $plugin['outdated'] > 0 ? get_status('warning', get_count($plugin['outdated'], 'web čeká', 'weby čekají', 'webů čeká') . ' na aktualizaci') : get_status('ok', 'všude aktuální') ?>
                                <div class="table__secondary" style="line-height:var(--line-height-relaxed)">
                                    <?php foreach ($plugin['sites'] as $i => $site): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= $site['url'] ?>"><?= $this->e($site['name']) ?></a><?php if ($site['outdated']): ?> <span class="text-warning u-mono"><?= $this->e($site['version']) ?></span><?php endif; ?><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="table__cell text-secondary" style="font-size:var(--font-size-label)"><?= $this->e($plugin['uploaded']) ?></div>
                        <div class="table__cell table__cell--right">
                            <form class="row-actions" method="post" action="<?= $plugin['removeAction'] ?>">
                                <?php render_csrf($csrfToken) ?>
                                <a class="btn btn--ghost btn--icon" href="<?= $plugin['downloadUrl'] ?>" title="Stáhnout ZIP pro ruční instalaci na nový web" aria-label="Stáhnout <?= $this->e((string) $plugin['name']) ?>"><?= get_icon('download', 'icon--sm') ?></a>
                                <button type="submit" class="btn btn--ghost btn--icon" title="Odebrat z knihovny (na webech plugin zůstane)" aria-label="Odebrat <?= $this->e((string) $plugin['name']) ?> z knihovny"><?= get_icon('trash', 'icon--sm') ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
