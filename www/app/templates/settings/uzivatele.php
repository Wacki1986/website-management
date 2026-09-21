<?php
/**
 * Nastavení → Uživatelé (návrh `nastaveni-uzivatele.html`) + karta
 * vlastního účtu (jméno, e-mail, fotka, heslo).
 *
 * Role je jen štítek — všichni mohou vše. Účty se nemažou, jen pozastavují,
 * aby zůstal čitelný auditní log.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<int, array<string, mixed>> $users řádky připravené v controlleru
 * @var string              $usersNote     „3 aktivních · 1 čeká na přijetí pozvánky"
 * @var array<string,string> $roles        kód => popisek
 * @var int                 $currentUserId
 * @var int|null            $firstUserId   zakládající účet — nejde pozastavit
 * @var array               $me            řádek přihlášeného
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
            <div class="table__head"><div>Jméno</div><div>E-mail</div><div>Role</div><div>Poslední přihlášení</div><div>Stav</div><div></div></div>

            <?php foreach ($users as $u): ?>
                <div class="table__row">
                    <div class="table__cell row" style="flex-wrap:nowrap;gap:11px">
                        <?= $this->partial('partials/avatar', ['user' => $u, 'size' => 'sm']) ?>
                        <span style="min-width:0">
                            <span class="table__primary u-truncate" style="display:block"><?= $this->e($u['displayName']) ?></span>
                            <span class="table__secondary u-truncate u-mono" style="display:block;margin-top:0"><?= $this->e((string) $u['username']) ?></span>
                        </span>
                    </div>
                    <div class="table__cell u-truncate text-secondary" style="font-size:var(--font-size-label)"><?= $this->e((string) ($u['email'] ?? '') !== '' ? (string) $u['email'] : '—') ?></div>
                    <div class="table__cell">
                        <?php // Role se mění výběrem přímo v řádku — bez JS se potvrdí tlačítkem. ?>
                        <form method="post" action="<?= get_url('nastaveni/uzivatele/' . (int) $u['id'] . '/role') ?>" class="row" style="flex-wrap:nowrap;gap:6px">
                            <?php render_csrf($csrfToken) ?>
                            <select name="role" class="form__control form__control--inline" style="padding:5px 9px;font-size:var(--font-size-label)" aria-label="Role">
                                <?php foreach ($roles as $code => $label): ?>
                                    <option value="<?= $this->e($code) ?>"<?= ($u['role'] ?? 'admin') === $code ? ' selected' : '' ?>><?= $this->e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn--secondary btn--icon" title="Uložit roli"><?= get_icon('check', 'icon--sm') ?></button>
                        </form>
                    </div>
                    <div class="table__cell text-subtle u-nowrap" style="font-size:var(--font-size-label)"><?= $this->e($u['lastLogin']) ?></div>
                    <div class="table__cell"><?= get_status($u['stateTone'], $u['stateLabel']) ?></div>
                    <div class="table__cell table__cell--right row" style="justify-content:flex-end;gap:6px">
                        <?php if ($u['isInvited']): ?>
                            <form method="post" action="<?= get_url('nastaveni/uzivatele/' . (int) $u['id'] . '/pozvanka') ?>">
                                <?php render_csrf($csrfToken) ?>
                                <button type="submit" class="btn btn--secondary btn--sm">Poslat znovu</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((int) $u['id'] === $firstUserId): ?>
                            <span class="text-caption" title="Zakládající účet — nejde pozastavit, ať ho nemůže zamknout druhý přidaný účet.">vlastník</span>
                        <?php elseif ((int) $u['id'] === $currentUserId): ?>
                            <span class="text-caption">vy</span>
                        <?php elseif ($u['isActiveFlag']): ?>
                            <form method="post" action="<?= get_url('nastaveni/uzivatele/' . (int) $u['id'] . '/pozastavit') ?>">
                                <?php render_csrf($csrfToken) ?>
                                <button type="submit" class="btn btn--ghost btn--sm">Pozastavit</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= get_url('nastaveni/uzivatele/' . (int) $u['id'] . '/obnovit') ?>">
                                <?php render_csrf($csrfToken) ?>
                                <button type="submit" class="btn btn--secondary btn--sm">Obnovit</button>
                            </form>
                        <?php endif; ?>
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

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Můj účet</div>
                        <div class="card__note">E-mail slouží k obnově zapomenutého hesla — bez něj funguje jen obnova přes příkazovou řádku.</div>
                    </div>

                    <form method="post" action="<?= get_url('nastaveni/ucet') ?>" class="form">
                        <?php render_csrf($csrfToken) ?>
                        <div class="form__row">
                            <?= $form->text('name', 'Jméno', (string) ($me['name'] ?? ''), attributes: ['placeholder' => 'Petra Kolářová'], class: 'form__field--caps') ?>
                            <?= $form->text('email', 'E-mail', (string) ($me['email'] ?? ''), type: 'email', attributes: ['autocomplete' => 'email'], class: 'form__field--caps') ?>
                        </div>
                        <div class="row"><button type="submit" class="btn btn--secondary">Uložit údaje</button></div>
                    </form>

                    <?php // Fotka nahradí kolečko s iniciálami. Nahrání potřebuje multipart,
                          // mazání ne — proto dva formuláře; tlačítko „Odebrat" míří do
                          // druhého atributem `form`. ?>
                    <form method="post" enctype="multipart/form-data" action="<?= get_url('nastaveni/ucet/fotka') ?>" class="form">
                        <?php render_csrf($csrfToken) ?>
                        <div class="row" style="flex-wrap:nowrap;align-items:flex-start;gap:16px">
                            <?= $this->partial('partials/avatar', ['user' => $me]) ?>
                            <div class="form__field" style="flex:1">
                                <span class="form__label form__label--caps">Profilová fotka</span>
                                <input class="form__control" type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Soubor s fotkou">
                                <span class="form__hint">JPG, PNG, WebP nebo GIF do 4 MB — ořízne se na čtverec.</span>
                                <div class="row">
                                    <button type="submit" class="btn btn--secondary btn--sm">Nahrát fotku</button>
                                    <?php if (($me['avatar'] ?? '') !== ''): ?>
                                        <button type="submit" class="btn btn--ghost btn--sm" form="avatar-remove"><?= get_btn_icon('trash') ?>Odebrat</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>
                    <?php if (($me['avatar'] ?? '') !== ''): ?>
                        <form id="avatar-remove" method="post" action="<?= get_url('nastaveni/ucet/fotka/smazat') ?>">
                            <?php render_csrf($csrfToken) ?>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="<?= get_url('nastaveni/heslo') ?>" class="form">
                        <?php render_csrf($csrfToken) ?>
                        <div class="form__row">
                            <?= $form->text('password', 'Nové heslo', type: 'password', attributes: ['required' => true, 'autocomplete' => 'new-password'], class: 'form__field--caps') ?>
                            <?= $form->text('password_confirm', 'Nové heslo znovu', type: 'password', attributes: ['required' => true, 'autocomplete' => 'new-password'], class: 'form__field--caps') ?>
                        </div>
                        <div class="row" style="justify-content:space-between">
                            <span class="form__hint">Aspoň 10 znaků. Změna odhlásí všechny relace.</span>
                            <button type="submit" class="btn btn--secondary">Změnit heslo</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Kdo co vidí</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed);text-wrap:pretty">Role je jen štítek pro orientaci v malém týmu — každý přihlášený může všechno. Pozastavený účet se nemůže přihlásit, jeho stopa v historii zůstává.</div>
            </div>
        </aside>
    </div>
</div>
