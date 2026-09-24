<?php
/**
 * Detail webu — Přístupy (návrh ji nemá): trezor s FTP, hostingem,
 * databází a dalšími přihlašovacími údaji.
 *
 * Heslo ve stránce není — místo něj tečky; oko a kopírování si ho dotáhnou
 * z `…/heslo` (`vault.js`). Akce v řádku jsou ikony v posledním sloupci.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var bool                $locked    přihlášený účet nemá dvoufázové přihlášení
 * @var bool                $available je `app_key` (čím šifrovat)
 * @var array<int, array<string, mixed>> $rows řádky z `CredentialController::row()`
 * @var array<int, array{label: string, url: string}> $addLinks přidání po druzích
 * @var string              $setupUrl  zapnutí dvoufázového přihlášení
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Přístupy']);
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <?php if ($locked): ?>
        <section class="card card--padded">
            <?php render_empty(
                'Trezor je zamčený',
                'Přístupy k FTP, hostingu a databázím uvidí jen účet se zapnutým dvoufázovým přihlášením — samotné heslo do správy na ně nestačí.',
                'shield',
                '<a class="btn btn--primary" href="' . $setupUrl . '">' . get_btn_icon('shield') . 'Zapnout dvoufázové přihlášení</a>',
            ) ?>
        </section>
    <?php elseif (!$available): ?>
        <?php render_notice($this, 'error', 'Chybí app_key v config/env.php', 'Bez něj se přístupy nedají bezpečně uložit ani přečíst. Vygenerujte ho příkazem php dev/tools/generate-tokens.php a doplňte na server.') ?>
    <?php else: ?>
        <section class="card card--scroll-x">
            <?php render_card_head('Přístupy', 'Hesla jsou uložená šifrovaně. Oko je ukáže, tlačítko vedle zkopíruje, aniž by se ukázala.') ?>

            <?php if ($rows === []): ?>
                <?php render_empty('Zatím žádný přístup', 'Uložte si sem FTP, přihlášení do hostingu nebo k databázi — ať je nemusíte hledat po e-mailech a souborech.', 'shield') ?>
            <?php else: ?>
                <div class="table table--credentials">
                    <div class="table__head"><div>Přístup</div><div>Server / adresa</div><div>Uživatel</div><div>Heslo</div><div class="table__cell table__cell--right">Akce</div></div>
                    <?php foreach ($rows as $row): ?>
                        <div class="table__row">
                            <div class="table__cell row" style="flex-wrap:nowrap;gap:10px">
                                <?= get_icon($row['icon'], 'icon--subtle') ?>
                                <span style="min-width:0">
                                    <span class="table__primary u-truncate" style="display:block"><?= $this->e($row['title']) ?></span>
                                    <?php if ($row['subline'] !== ''): ?>
                                        <span class="table__secondary u-truncate" style="display:block;margin-top:0" title="<?= $this->e($row['note']) ?>"><?= $this->e($row['subline']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="table__cell u-truncate u-mono" style="font-size:var(--font-size-label)">
                                <?php if ($row['openUrl'] !== ''): ?>
                                    <a href="<?= $this->e($row['openUrl']) ?>" target="_blank" rel="noopener noreferrer"><?= $this->e($row['server']) ?></a>
                                <?php else: ?>
                                    <?= $this->e($row['server'] !== '' ? $row['server'] : '—') ?>
                                <?php endif; ?>
                            </div>
                            <div class="table__cell credential-value">
                                <?php if ($row['username'] !== ''): ?>
                                    <span class="u-truncate u-mono" title="<?= $this->e($row['username']) ?>"><?= $this->e($row['username']) ?></span>
                                    <button type="button" class="btn btn--ghost btn--icon" data-copy="<?= $this->e($row['username']) ?>" title="Zkopírovat uživatele" aria-label="Zkopírovat uživatele <?= $this->e($row['title']) ?>"><?= get_icon('copy', 'icon--sm') ?></button>
                                <?php else: ?>
                                    <span class="text-subtle">—</span>
                                <?php endif; ?>
                            </div>
                            <div class="table__cell credential-value" data-secret>
                                <?php if ($row['hasPassword']): ?>
                                    <span class="u-truncate u-mono credential-value__secret" data-secret-value>••••••••</span>
                                    <button type="button" class="btn btn--ghost btn--icon" data-secret-reveal="<?= $row['passwordUrl'] ?>" title="Ukázat heslo" aria-label="Ukázat heslo <?= $this->e($row['title']) ?>" aria-pressed="false"><?= get_icon('eye', 'icon--sm') ?></button>
                                    <button type="button" class="btn btn--ghost btn--icon" data-secret-copy="<?= $row['passwordUrl'] ?>" title="Zkopírovat heslo" aria-label="Zkopírovat heslo <?= $this->e($row['title']) ?>"><?= get_icon('copy', 'icon--sm') ?></button>
                                <?php else: ?>
                                    <span class="text-subtle">neuloženo</span>
                                <?php endif; ?>
                            </div>
                            <div class="table__cell table__cell--right">
                                <div class="row-actions">
                                    <?php if ($row['fileZilla']): ?>
                                        <button type="button" class="btn btn--ghost btn--icon" data-secret-copy="<?= $row['passwordUrl'] ?>" data-secret-format="filezilla" title="Zkopírovat pro FileZillu — vložte do pole Hostitel v Rychlém připojení" aria-label="Zkopírovat adresu pro FileZillu — <?= $this->e($row['title']) ?>"><?= get_icon('server', 'icon--sm') ?></button>
                                    <?php endif; ?>
                                    <a class="btn btn--ghost btn--icon" href="<?= $row['editUrl'] ?>" title="Upravit" aria-label="Upravit <?= $this->e($row['title']) ?>"><?= get_icon('edit', 'icon--sm') ?></a>
                                    <a class="btn btn--ghost btn--icon" href="<?= $row['editUrl'] ?>" title="Smazat" aria-label="Smazat <?= $this->e($row['title']) ?>" data-confirm="credential-delete" data-confirm-action="<?= $row['deleteUrl'] ?>" data-confirm-title="Smazat přístup <?= $this->e($row['title']) ?>?"><?= get_icon('trash', 'icon--sm') ?></a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card__footer row" style="gap:8px;flex-wrap:wrap;justify-content:flex-start">
                <span class="text-subtle" style="font-size:var(--font-size-label)">Přidat:</span>
                <?php foreach ($addLinks as $link): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= $link['url'] ?>"><?= get_btn_icon('plus') ?><?= $this->e($link['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="card card--note">Hesla se nikdy neposílají e-mailem, nejsou v reportech ani v exportech. Při odebrání webu z monitoringu se přístupy smažou.</div>
    <?php endif; ?>
</div>

<dialog class="modal modal--danger" id="credential-delete" aria-labelledby="credential-delete-title">
    <form class="modal__body" method="post" action="" data-pending>
        <?php render_csrf($csrfToken) ?>
        <div>
            <div class="card__title modal__title" id="credential-delete-title" data-confirm-title>Smazat přístup?</div>
            <div class="card__note">Přístup i s heslem se ze správy smaže natrvalo.</div>
        </div>
        <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-dialog-close>Zrušit</button>
            <button type="submit" class="btn btn--danger" data-pending-label="Mažu…"><?= get_btn_icon('trash') ?>Smazat přístup</button>
        </div>
    </form>
</dialog>
