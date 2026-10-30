<?php
/**
 * Detail klienta — čtení (návrh `detail-klienta.html`).
 *
 * @var \App\Core\View\View $this
 * @var array               $client
 * @var array|null          $primary   hlavní kontaktní osoba (s `role`)
 * @var string              $primaryUrl stránka hlavní osoby ('' = klient ji nemá)
 * @var array<int, array{name: string, url: string}> $primaryOthers další klienti hlavní osoby
 * @var array<int, array>   $contacts  další kontakty s `name`, `url`, `role`, `othersLabel` („také u …")
 * @var array<int, string>  $pickOptions osoby z kontaktů, které u klienta ještě nejsou
 * @var string              $pickJson  údaje osob pro `contact-pick.js`
 * @var array<int, array<string, mixed>> $projects projekty klienta se `state` a `siteCountLabel`
 * @var array<int, array<string, mixed>> $sites  weby se `state`, `host`, `service` a `report`
 * @var string              $lastReport
 * @var string              $yearly   domény a hosting, které přefakturujeme, přepočtené na rok
 * @var array<int, array<string, mixed>> $recent poslední události u webů klienta
 * @var array<int, array>   $unassigned projekty bez klienta (k přiřazení)
 * @var array{contact: string, sites: string, since: string} $meta
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $client['name']]);
$contactForm = $this->form();

$field = function (string $label, string $value, bool $optional = false, string $span = ''): void {
    ?>
    <div class="form__field"<?= $span !== '' ? ' style="grid-column:span ' . $span . '"' : '' ?>>
        <span class="form__label form__label--caps"><?= $this->e($label) ?><?= $optional ? '<span class="form__label-optional"> · nepovinné</span>' : '' ?></span>
        <div class="form__value<?= $value === '' ? ' form__value--empty' : '' ?>"><?= $value !== '' ? $this->e($value) : '—' ?></div>
    </div>
    <?php
};
?>
<header class="page-header">
    <div class="page-header__breadcrumb"><a href="<?= get_url('klienti') ?>">Klienti</a><span>/</span><span><?= $this->e((string) $client['name']) ?></span></div>
    <div class="page-header__top">
        <div class="row" style="flex-wrap:nowrap;gap:16px;min-width:0">
            <?= get_avatar((string) $client['name']) ?>
            <div style="min-width:0">
                <h1 class="page-header__title"><?= $this->e((string) $client['name']) ?></h1>
                <div class="page-header__meta">
                    <?php if ($meta['contact'] !== ''): ?><span><?= $this->e($meta['contact']) ?></span><?php endif; ?>
                    <span><?= $this->e($meta['sites']) ?></span>
                    <?php if ($meta['since'] !== ''): ?><span><?= $this->e($meta['since']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="page-header__actions">
            <?php if ((string) ($primary['email'] ?? '') !== ''): ?>
                <a class="btn btn--secondary" href="mailto:<?= $this->e((string) $primary['email']) ?>"><?= get_btn_icon('mail') ?>Napsat e-mail</a>
            <?php endif; ?>
            <a class="btn btn--primary" href="<?= get_url('klienti/' . (int) $client['id'] . '/upravit') ?>">Upravit údaje</a>
        </div>
    </div>
</header>

<div class="app__content">
    <div class="split split--settings">
        <div class="stack">
            <section class="card card--padded">
                <div class="form">
                    <div class="row" style="justify-content:space-between;align-items:baseline">
                        <div class="card__title">Údaje klienta</div>
                        <div class="text-caption">Uloženo <?= $this->e(get_when((string) $client['updated_at'])) ?></div>
                    </div>
                    <div class="form__row">
                        <?php $field('Firma', (string) $client['name'], false, '2'); ?>
                        <?php $field('Jméno', (string) ($primary['first_name'] ?? '')); ?>
                        <?php $field('Příjmení', (string) ($primary['last_name'] ?? '')); ?>
                        <?php $field('Role', (string) ($primary['role'] ?? ''), true); ?>
                        <?php $field('Telefon', (string) ($primary['phone'] ?? '')); ?>
                        <?php $field('E-mail', (string) ($primary['email'] ?? '')); ?>
                        <?php $field('IČO', (string) $client['company_id']); ?>
                        <?php $field('DIČ', (string) $client['vat_id'], true); ?>
                        <?php $field('Adresa', (string) $client['address'], false, '2'); ?>
                        <?php $field('Fakturace', (string) $client['billing_note'], true, '2'); ?>
                    </div>
                    <?php if ($primaryUrl !== ''): ?>
                        <div class="contact-shared contact-shared--plain">
                            <?= get_icon('users', 'icon--sm icon--subtle') ?>
                            <span>
                                <?php if ($primaryOthers !== []): ?>
                                    Kontaktní osoba je i u:
                                    <?php foreach ($primaryOthers as $i => $other): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= $other['url'] ?>"><?= $this->e($other['name']) ?></a><?php endforeach; ?>
                                <?php else: ?>
                                    Kontaktní osoba je jen u tohoto klienta
                                <?php endif; ?>
                                · <a href="<?= $primaryUrl ?>">Upravit osobu</a>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="form__field">
                        <span class="form__label form__label--caps">Interní poznámka<span class="form__label-optional"> · nevidí ji klient</span></span>
                        <div class="text-secondary" style="padding:6px 0"><?= (string) $client['note'] !== '' ? get_rich_text((string) $client['note']) : '—' ?></div>
                    </div>
                </div>
            </section>

            <section class="card">
                    <?php render_card_head('Další kontakty', 'Komu ještě volat a psát') ?>
                    <?php if ($contacts !== []): ?>
                        <div class="table table--client-contacts">
                            <div class="table__head"><div>Osoba</div><div>Role</div><div>Telefon</div><div>E-mail</div><div class="table__cell--right">Akce</div></div>
                            <?php foreach ($contacts as $contact): ?>
                                <div class="table__row">
                                    <div class="table__cell" style="min-width:0">
                                        <div class="table__primary u-truncate"><?= $this->e($contact['name']) ?></div>
                                        <?php if ($contact['othersLabel'] !== ''): ?><div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($contact['othersLabel']) ?></div><?php endif; ?>
                                    </div>
                                    <div class="table__cell text-subtle u-truncate"><?= $this->e((string) $contact['role']) ?></div>
                                    <div class="table__cell table__cell--mono u-truncate"><?= $this->e((string) $contact['phone']) ?></div>
                                    <div class="table__cell text-secondary u-truncate" style="font-size:var(--font-size-label)"><?= $this->e((string) $contact['email']) ?></div>
                                    <div class="table__cell table__cell--right">
                                        <div class="row-actions">
                                            <a class="btn btn--ghost btn--icon" href="<?= $contact['url'] ?>" title="Upravit osobu" aria-label="Upravit <?= $this->e($contact['name']) ?>"><?= get_icon('edit', 'icon--sm') ?></a>
                                            <form class="row-actions__form" method="post" action="<?= get_url('klienti/' . (int) $client['id'] . '/kontakt/' . (int) $contact['id'] . '/smazat') ?>">
                                                <?php render_csrf($csrfToken) ?>
                                                <button type="submit" class="btn btn--ghost btn--icon" title="Odebrat od klienta (v kontaktech zůstane)" aria-label="Odebrat <?= $this->e($contact['name']) ?> od klienta"><?= get_icon('close', 'icon--sm') ?></button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?= get_url('klienti/' . (int) $client['id'] . '/kontakt') ?>" class="card__body form contact-add">
                        <?php render_csrf($csrfToken) ?>
                        <span class="caps">Přidat kontakt</span>
                        <?php if ($pickOptions !== []): ?>
                            <div class="form__row">
                                <?= $contactForm->select('contact_id', 'Osoba z kontaktů', $pickOptions, '', 'Nová osoba — vyplňte údaje níže', class: 'form__field--caps', span: 12,
                                    attributes: ['data-contact-pick' => 'link', 'data-contacts' => $pickJson, 'data-contact-client' => (string) (int) $client['id']]) ?>
                            </div>
                            <p class="contact-shared" data-contact-shared hidden></p>
                        <?php endif; ?>
                        <div class="form__row">
                            <?= $contactForm->text('first_name', 'Jméno', class: 'form__field--caps contact-person-field', attributes: ['placeholder' => 'Markéta']) ?>
                            <?= $contactForm->text('last_name', 'Příjmení', class: 'form__field--caps contact-person-field', attributes: ['placeholder' => 'Dobrá']) ?>
                            <?= $contactForm->text('role', 'Role', class: 'form__field--caps', attributes: ['placeholder' => 'účetní, správce IT…'], keyLabel: '· nepovinné') ?>
                            <?= $contactForm->text('phone', 'Telefon', type: 'tel', class: 'form__field--caps contact-person-field', attributes: ['placeholder' => '+420 …', 'class' => 'form__control--mono'], measure: 'none') ?>
                            <?= $contactForm->text('email', 'E-mail', type: 'email', class: 'form__field--caps contact-person-field', attributes: ['placeholder' => 'jmeno@firma.cz'], measure: 'none') ?>
                            <div class="form__field contact-add__submit">
                                <button type="submit" class="btn btn--secondary"><?= get_btn_icon('plus') ?>Přidat kontakt</button>
                            </div>
                        </div>
                    </form>
                </section>

            <section class="card card--scroll-x">
                <?php render_card_head('Projekty', 'Web, doména, hosting a e-maily pohromadě',
                    '<a class="btn btn--secondary btn--sm" href="' . get_url('projekty/pridat?klient=' . (int) $client['id']) . '">' . get_btn_icon('plus') . 'Nový projekt</a>') ?>
                <?php if ($projects === []): ?>
                    <?php render_empty('Zatím žádný projekt', 'Přidejte klientovi web (založí se mu projekt), nebo přiřaďte některý z projektů bez klienta.', 'folder') ?>
                <?php else: ?>
                    <div class="table table--client-projects">
                        <div class="table__head"><div>Projekt</div><div>Stav</div><div>Weby</div><div></div></div>
                        <?php foreach ($projects as $project): ?>
                            <a class="table__row table__row--link<?= $project['state']['level'] === 'problem' ? ' table__row--error' : '' ?>" href="<?= get_url('projekty/' . (int) $project['id']) ?>">
                                <div class="table__cell row" style="flex-wrap:nowrap;gap:11px"><?= get_icon('folder', 'icon--sm icon--subtle') ?><div class="table__primary u-truncate" style="font-size:var(--font-size-label)"><?= $this->e((string) $project['name']) ?></div></div>
                                <div class="table__cell"><?= get_status($project['state']['tone'], $project['state']['label']) ?></div>
                                <div class="table__cell text-secondary" style="font-size:var(--font-size-label)"><?= $this->e($project['siteCountLabel']) ?></div>
                                <div class="table__cell table__cell--right"><span style="color:var(--color-brand);font-weight:var(--font-weight-semibold);font-size:var(--font-size-label)">Otevřít</span></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($unassigned !== []): ?>
                    <form method="post" action="<?= get_url('klienti/' . (int) $client['id'] . '/projekty') ?>" class="card__footer" style="gap:10px;align-items:flex-start;flex-direction:column">
                        <?php render_csrf($csrfToken) ?>
                        <span class="caps">Přiřadit projekt bez klienta</span>
                        <div class="pick pick--grid">
                            <?php foreach ($unassigned as $free): ?>
                                <label class="pick__item"><input type="checkbox" name="projects[]" value="<?= (int) $free['id'] ?>" class="form__check-input"><?= get_icon('folder', 'icon--sm icon--subtle') ?><span style="flex:1;min-width:0"><span class="table__primary u-truncate" style="display:block;font-size:var(--font-size-label)"><?= $this->e((string) $free['name']) ?></span><span class="table__secondary u-truncate" style="display:block;margin-top:0"><?= $this->e(get_count((int) $free['site_count'], 'web', 'weby', 'webů')) ?></span></span></label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn btn--secondary btn--sm">Přiřadit vybrané</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card card--scroll-x">
                <?php render_card_head('Weby klienta', 'Reporty a servis se nastavují u konkrétního webu',
                    '<a class="btn btn--secondary btn--sm" href="' . get_url('weby/pridat?klient=' . (int) $client['id']) . '">' . get_btn_icon('plus') . 'Přidat web</a>') ?>
                <?php if ($sites === []): ?>
                    <?php render_empty('Zatím žádný web', 'Přidejte nový web, nebo klientovi přiřaďte projekt, který už weby má.', 'globe') ?>
                <?php else: ?>
                    <div class="table table--client-sites">
                        <div class="table__head"><div>Web</div><div>Stav</div><div>Servis</div><div>Report</div><div></div></div>
                        <?php foreach ($sites as $site): ?>
                            <a class="table__row table__row--link<?= $site['state']['level'] === 'problem' ? ' table__row--error' : '' ?>" href="<?= get_url('weby/' . (int) $site['id']) ?>">
                                <div class="table__cell row" style="flex-wrap:nowrap;gap:11px"><?= get_site_avatar((int) $site['id'], (string) $site['name'], (string) ($site['icon'] ?? ''), 'sm') ?><div style="min-width:0"><div class="table__primary u-truncate" style="font-size:var(--font-size-label)"><?= $this->e((string) $site['name']) ?></div><div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($site['host']) ?></div></div></div>
                                <div class="table__cell"><?= get_status($site['state']['tone'], $site['state']['label']) ?></div>
                                <div class="table__cell u-truncate<?= $site['service']['tone'] !== '' ? ' text-' . $site['service']['tone'] : '' ?>" style="font-size:var(--font-size-label)"><?= $this->e($site['service']['label']) ?></div>
                                <div class="table__cell u-truncate<?= $site['report']['tone'] !== '' ? ' text-' . $site['report']['tone'] : ' text-secondary' ?>" style="font-size:var(--font-size-label)"><?= $this->e($site['report']['label']) ?></div>
                                <div class="table__cell table__cell--right"><span style="color:var(--color-brand);font-weight:var(--font-weight-semibold);font-size:var(--font-size-label)">Otevřít</span></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow">
                <div class="summary-list">
                    <div class="summary-list__row"><span class="summary-list__label">Weby v péči</span><span class="summary-list__value"><?= count($sites) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Služby za rok</span><span class="summary-list__value"><?= $this->e($yearly) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Fakturace</span><span class="summary-list__value"><?= $this->e((string) $client['billing_note'] !== '' ? (string) $client['billing_note'] : '—') ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Spolupráce od</span><span class="summary-list__value"><?= $client['since'] !== null ? $this->e(date('n/Y', (int) strtotime((string) $client['since']))) : '—' ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Poslední report</span><span class="summary-list__value"><?= $this->e($lastReport) ?></span></div>
                </div>
            </div>

            <div class="card card--small-shadow">
                <div class="card__header"><span class="card__title" style="font-size:var(--font-size-body-large)">Poslední události</span></div>
                <?php if ($recent === []): ?>
                    <div class="text-caption" style="padding:14px 22px">Zatím nic — události u webů klienta se objeví tady.</div>
                <?php else: ?>
                    <div class="check-list">
                        <?php foreach ($recent as $event): ?>
                            <div class="check-list__item">
                                <?= get_dot((string) $event['tone']) ?>
                                <div>
                                    <div style="font-size:var(--font-size-label)"><?= $this->e((string) $event['message']) ?></div>
                                    <div class="check-list__text"><?= $this->e((string) $event['site_name']) ?> · <?= $this->e(get_when((string) $event['created_at'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card card--danger">
                <div style="font-weight:var(--font-weight-semibold);color:var(--color-status-error-text)">Odebrat klienta</div>
                <div class="text-caption" style="margin-top:4px">Projekty a weby zůstanou, jen bez přiřazeného klienta.</div>
                <form method="post" action="<?= get_url('klienti/' . (int) $client['id'] . '/archivovat') ?>" style="margin-top:12px">
                    <?php render_csrf($csrfToken) ?>
                    <button type="submit" class="btn btn--danger btn--sm"><?= get_btn_icon('trash') ?>Odebrat klienta</button>
                </form>
            </div>
        </aside>
    </div>
</div>
