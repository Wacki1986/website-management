<?php
/**
 * Nový klient / úprava klienta (návrh `novy-klient*.html`, `detail-klienta-uprava.html`).
 *
 * Jeden formulář pro založení i úpravu: liší se jen adresou a tím, že
 * u nového klienta jde rovnou přiřadit weby bez klienta. „Načíst z ARESu"
 * je submit s `_action=ares` — funguje bez JavaScriptu.
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var array|null           $client     null = nový
 * @var array<string, mixed> $values
 * @var array<string,string> $errors
 * @var array<int, array>    $unassigned weby bez klienta (jen u nového)
 * @var array<int, array{label: string, done: bool}> $tasks
 * @var string               $previewName
 * @var string               $previewContact
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form($errors, array_key_first($errors));
$action = $client === null ? get_url('klienti/pridat') : get_url('klienti/' . (int) $client['id'] . '/upravit');
$back = $client === null ? get_url('klienti') : get_url('klienti/' . (int) $client['id']);
?>
<form method="post" action="<?= $action ?>" id="client-form">
<header class="page-header">
    <div class="page-header__breadcrumb"><a href="<?= get_url('klienti') ?>">Klienti</a><span>/</span><span><?= $client === null ? 'Nový klient' : $this->e((string) $client['name']) ?></span></div>
    <div class="page-header__top">
        <div>
            <h1 class="page-header__title"><?= $this->e($title) ?></h1>
            <div class="page-header__meta">Povinné je jen jméno firmy, kontaktní osoba a e-mail. Zbytek jde doplnit později.</div>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--ghost" href="<?= $back ?>">Zrušit</a>
            <?php if ($client === null): ?>
                <button class="btn btn--secondary" type="submit" name="_action" value="save_and_site">Uložit a přidat web</button>
            <?php endif; ?>
            <button class="btn btn--primary" type="submit" name="_action" value="save"><?= get_btn_icon('check') ?>Uložit klienta</button>
        </div>
    </div>
</header>

<div class="app__content">
    <?php render_csrf($csrfToken) ?>

    <?php if ($errors !== []): ?>
        <?php render_notice($this, 'error', 'Formulář má ' . get_count(count($errors), 'chybu', 'chyby', 'chyb'), 'Doplňte označená pole a uložte znovu.') ?>
    <?php endif; ?>

    <div class="split split--settings">
        <div class="stack">
            <section class="card card--padded">
                <div class="form">
                    <div class="row" style="justify-content:space-between;align-items:flex-start">
                        <div>
                            <div class="card__title">Firma</div>
                            <div class="card__note">Zadejte IČO a údaje se doplní z rejstříku.</div>
                        </div>
                        <button class="btn btn--secondary btn--sm" type="submit" name="_action" value="ares"><?= get_btn_icon('search') ?>Načíst z ARESu</button>
                    </div>
                    <div class="form__row">
                        <?= $form->text('company_id', 'IČO', (string) $values['company_id'], class: 'form__field--caps', attributes: ['placeholder' => '12345678', 'class' => 'form__control--mono', 'inputmode' => 'numeric'], hint: 'Načteme název i adresu z ARESu.') ?>
                        <?= $form->text('vat_id', 'DIČ', (string) $values['vat_id'], class: 'form__field--caps', attributes: ['placeholder' => 'CZ12345678', 'class' => 'form__control--mono'], keyLabel: '· nepovinné') ?>
                        <?= $form->text('name', 'Název firmy', (string) $values['name'], class: 'form__field--caps form__field--12', attributes: ['placeholder' => 'Firma s.r.o. nebo jméno OSVČ'], span: 12) ?>
                        <?= $form->text('address', 'Fakturační adresa', (string) $values['address'], class: 'form__field--caps', attributes: ['placeholder' => 'Ulice 1, PSČ Město'], span: 12) ?>
                        <?= $form->text('billing_note', 'Fakturace', (string) $values['billing_note'], class: 'form__field--caps', attributes: ['placeholder' => 'Paušál 2 400 Kč / měsíc nebo hodinově'], span: 12, keyLabel: '· nepovinné') ?>
                        <?= $form->text('since', 'Spolupráce od', (string) $values['since'], type: 'date', class: 'form__field--caps', keyLabel: '· nepovinné') ?>
                    </div>
                </div>
            </section>

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Kontaktní osoba</div>
                        <div class="card__note">Komu voláme a píšeme. Reporty se nastavují až u jednotlivých webů.</div>
                    </div>
                    <div class="form__row">
                        <?= $form->text('first_name', 'Jméno', (string) $values['first_name'], class: 'form__field--caps', attributes: ['placeholder' => 'Markéta']) ?>
                        <?= $form->text('last_name', 'Příjmení', (string) $values['last_name'], class: 'form__field--caps', attributes: ['placeholder' => 'Dobrá']) ?>
                        <?= $form->text('role', 'Role', (string) $values['role'], class: 'form__field--caps', attributes: ['placeholder' => 'majitelka, jednatel…'], keyLabel: '· nepovinné') ?>
                        <?= $form->text('phone', 'Telefon', (string) $values['phone'], type: 'tel', class: 'form__field--caps', attributes: ['placeholder' => '+420 …', 'class' => 'form__control--mono'], measure: 'none') ?>
                        <?= $form->text('email', 'E-mail', (string) $values['email'], type: 'email', class: 'form__field--caps', attributes: ['placeholder' => 'jmeno@firma.cz'], span: 12, measure: 'none') ?>
                    </div>
                </div>
            </section>

            <?php if ($client === null): ?>
                <section class="card card--padded">
                    <div class="form">
                        <div class="row" style="justify-content:space-between;align-items:flex-start">
                            <div>
                                <div class="card__title">Weby klienta<span class="form__label-optional" style="font-size:var(--font-size-label)"> · nepovinné</span></div>
                                <div class="card__note">Přiřaďte weby, které už monitorujeme, nebo je přidejte později.</div>
                            </div>
                            <span class="text-subtle" style="font-size:var(--font-size-label)"><?= get_count(count($unassigned), 'web', 'weby', 'webů') ?> zatím bez klienta</span>
                        </div>
                        <?php if ($unassigned !== []): ?>
                            <div class="pick">
                                <?php foreach ($unassigned as $free): ?>
                                    <label class="pick__item<?= in_array((int) $free['id'], $values['sites'], true) ? ' pick__item--active' : '' ?>">
                                        <input type="checkbox" name="sites[]" value="<?= (int) $free['id'] ?>"<?= in_array((int) $free['id'], $values['sites'], true) ? ' checked' : '' ?> class="form__check-input">
                                        <?= get_avatar((string) $free['name'], '', 'sm') ?>
                                        <span style="flex:1;min-width:0"><span class="table__primary u-truncate" style="display:block;font-size:var(--font-size-label)"><?= $this->e((string) $free['name']) ?></span><span class="table__secondary u-truncate" style="display:block;margin-top:0"><?= $this->e(\App\Core\Sites\SiteRepository::host((string) $free['url'])) ?></span></span>
                                        <span class="text-caption u-nowrap">přidán <?= $this->e(get_czech_date((string) $free['created_at'])) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="card__footer" style="padding:14px 0 0">
                            <span class="text-subtle" style="font-size:var(--font-size-label)">Web není v seznamu?</span>
                            <span class="text-subtle" style="font-size:var(--font-size-label)">Uložte klienta tlačítkem „Uložit a přidat web".</span>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card card--padded">
                <?= $form->textarea('note', 'Interní poznámka', (string) $values['note'], class: 'form__field--caps', attributes: ['rows' => '4', 'placeholder' => 'Kdy volat, co neměnit bez domluvy, jak fakturovat…'], hint: 'Nevidí ji klient.') ?>
            </section>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div class="caps">Náhled v seznamu</div>
                <div class="row" style="flex-wrap:nowrap;gap:12px;margin-top:14px">
                    <?= get_avatar($previewName) ?>
                    <div style="min-width:0">
                        <div class="u-truncate" style="font-weight:var(--font-weight-semibold)<?= $values['name'] === '' ? ';color:var(--color-text-faint)' : '' ?>"><?= $this->e($previewName) ?></div>
                        <div class="text-caption u-truncate"><?= $this->e($previewContact) ?></div>
                    </div>
                </div>
                <div class="summary-list" style="padding:16px 0 0">
                    <div class="summary-list__row" style="padding:8px 0;border-bottom:0;font-size:var(--font-size-label)"><span class="summary-list__label">Telefon</span><span class="summary-list__value<?= $values['phone'] === '' ? ' text-faint' : '' ?>"><?= $this->e($values['phone'] !== '' ? $values['phone'] : '—') ?></span></div>
                    <div class="summary-list__row" style="padding:8px 0;border-bottom:0;font-size:var(--font-size-label)"><span class="summary-list__label">E-mail</span><span class="summary-list__value<?= $values['email'] === '' ? ' text-faint' : '' ?>"><?= $this->e($values['email'] !== '' ? $values['email'] : '—') ?></span></div>
                    <div class="summary-list__row" style="padding:8px 0;border-bottom:0;font-size:var(--font-size-label)"><span class="summary-list__label">Weby</span><span class="summary-list__value"><?= count($values['sites']) ?></span></div>
                </div>
            </div>

            <div class="card card--small-shadow" style="padding:6px 22px">
                <?php foreach ($tasks as $task): ?>
                    <div class="task-list__item<?= $task['done'] ? ' task-list__item--done' : '' ?>"><span class="task-list__mark"></span><?= $this->e($task['label']) ?></div>
                <?php endforeach; ?>
            </div>

            <div class="card card--note" style="font-size:var(--font-size-label);line-height:var(--line-height-relaxed);color:var(--color-text-secondary)">Klient je jen kontakt. Frekvence reportů, servis i prahy alertů se nastavují u konkrétního webu — jeden klient tak může mít weby s různým režimem péče.</div>
        </aside>
    </div>
</div>
</form>
