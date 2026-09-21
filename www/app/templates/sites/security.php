<?php
/**
 * Detail webu — Zabezpečení (návrh `detail-webu-zabezpeceni*.html`).
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array<int, array<string, string>> $checks  id, label, icon, status, statusLabel, value, note
 * @var array{tone: string, label: string, value: string, note: string}|null $score
 * @var array<int, array<string, string>> $todo    chybějící a částečná opatření
 * @var string|null         $checkedAt
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Zabezpečení']);

$iconClass = ['ok' => 'icon--subtle', 'warning' => 'icon--warning', 'error' => 'icon--error', 'unknown' => 'icon--subtle'];
$statusTone = ['ok' => 'ok', 'warning' => 'warning', 'error' => 'error', 'unknown' => 'muted'];
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split">
        <section class="card">
            <?php render_card_head('Bezpečnostní kontrola', $checkedAt !== null ? 'Ověřeno ' . get_when($checkedAt) . ' · pět opatření' : 'Zatím neověřeno',
                '<form method="post" action="' . get_url('weby/' . (int) $site['id'] . '/zabezpeceni/overit') . '">' . get_csrf($csrfToken)
                . '<button type="submit" class="btn btn--secondary btn--sm">' . get_btn_icon('refresh') . 'Ověřit znovu</button></form>') ?>

            <?php if ($checks === []): ?>
                <?php render_empty('Zatím neověřeno', 'Kontrola proběhne s prvním „Zkontrolovat teď" — tři opatření zjišťuje plugin na webu, dvě ověřujeme zvenku.', 'shield') ?>
            <?php else: ?>
                <div class="table table--security">
                    <div class="table__head"><div>Opatření</div><div>Zjištěný stav</div><div>Vyhodnocení</div></div>
                    <?php foreach ($checks as $check): ?>
                        <div class="table__row">
                            <div class="table__cell row" style="flex-wrap:nowrap;align-items:flex-start;gap:11px">
                                <?= get_icon($check['icon'], $iconClass[$check['status']]) ?>
                                <div>
                                    <div class="table__primary"><?= $this->e($check['label']) ?></div>
                                    <div class="table__secondary"><?= $this->e($check['note']) ?></div>
                                </div>
                            </div>
                            <div class="table__cell table__cell--mono u-break-anywhere<?= $check['status'] === 'error' ? ' text-error' : '' ?>"><?= $this->e($check['value']) ?></div>
                            <div class="table__cell"><?= get_status($statusTone[$check['status']], $check['statusLabel']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="split__aside">
            <?php if ($score !== null): ?>
                <div class="score-card score-card--<?= $score['tone'] ?>">
                    <div class="score-card__label"><span class="score-card__dot"></span><?= $this->e($score['label']) ?></div>
                    <div class="score-card__value"><?= $this->e($score['value']) ?></div>
                    <div class="score-card__note"><?= $this->e($score['note']) ?></div>
                </div>

                <div class="card card--small-shadow">
                    <div class="card__header"><span class="caps">Co dořešit</span></div>
                    <?php if ($todo === []): ?>
                        <?php render_empty('Nic k dořešení', 'Všech pět opatření je nasazeno.', 'check') ?>
                    <?php else: ?>
                        <div class="check-list">
                            <?php foreach ($todo as $item): ?>
                                <div class="check-list__item check-list__item--<?= $item['status'] === 'error' ? 'error' : 'warning' ?>">
                                    <span class="check-list__dot"></span>
                                    <div>
                                        <div class="check-list__title"><?= $this->e($item['label']) ?></div>
                                        <div class="check-list__text"><?= $this->e($item['note']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card__footer"><a class="btn btn--secondary btn--sm btn--block" href="<?= get_url('weby/' . (int) $site['id'] . '/servis/zapsat?predvyplnit=zabezpeceni') ?>"><?= get_btn_icon('plus') ?>Zapsat do servisu</a></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card card--note">Bezpečnostní kontrola běží při každém servisu. Klient v reportu vidí jen souhrn, ne konkrétní adresy.</div>
        </aside>
    </div>
</div>
