<?php
/**
 * Detail projektu. Návrh obrazovku nemá — stavba jako detail klienta
 * (`detail-klienta.html`): obsah vlevo, souhrn a smazání v pravém panelu.
 *
 * Karty pod sebou: weby, domény a hosting (partial `service-row`),
 * přístupy a e-maily (jen počty, trezor má vlastní stránku), poznámka.
 *
 * @var \App\Core\View\View $this
 * @var array               $project  s `client_name`
 * @var array<int, array<string, mixed>> $sites weby se `state`, `host`, `service` a `report`
 * @var array<int, array<string, mixed>> $services domény a hosting z `ProjectServiceController::row()`
 * @var array<int, array{label: string, icon: string, count: int}> $vault počty v trezoru po druzích
 * @var array{level: string, tone: string, label: string} $state stav nejhoršího webu
 * @var array{sites: string, created: string} $meta
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $project['name']]);

$base = 'projekty/' . (int) $project['id'];
?>
<header class="page-header">
    <div class="page-header__breadcrumb"><a href="<?= get_url('projekty') ?>">Projekty</a><span>/</span><span><?= $this->e((string) $project['name']) ?></span></div>
    <div class="page-header__top">
        <div class="row" style="flex-wrap:nowrap;gap:16px;min-width:0">
            <?= $sites !== [] ? get_site_avatar((int) $sites[0]['id'], (string) $project['name'], (string) ($sites[0]['icon'] ?? '')) : get_avatar((string) $project['name']) ?>
            <div style="min-width:0">
                <h1 class="page-header__title"><?= $this->e((string) $project['name']) ?></h1>
                <div class="page-header__meta">
                    <?= get_status($state['tone'], $state['label']) ?>
                    <?php if ($project['client_id'] !== null): ?>
                        <a href="<?= get_url('klienti/' . (int) $project['client_id']) ?>"><?= $this->e((string) $project['client_name']) ?></a>
                    <?php else: ?>
                        <span class="text-faint">bez klienta</span>
                    <?php endif; ?>
                    <span><?= $this->e($meta['sites']) ?></span>
                </div>
            </div>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--primary" href="<?= get_url($base . '/upravit') ?>"><?= get_btn_icon('edit') ?>Upravit projekt</a>
        </div>
    </div>
</header>

<div class="app__content">
    <div class="split split--settings">
        <div class="stack">
            <section class="card card--scroll-x">
                <?php render_card_head('Weby', 'Monitoring, servis a reporty se nastavují u konkrétního webu',
                    '<a class="btn btn--secondary btn--sm" href="' . get_url('weby/pridat?projekt=' . (int) $project['id']) . '">' . get_btn_icon('plus') . 'Přidat web</a>') ?>
                <?php if ($sites === []): ?>
                    <?php render_empty('Zatím žádný web', 'Přidejte nový web, nebo sem přesuňte existující — v jeho Nastavení vyberte tenhle projekt.', 'globe') ?>
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

            <section class="card card--scroll-x">
                <?php render_card_head('Domény a hosting', 'Obnovy, ceny a co fakturujeme klientovi',
                    '<div class="row" style="gap:8px"><a class="btn btn--secondary btn--sm" href="' . get_url($base . '/sluzby/pridat/domena') . '">' . get_btn_icon('plus') . 'Doména</a>'
                    . '<a class="btn btn--secondary btn--sm" href="' . get_url($base . '/sluzby/pridat/hosting') . '">' . get_btn_icon('plus') . 'Hosting</a></div>') ?>
                <?php if ($services === []): ?>
                    <?php render_empty('Zatím žádná doména ani hosting', 'Přidejte, u koho je doména registrovaná a kde web běží — s datem obnovy a cenou pro klienta.', 'server') ?>
                <?php else: ?>
                    <div class="table table--services">
                        <div class="table__head"><div>Služba</div><div>Obnova</div><div>Cena</div><div>Fakturace</div><div class="table__cell--right">Akce</div></div>
                        <?php foreach ($services as $service): ?>
                            <?= $this->partial('partials/service-row', ['row' => $service, 'showProject' => false, 'back' => '', 'csrfToken' => $csrfToken]) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card">
                <?php render_card_head('Přístupy a e-maily', 'FTP, hosting, databáze, e-mailové schránky — hesla šifrovaně v trezoru',
                    '<a class="btn btn--secondary btn--sm" href="' . get_url($base . '/pristupy') . '">' . get_btn_icon('key') . 'Otevřít trezor</a>') ?>
                <?php if ($vault === []): ?>
                    <div class="text-caption" style="padding:14px 22px">Zatím nic uloženého.</div>
                <?php else: ?>
                    <div class="summary-list">
                        <?php foreach ($vault as $item): ?>
                            <div class="summary-list__row"><span class="summary-list__label row" style="gap:8px"><?= get_icon($item['icon'], 'icon--sm icon--subtle') ?><?= $this->e($item['label']) ?></span><span class="summary-list__value"><?= (int) $item['count'] ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ((string) ($project['note'] ?? '') !== ''): ?>
                <section class="card card--padded">
                    <div class="card__title">Poznámka</div>
                    <div class="text-secondary" style="line-height:var(--line-height-relaxed);margin-top:8px;white-space:pre-line"><?= $this->e((string) $project['note']) ?></div>
                </section>
            <?php endif; ?>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow">
                <div class="summary-list">
                    <div class="summary-list__row"><span class="summary-list__label">Klient</span><span class="summary-list__value"><?= $this->e((string) ($project['client_name'] ?? '—')) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Weby v monitoringu</span><span class="summary-list__value"><?= count($sites) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Založeno</span><span class="summary-list__value"><?= $this->e(get_czech_date((string) $project['created_at'])) ?></span></div>
                </div>
            </div>

            <div class="card card--danger">
                <div style="font-weight:var(--font-weight-semibold);color:var(--color-status-error-text)">Smazat projekt</div>
                <?php if ($sites === []): ?>
                    <div class="text-caption" style="margin-top:4px">Projekt nemá žádný web. Se smazáním zmizí i jeho domény, hosting a trezor přístupů.</div>
                    <a class="btn btn--danger btn--sm" href="#" data-confirm="project-delete" style="margin-top:12px"><?= get_btn_icon('trash') ?>Smazat projekt</a>
                <?php else: ?>
                    <div class="text-caption" style="margin-top:4px">Jde smazat, až nebude mít žádný web — přesuňte je do jiného projektu v Nastavení webu, nebo je odeberte z monitoringu.</div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<?= $this->partial('partials/service-delete-dialog', ['back' => '', 'csrfToken' => $csrfToken]) ?>

<?php if ($sites === []): ?>
    <dialog class="modal modal--danger" id="project-delete" aria-labelledby="project-delete-title">
        <form class="modal__body" method="post" action="<?= get_url($base . '/smazat') ?>" data-pending>
            <?php render_csrf($csrfToken) ?>
            <div>
                <div class="card__title modal__title" id="project-delete-title">Smazat projekt <?= $this->e((string) $project['name']) ?>?</div>
                <div class="card__note">Projekt zmizí z evidence i s doménami, hostingem a uloženými přístupy. Klient se nemaže.</div>
            </div>
            <div class="modal__actions">
                <button type="button" class="btn btn--ghost" data-dialog-close>Zrušit</button>
                <button type="submit" class="btn btn--danger" data-pending-label="Mažu…"><?= get_btn_icon('trash') ?>Smazat projekt</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
