<?php
/**
 * Kontaktní osoba — nová i úprava. Jméno, e-mail a telefon platí u všech
 * klientů osoby; role se vyplňuje u každého klienta zvlášť (karta Klienti).
 * Nová osoba jde rovnou připojit k jednomu klientovi.
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var array|null           $contact    null = nová osoba
 * @var array<string, mixed> $values     first_name, last_name, email, phone, client_id, role, roles[client_id]
 * @var array<string,string> $errors
 * @var array<int, array{client_id: int, name: string, role: string, is_primary: int, url: string}> $clients klienti osoby
 * @var array<int, string>   $clientOptions klienti do výběru u nové osoby
 * @var string               $sameUrl    odkaz na osobu se stejným jménem a e-mailem ('' = není)
 * @var string               $previewName
 * @var string               $deleteBlocked proč osobu nejde smazat ('' = jde)
 * @var string               $deleteNote
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form($errors, array_key_first($errors));
$action = $contact === null ? get_url('klienti/kontakty/pridat') : get_url('klienti/kontakty/' . (int) $contact['id']);
?>
<form method="post" action="<?= $action ?>" id="contact-form">
<header class="page-header">
    <div class="page-header__breadcrumb"><a href="<?= get_url('klienti') ?>">Klienti</a><span>/</span><a href="<?= get_url('klienti/kontakty') ?>">Kontaktní osoby</a><span>/</span><span><?= $this->e($title) ?></span></div>
    <div class="page-header__top">
        <div class="row" style="flex-wrap:nowrap;gap:16px;min-width:0">
            <?= get_avatar($previewName) ?>
            <div style="min-width:0">
                <h1 class="page-header__title"><?= $this->e($title) ?></h1>
                <div class="page-header__meta">
                    <?php if ($contact === null): ?>
                        <span>Osoba se zapíše jednou a připojí k libovolnému počtu klientů.</span>
                    <?php else: ?>
                        <span><?= $this->e(get_count(count($clients), 'klient', 'klienti', 'klientů')) ?></span>
                        <span>upraveno <?= $this->e(get_when((string) $contact['updated_at'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--ghost" href="<?= get_url('klienti/kontakty') ?>">Zrušit</a>
            <button class="btn btn--primary" type="submit"><?= get_btn_icon('check') ?>Uložit osobu</button>
        </div>
    </div>
</header>

<div class="app__content">
    <?php render_csrf($csrfToken) ?>

    <?php if ($sameUrl !== ''): ?>
        <?php render_notice($this, 'warning', 'Tahle osoba už v kontaktech je', 'Místo nové ji otevřete a připojte ke klientovi v jeho Dalších kontaktech.', [], $sameUrl, 'Otevřít osobu') ?>
    <?php elseif ($errors !== []): ?>
        <?php render_notice($this, 'error', 'Formulář má ' . get_count(count($errors), 'chybu', 'chyby', 'chyb'), 'Doplňte označená pole a uložte znovu.') ?>
    <?php endif; ?>

    <div class="split split--settings">
        <div class="stack">
            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Osoba</div>
                        <div class="card__note"><?= count($clients) > 1 ? 'Změna jména, e-mailu nebo telefonu platí u všech klientů níže.' : 'Stačí příjmení, nebo e-mail. Zbytek jde doplnit později.' ?></div>
                    </div>
                    <div class="form__row">
                        <?= $form->text('first_name', 'Jméno', (string) $values['first_name'], class: 'form__field--caps', attributes: ['placeholder' => 'Markéta']) ?>
                        <?= $form->text('last_name', 'Příjmení', (string) $values['last_name'], class: 'form__field--caps', attributes: ['placeholder' => 'Dobrá']) ?>
                        <?= $form->text('phone', 'Telefon', (string) $values['phone'], type: 'tel', class: 'form__field--caps', attributes: ['placeholder' => '+420 …', 'class' => 'form__control--mono'], measure: 'none') ?>
                        <?= $form->text('email', 'E-mail', (string) $values['email'], type: 'email', class: 'form__field--caps', attributes: ['placeholder' => 'jmeno@firma.cz'], measure: 'none') ?>
                    </div>
                </div>
            </section>

            <?php if ($contact === null): ?>
                <section class="card card--padded">
                    <div class="form">
                        <div>
                            <div class="card__title">Klient<span class="form__label-optional" style="font-size:var(--font-size-label)"> · nepovinné</span></div>
                            <div class="card__note">K dalším klientům osobu připojíte v jejich detailu (Další kontakty).</div>
                        </div>
                        <div class="form__row">
                            <?= $form->select('client_id', 'Připojit ke klientovi', $clientOptions, (string) $values['client_id'], '— zatím k žádnému —', class: 'form__field--caps') ?>
                            <?= $form->text('role', 'Role u klienta', (string) $values['role'], class: 'form__field--caps', attributes: ['placeholder' => 'účetní, správce IT…'], keyLabel: '· nepovinné') ?>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <section class="card">
                    <?php render_card_head('Klienti', 'Role se vyplňuje u každého klienta zvlášť') ?>
                    <?php if ($clients === []): ?>
                        <?php render_empty('Osoba není u žádného klienta', 'Připojíte ji v detailu klienta, v kartě Další kontakty.', 'users') ?>
                    <?php else: ?>
                        <div class="table table--contact-clients">
                            <div class="table__head"><div>Klient</div><div>Role</div><div>Kontakt</div></div>
                            <?php foreach ($clients as $client): ?>
                                <div class="table__row">
                                    <div class="table__cell row" style="flex-wrap:nowrap;gap:11px">
                                        <?= get_avatar($client['name'], '', 'sm') ?>
                                        <a class="table__primary u-truncate" href="<?= $client['url'] ?>"><?= $this->e($client['name']) ?></a>
                                    </div>
                                    <div class="table__cell">
                                        <input type="text" name="roles[<?= (int) $client['client_id'] ?>]" value="<?= $this->e($client['role']) ?>" maxlength="100" class="form__control" placeholder="jednatel, účetní…" aria-label="Role u <?= $this->e($client['name']) ?>">
                                    </div>
                                    <div class="table__cell"><?= $client['is_primary'] === 1 ? get_status('ok', 'Hlavní') : '<span class="text-subtle">Další</span>' ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="split__aside">
            <div class="card card--note" style="font-size:var(--font-size-label);line-height:var(--line-height-relaxed);color:var(--color-text-secondary)">Osoba je v aplikaci jen jednou. Kdo jedná za víc firem, má jeden záznam — nový telefon nebo e-mail stačí přepsat tady a platí všude.</div>

            <?php if ($contact !== null): ?>
                <div class="card card--danger">
                    <div style="font-weight:var(--font-weight-semibold);color:var(--color-status-error-text)">Smazat osobu</div>
                    <?php if ($deleteBlocked !== ''): ?>
                        <div class="text-caption" style="margin-top:4px"><?= $this->e($deleteBlocked) ?></div>
                    <?php else: ?>
                        <div class="text-caption" style="margin-top:4px"><?= $this->e($deleteNote) ?></div>
                        <a class="btn btn--danger btn--sm" style="margin-top:12px" href="<?= get_url('klienti/kontakty/' . (int) $contact['id']) ?>" data-confirm="contact-delete" data-confirm-action="<?= get_url('klienti/kontakty/' . (int) $contact['id'] . '/smazat') ?>" data-confirm-title="<?= $this->e('Smazat osobu ' . $title . '?') ?>" data-confirm-note="<?= $this->e($deleteNote) ?>"><?= get_btn_icon('trash') ?>Smazat osobu</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
</form>

<?= $this->partial('partials/contact-delete-dialog', ['csrfToken' => $csrfToken]) ?>
