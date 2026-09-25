<?php
/**
 * Knihovna pluginů — placené a vlastní pluginy mimo wordpress.org (návrh
 * stránku nemá; skládá se jako seznam klientů: hlavička, karta, tabulka).
 *
 * Nahrání ZIPu nahradí předchozí verzi. Weby s pluginem MEDIAGRAFIK
 * Monitor od verze `$since` si novou verzi nabídnou k aktualizaci samy.
 *
 * „Aktualizovat všude" (ikona v Akcích) otevře okno se seznamem webů;
 * po potvrzení `library-update.js` pošle weby po jednom na jejich akci
 * aktualizace (jako záložka Pluginy webu) a průběh ukazuje přímo v okně —
 * kolečko, pak fajfka nebo křížek u každého webu. Po zavření se stránka
 * načte znovu s čerstvými verzemi.
 *
 * Weby ve sloupci jsou pilulky: oranžová se starší verzí, šedá aktuální.
 * U víc webů se šedé schovají za „+ N aktuálních" (rozbalí skript).
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<int, array<string, mixed>> $rows  plugin knihovny + `sites` (je vůbec na nějakém webu),
 *                                              `listedSites` (vypsané jménem) a `hiddenSites` (pod „+ N aktuálních", popisek `hiddenLabel`):
 *                                              id, name, version, url, outdated, blocked (proč ho „všude" přeskočí, null = ne),
 *                                              `outdated` (počet webů se starší verzí),
 *                                              `targets` / `skipped` (weby se starší verzí, které se aktualizují / přeskočí; mají `updateAction`),
 *                                              `dialogId`, `sizeLabel`, `uploaded`, `downloadUrl`, `removeAction`
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
            <div class="card card--note">Weby si novou verzi nabídnou k aktualizaci samy (ve správě i ve wp-admin), když mají plugin MEDIAGRAFIK Monitor <?= $this->e($since) ?> nebo novější. Ikonou „Aktualizovat všude" ji rozešlete na všechny weby najednou. ZIPy nejsou veřejně ke stažení — každý web dostane odkaz podepsaný jen pro sebe.</div>
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
                            <form class="row-actions" method="post" action="<?= $plugin['removeAction'] ?>">
                                <?php render_csrf($csrfToken) ?>
                                <?php if ($plugin['targets'] !== []): ?>
                                    <a class="btn btn--secondary btn--icon" href="#" data-confirm="<?= $this->e($plugin['dialogId']) ?>" title="Aktualizovat všude na <?= $this->e((string) $plugin['version']) ?>" aria-label="Aktualizovat <?= $this->e((string) $plugin['name']) ?> všude na <?= $this->e((string) $plugin['version']) ?>"><?= get_icon('refresh', 'icon--sm') ?></a>
                                <?php elseif ($plugin['skipped'] !== []): ?>
                                    <button type="button" class="btn btn--secondary btn--icon" disabled title="Žádný z webů se starší verzí teď nejde aktualizovat ze správy — důvod je u verze webu." aria-label="Aktualizovat všude teď nejde"><?= get_icon('refresh', 'icon--sm') ?></button>
                                <?php endif; ?>
                                <a class="btn btn--ghost btn--icon" href="<?= $plugin['downloadUrl'] ?>" title="Stáhnout ZIP pro ruční instalaci na nový web" aria-label="Stáhnout <?= $this->e((string) $plugin['name']) ?>"><?= get_icon('download', 'icon--sm') ?></a>
                                <button type="submit" class="btn btn--ghost btn--icon" title="Odebrat z knihovny (na webech plugin zůstane)" aria-label="Odebrat <?= $this->e((string) $plugin['name']) ?> z knihovny"><?= get_icon('trash', 'icon--sm') ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php foreach ($rows as $plugin): ?>
        <?php if ($plugin['targets'] !== []): ?>
            <dialog class="modal modal--wide" id="<?= $this->e($plugin['dialogId']) ?>" aria-labelledby="<?= $this->e($plugin['dialogId']) ?>-title">
                <form class="modal__body" method="dialog" data-library-update>
                    <?php render_csrf($csrfToken) ?>
                    <input type="hidden" name="plugin" value="<?= $this->e((string) $plugin['file']) ?>">
                    <div>
                        <div class="card__title modal__title" id="<?= $this->e($plugin['dialogId']) ?>-title">Aktualizovat <?= $this->e($plugin['name'] . ' na ' . $plugin['version']) ?></div>
                        <div class="card__note">Weby se aktualizují jeden po druhém. Nechte okno otevřené, dokud aktualizace neskončí — po zavření stránky se zbylé weby přeskočí (hotové zůstanou aktualizované).</div>
                    </div>
                    <div class="plugin-progress plugin-progress--inline" data-library-progress aria-live="polite" hidden>
                        <div data-library-progress-text>Připravuji aktualizaci…</div>
                        <div class="plugin-progress__bar"><div class="plugin-progress__fill" data-library-progress-fill></div></div>
                    </div>
                    <div>
                        <div class="form__label"><?= $this->e(get_count(count($plugin['targets']), 'web', 'weby', 'webů')) ?> k aktualizaci</div>
                        <ul class="progress-list">
                            <?php foreach ($plugin['targets'] as $site): ?>
                                <li class="progress-list__item" data-library-target data-action="<?= $site['updateAction'] ?>" data-name="<?= $this->e($site['name']) ?>">
                                    <span class="progress-list__icon" data-library-icon title="Čeká"></span>
                                    <span class="progress-list__name"><?= $this->e($site['name']) ?></span>
                                    <span class="progress-list__version u-mono" data-library-version><?= $this->e($site['version'] . ' → ' . $plugin['version']) ?></span>
                                    <span class="progress-list__note" data-library-note hidden></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($plugin['skipped'] !== []): ?>
                        <div>
                            <div class="form__label">Přeskočí se</div>
                            <ul class="progress-list">
                                <?php foreach ($plugin['skipped'] as $site): ?>
                                    <li class="progress-list__item progress-list__item--skipped">
                                        <span class="progress-list__icon"><?= get_icon('minus', 'icon--sm') ?></span>
                                        <span class="progress-list__name"><?= $this->e($site['name']) ?></span>
                                        <span class="progress-list__version u-mono"><?= $this->e($site['version']) ?></span>
                                        <span class="progress-list__note"><?= $this->e((string) $site['blocked']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div class="modal__actions">
                        <button type="button" class="btn btn--ghost" data-dialog-close data-library-close>Zrušit</button>
                        <button type="submit" class="btn btn--primary" data-library-start><?= get_btn_icon('refresh') ?>Aktualizovat na <?= $this->e(get_count(count($plugin['targets']), 'webu', 'webech', 'webech')) ?></button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
