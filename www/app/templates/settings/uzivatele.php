<?php
/**
 * Nastavení → Uživatelé (návrh `nastaveni-uzivatele.html`) + karta
 * vlastního účtu (jméno, e-mail, fotka, heslo).
 *
 * Role je jen štítek — všichni mohou vše, jen na zakládající účet smí sahat
 * jen on sám (`UserRepository::canManage()`). Účty se nemažou, jen
 * pozastavují, aby zůstal čitelný auditní log. Dvoufázové přihlášení je
 * povinné, proto karta s ním nemá stav „vypnuto".
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<int, array<string, mixed>> $users řádky připravené v controlleru
 * @var string              $usersNote     „3 aktivních · 1 čeká na přijetí pozvánky"
 * @var array<string,string> $roles        kód => popisek
 * @var array               $me            řádek přihlášeného
 * @var array{note: string, recoveryLow: bool} $twoFactor dvoufázové přihlášení přihlášeného
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form();
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">

    <section class="card card--scroll-x">
        <?php render_card_head('Uživatelé', $usersNote) ?>

        <div class="table table--users">
            <div class="table__head"><div>Jméno</div><div>E-mail</div><div>Role</div><div>Poslední přihlášení</div><div>Stav</div><div class="table__cell table__cell--right">Akce</div></div>

            <?php foreach ($users as $u): ?>
                <div class="table__row">
                    <div class="table__cell row" style="flex-wrap:nowrap;gap:11px">
                        <?= $this->partial('partials/avatar', ['user' => $u, 'size' => 'sm']) ?>
                        <span style="min-width:0">
                            <span class="table__primary u-truncate" style="display:block"><?= $this->e($u['displayName']) ?></span>
                            <span class="table__secondary u-truncate" style="display:block;margin-top:0">
                                <span class="u-mono"><?= $this->e((string) $u['username']) ?></span>
                                <?php if ($u['isOwner']): ?> · <span title="Zakládající účet — upravit, pozastavit ani odpárovat ho nemůže nikdo jiný.">vlastník</span><?php endif; ?>
                                <?php if ($u['isSelf']): ?> · vy<?php endif; ?>
                            </span>
                        </span>
                    </div>
                    <div class="table__cell u-truncate text-secondary" style="font-size:var(--font-size-label)"><?= $this->e((string) ($u['email'] ?? '') !== '' ? (string) $u['email'] : '—') ?></div>
                    <div class="table__cell text-secondary" style="font-size:var(--font-size-label)"><?= $this->e($u['roleLabel']) ?></div>
                    <div class="table__cell text-subtle u-nowrap" style="font-size:var(--font-size-label)"><?= $this->e($u['lastLogin']) ?></div>
                    <div class="table__cell"><?= get_status($u['stateTone'], $u['stateLabel']) ?></div>
                    <div class="table__cell table__cell--right">
                        <div class="row-actions">
                            <?php if ($u['isInvited']): ?>
                                <form method="post" action="<?= get_url('nastaveni/uzivatele/' . (int) $u['id'] . '/pozvanka') ?>">
                                    <?php render_csrf($csrfToken) ?>
                                    <button type="submit" class="btn btn--ghost btn--icon" title="Poslat pozvánku znovu" aria-label="Poslat pozvánku znovu — <?= $this->e($u['displayName']) ?>"><?= get_icon('mail', 'icon--sm') ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($u['canUnpair']): ?>
                                <a class="btn btn--ghost btn--icon" href="<?= get_url('nastaveni/uzivatele') ?>" title="Zrušit spárování telefonu" aria-label="Zrušit spárování telefonu — <?= $this->e($u['displayName']) ?>" data-confirm="two-factor-reset" data-confirm-action="<?= get_url('nastaveni/uzivatele/' . (int) $u['id'] . '/dvoufazove/vypnout') ?>" data-confirm-title="Zrušit spárování telefonu účtu <?= $this->e($u['displayName']) ?>?"><?= get_icon('shield', 'icon--sm') ?></a>
                            <?php endif; ?>
                            <?php if ($u['canSuspend']): ?>
                                <form method="post" action="<?= get_url('nastaveni/uzivatele/' . (int) $u['id'] . ($u['isActiveFlag'] ? '/pozastavit' : '/obnovit')) ?>">
                                    <?php render_csrf($csrfToken) ?>
                                    <button type="submit" class="btn btn--ghost btn--icon" title="<?= $u['isActiveFlag'] ? 'Pozastavit účet' : 'Obnovit účet' ?>" aria-label="<?= $this->e(($u['isActiveFlag'] ? 'Pozastavit účet ' : 'Obnovit účet ') . $u['displayName']) ?>"><?= get_icon($u['isActiveFlag'] ? 'power' : 'refresh', 'icon--sm') ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($u['editUrl'] !== ''): ?>
                                <a class="btn btn--ghost btn--icon" href="<?= $u['editUrl'] ?>" title="Upravit uživatele" aria-label="Upravit uživatele <?= $this->e($u['displayName']) ?>"><?= get_icon('edit', 'icon--sm') ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="split split--settings">
        <div class="stack">
            <section class="card card--padded">
                <form method="post" action="<?= get_url('nastaveni/uzivatele') ?>" class="form">
                    <?php render_csrf($csrfToken) ?>
                    <div>
                        <div class="card__title">Pozvat uživatele</div>
                        <div class="card__note">Na e-mail odejde odkaz pro nastavení hesla — žádné heslo se nikam nepíše.</div>
                    </div>
                    <div class="form__row">
                        <?= $form->text('new_name', 'Jméno', '', attributes: ['placeholder' => 'Petra Kolářová'], class: 'form__field--caps') ?>
                        <?= $form->text('new_username', 'Přihlašovací jméno', '', attributes: ['placeholder' => 'petra', 'class' => 'form__control--mono'], class: 'form__field--caps') ?>
                        <?= $form->text('new_email', 'E-mail', '', type: 'email', attributes: ['placeholder' => 'petra@mediagrafik.cz'], class: 'form__field--caps') ?>
                        <?= $form->select('new_role', 'Role', $roles, 'technician', hint: 'Jen štítek v seznamu — všichni mohou vše.', class: 'form__field--caps') ?>
                    </div>
                    <div class="row"><button type="submit" class="btn btn--primary"><?= get_btn_icon('plus') ?>Pozvat uživatele</button></div>
                </form>
            </section>

            <section class="card card--padded" id="muj-ucet">
                <?php // Jeden formulář, jedno tlačítko. Multipart kvůli fotce; fotka
                      // a heslo se mění, jen když jsou vyplněné. „Odebrat fotku"
                      // míří atributem `form` do samostatného formuláře pod kartou. ?>
                <form method="post" enctype="multipart/form-data" action="<?= get_url('nastaveni/ucet') ?>" class="form">
                    <?php render_csrf($csrfToken) ?>
                    <div>
                        <div class="card__title">Můj účet</div>
                        <div class="card__note">E-mail slouží k obnově zapomenutého hesla — bez něj funguje jen obnova přes příkazovou řádku.</div>
                    </div>

                    <div class="form__row">
                        <?= $form->text('name', 'Jméno', (string) ($me['name'] ?? ''), attributes: ['placeholder' => 'Petra Kolářová'], class: 'form__field--caps') ?>
                        <?= $form->text('username', 'Přihlašovací jméno', (string) ($me['username'] ?? ''), attributes: ['required' => true, 'autocomplete' => 'username', 'class' => 'form__control--mono'], class: 'form__field--caps') ?>
                        <?= $form->text('email', 'E-mail', (string) ($me['email'] ?? ''), type: 'email', attributes: ['autocomplete' => 'email'], class: 'form__field--caps') ?>
                        <?= $form->select('role', 'Role', $roles, (string) ($me['role'] ?? 'admin'), hint: 'Jen štítek v seznamu — všichni mohou vše.', class: 'form__field--caps') ?>
                    </div>

                    <div class="row" style="flex-wrap:nowrap;align-items:flex-start;gap:16px">
                        <?= $this->partial('partials/avatar', ['user' => $me]) ?>
                        <div class="form__field" style="flex:1">
                            <span class="form__label form__label--caps">Profilová fotka</span>
                            <input class="form__control" type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Soubor s fotkou">
                            <span class="form__hint">JPG, PNG, WebP nebo GIF do 4 MB — ořízne se na čtverec. Nevybraný soubor fotku nemění.</span>
                            <?php if (($me['avatar'] ?? '') !== ''): ?>
                                <div class="row"><button type="submit" class="btn btn--ghost btn--sm" form="avatar-remove"><?= get_btn_icon('trash') ?>Odebrat fotku</button></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form__row">
                        <?= $form->text('password', 'Nové heslo', type: 'password', attributes: ['autocomplete' => 'new-password'], class: 'form__field--caps') ?>
                        <?= $form->text('password_confirm', 'Nové heslo znovu', type: 'password', attributes: ['autocomplete' => 'new-password'], class: 'form__field--caps') ?>
                    </div>

                    <div class="row" style="justify-content:space-between">
                        <span class="form__hint">Heslo vyplňte, jen když ho měníte — aspoň 10 znaků. Změna hesla odhlásí všechny relace.</span>
                        <button type="submit" class="btn btn--primary">Uložit</button>
                    </div>
                </form>

                <?php if (($me['avatar'] ?? '') !== ''): ?>
                    <form id="avatar-remove" method="post" action="<?= get_url('nastaveni/ucet/fotka/smazat') ?>">
                        <?php render_csrf($csrfToken) ?>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card card--padded" id="dvoufazove">
                <div class="form">
                    <div class="row" style="justify-content:space-between;align-items:flex-start">
                        <div>
                            <div class="card__title">Dvoufázové přihlášení</div>
                            <div class="card__note">Povinné pro všechny účty — po hesle ještě kód z aplikace v telefonu.</div>
                        </div>
                        <?= get_status('ok', 'Zapnuto') ?>
                    </div>

                    <div class="text-subtle" style="font-size:var(--font-size-label)"><?= $this->e($twoFactor['note']) ?></div>
                    <?php if ($twoFactor['recoveryLow']): ?>
                        <?php render_notice($this, 'warning', message: 'Záložních kódů zbývá málo — vytvořte si novou sadu.') ?>
                    <?php endif; ?>

                    <?php // Obě akce chtějí aktuální kód; jedno pole, tlačítko určí adresu (`formaction`). ?>
                    <form method="post" action="<?= get_url('nastaveni/dvoufazove/kody') ?>" class="form">
                        <?php render_csrf($csrfToken) ?>
                        <?= $form->text('code', 'Kód z aplikace', hint: 'Nové záložní kódy i nový telefon chtějí aktuální kód (nebo záložní kód).', attributes: [
                            'required' => true,
                            'autocomplete' => 'one-time-code',
                            'maxlength' => '20',
                            'class' => 'form__control--mono',
                        ], class: 'form__field--caps two-factor-code') ?>
                        <div class="row">
                            <button type="submit" class="btn btn--secondary">Nové záložní kódy</button>
                            <button type="submit" class="btn btn--ghost" formaction="<?= get_url('nastaveni/dvoufazove/vypnout') ?>">Spárovat nový telefon</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Kdo co vidí</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed);text-wrap:pretty">Role je jen štítek pro orientaci v malém týmu — každý přihlášený může všechno. Výjimkou je zakládající účet (vlastník): upravit, pozastavit ani odpárovat ho nemůže nikdo jiný. Pozastavený účet se nemůže přihlásit, jeho stopa v historii zůstává.</div>
            </div>
        </aside>
    </div>
</div>

<?php // Zrušení spárování telefonu kolegovi — adresu a jméno doplní confirm-dialog.js z odkazu v řádku. ?>
<dialog class="modal modal--danger" id="two-factor-reset" aria-labelledby="two-factor-reset-title">
    <form class="modal__body" method="post" action="">
        <?php render_csrf($csrfToken) ?>
        <div>
            <div class="card__title modal__title" id="two-factor-reset-title" data-confirm-title>Zrušit spárování telefonu?</div>
            <div class="card__note">Jen když kolega ztratil telefon i záložní kódy. Po přihlášení heslem ho aplikace nepustí dál, dokud si nespáruje nový telefon.</div>
        </div>
        <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-dialog-close>Zrušit</button>
            <button type="submit" class="btn btn--danger">Zrušit spárování</button>
        </div>
    </form>
</dialog>
