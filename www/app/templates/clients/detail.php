<?php
/**
 * Detail klienta — čtení (návrh `detail-klienta.html`).
 *
 * @var \App\Core\View\View $this
 * @var array               $client
 * @var array|null          $primary   hlavní kontakt
 * @var array<int, array>   $contacts  další kontakty
 * @var array<int, array<string, mixed>> $sites  weby se `state`, `host`, `service` a `report`
 * @var string              $lastReport
 * @var array<int, array<string, mixed>> $recent poslední události u webů klienta
 * @var array<int, array>   $unassigned weby bez klienta (k přiřazení)
 * @var array{contact: string, sites: string, since: string} $meta
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $client['name']]);

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
            <?php if ((string) $client['email'] !== ''): ?>
                <a class="btn btn--secondary" href="mailto:<?= $this->e((string) $client['email']) ?>"><?= get_btn_icon('mail') ?>Napsat e-mail</a>
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
                    <div class="form__field">
                        <span class="form__label form__label--caps">Interní poznámka<span class="form__label-optional"> · nevidí ji klient</span></span>
                        <div class="text-secondary" style="line-height:var(--line-height-relaxed);padding:6px 0;white-space:pre-line"><?= (string) $client['note'] !== '' ? $this->e((string) $client['note']) : '—' ?></div>
                    </div>
                </div>
            </section>

            <section class="card">
                    <?php render_card_head('Další kontakty', 'Komu ještě volat a psát') ?>
                    <?php if ($contacts !== []): ?>
                        <div class="table" style="--table-columns:minmax(0,1.4fr) minmax(0,1fr) 150px minmax(0,1.2fr) 40px">
                            <?php foreach ($contacts as $contact): ?>
                                <div class="table__row">
                                    <div class="table__cell table__primary u-truncate"><?= $this->e(trim($contact['first_name'] . ' ' . $contact['last_name'])) ?></div>
                                    <div class="table__cell text-subtle u-truncate"><?= $this->e((string) $contact['role']) ?></div>
                                    <div class="table__cell table__cell--mono u-truncate"><?= $this->e((string) $contact['phone']) ?></div>
                                    <div class="table__cell text-secondary u-truncate" style="font-size:var(--font-size-label)"><?= $this->e((string) $contact['email']) ?></div>
                                    <div class="table__cell table__cell--right">
                                        <form method="post" action="<?= get_url('klienti/' . (int) $client['id'] . '/kontakt/' . (int) $contact['id'] . '/smazat') ?>">
                                            <?php render_csrf($csrfToken) ?>
                                            <button type="submit" class="btn--menu" title="Odebrat kontakt"><?= get_icon('trash', 'icon--sm icon--subtle') ?></button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?= get_url('klienti/' . (int) $client['id'] . '/kontakt') ?>" class="card__footer" style="gap:8px">
                        <?php render_csrf($csrfToken) ?>
                        <input class="form__control form__control--inline" name="first_name" placeholder="Jméno" aria-label="Jméno" style="width:130px">
                        <input class="form__control form__control--inline" name="last_name" placeholder="Příjmení" aria-label="Příjmení" style="width:140px">
                        <input class="form__control form__control--inline" name="role" placeholder="role" aria-label="Role" style="width:120px">
                        <input class="form__control form__control--inline form__control--mono" name="phone" placeholder="+420 …" aria-label="Telefon" style="width:140px">
                        <input class="form__control form__control--inline" name="email" type="email" placeholder="jmeno@firma.cz" aria-label="E-mail" style="width:200px">
                        <button type="submit" class="btn btn--secondary btn--sm"><?= get_btn_icon('plus') ?>Přidat kontakt</button>
                    </form>
                </section>

            <section class="card card--scroll-x">
                <?php render_card_head('Weby klienta', 'Reporty a servis se nastavují u konkrétního webu',
                    '<a class="btn btn--secondary btn--sm" href="' . get_url('weby/pridat?klient=' . (int) $client['id']) . '">' . get_btn_icon('plus') . 'Přidat web</a>') ?>
                <?php if ($sites === []): ?>
                    <?php render_empty('Zatím žádný web', 'Přidejte nový web, nebo klientovi přiřaďte některý z webů bez klienta.', 'globe') ?>
                <?php else: ?>
                    <div class="table table--client-sites">
                        <div class="table__head"><div>Web</div><div>Stav</div><div>Servis</div><div>Report</div><div></div></div>
                        <?php foreach ($sites as $site): ?>
                            <a class="table__row table__row--link<?= $site['state']['level'] === 'problem' ? ' table__row--error' : '' ?>" href="<?= get_url('weby/' . (int) $site['id']) ?>">
                                <div class="table__cell row" style="flex-wrap:nowrap;gap:11px"><?= get_avatar((string) $site['name'], '', 'sm') ?><div style="min-width:0"><div class="table__primary u-truncate" style="font-size:var(--font-size-label)"><?= $this->e((string) $site['name']) ?></div><div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($site['host']) ?></div></div></div>
                                <div class="table__cell"><?= get_status($site['state']['tone'], $site['state']['label']) ?></div>
                                <div class="table__cell u-truncate<?= $site['service']['tone'] !== '' ? ' text-' . $site['service']['tone'] : '' ?>" style="font-size:var(--font-size-label)"><?= $this->e($site['service']['label']) ?></div>
                                <div class="table__cell u-truncate<?= $site['report']['tone'] !== '' ? ' text-' . $site['report']['tone'] : ' text-secondary' ?>" style="font-size:var(--font-size-label)"><?= $this->e($site['report']['label']) ?></div>
                                <div class="table__cell table__cell--right"><span style="color:var(--color-brand);font-weight:var(--font-weight-semibold);font-size:var(--font-size-label)">Otevřít</span></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($unassigned !== []): ?>
                    <form method="post" action="<?= get_url('klienti/' . (int) $client['id'] . '/weby') ?>" class="card__footer" style="gap:10px;align-items:flex-start;flex-direction:column">
                        <?php render_csrf($csrfToken) ?>
                        <span class="caps">Přiřadit web bez klienta</span>
                        <div class="pick">
                            <?php foreach ($unassigned as $free): ?>
                                <label class="pick__item"><input type="checkbox" name="sites[]" value="<?= (int) $free['id'] ?>" class="form__check-input"><?= get_avatar((string) $free['name'], '', 'sm') ?><span style="flex:1;min-width:0"><span class="table__primary u-truncate" style="display:block;font-size:var(--font-size-label)"><?= $this->e((string) $free['name']) ?></span><span class="table__secondary u-truncate" style="display:block;margin-top:0"><?= $this->e(\App\Core\Sites\SiteRepository::host((string) $free['url'])) ?></span></span></label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn btn--secondary btn--sm">Přiřadit vybrané</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow">
                <div class="summary-list">
                    <div class="summary-list__row"><span class="summary-list__label">Weby v péči</span><span class="summary-list__value"><?= count($sites) ?></span></div>
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
                <div class="text-caption" style="margin-top:4px">Weby zůstanou v monitoringu bez přiřazeného klienta.</div>
                <form method="post" action="<?= get_url('klienti/' . (int) $client['id'] . '/archivovat') ?>" style="margin-top:12px">
                    <?php render_csrf($csrfToken) ?>
                    <button type="submit" class="btn btn--danger btn--sm"><?= get_btn_icon('trash') ?>Odebrat klienta</button>
                </form>
            </div>
        </aside>
    </div>
</div>
