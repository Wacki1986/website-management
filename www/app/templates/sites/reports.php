<?php
/**
 * Detail webu — Reporty (návrh `detail-webu-reporty.html`): historie
 * odeslaných reportů. Nastavení plánu a adresátů je v záložce Nastavení.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array<int, array<string, mixed>> $rows
 * @var string              $settingsNote
 * @var bool                $hasRecipients
 * @var string              $reportsUrl
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Reporty']);

$base = 'weby/' . (int) $site['id'];
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <section class="card">
        <?php render_card_head('Odeslané reporty', $settingsNote,
            '<div class="row" style="gap:8px"><a class="btn btn--secondary btn--sm" href="' . get_url($base . '/nastaveni') . '#reporty">Nastavit</a>'
            . '<form method="post" action="' . get_url($base . '/reporty/odeslat') . '">' . get_csrf($csrfToken) . '<button class="btn btn--primary btn--sm" type="submit">' . get_btn_icon('mail') . 'Odeslat report teď</button></form></div>') ?>

        <?php if (!$hasRecipients): ?>
            <?php render_notice($this, 'warning', 'Web nemá adresáta reportu', 'Přidejte e-mail klienta v Nastavení webu → Klientské reporty, jinak report nemá kam odejít.', [], get_url($base . '/nastaveni'), 'Otevřít nastavení') ?>
        <?php endif; ?>

        <?php if ($rows === []): ?>
            <?php render_empty('Zatím žádný report', 'První report vznikne podle plánu, nebo ho pošlete hned tlačítkem „Odeslat report teď".', 'report') ?>
        <?php else: ?>
            <div class="table table--reports">
                <div class="table__head"><div>Datum</div><div>Období</div><div>Stav</div><div>Otevření</div><div class="table__cell table__cell--right">Akce</div></div>
                <?php foreach ($rows as $row): ?>
                    <div class="table__row<?= $row['isProblem'] ? ' table__row--error' : '' ?>">
                        <div class="table__cell table__cell--mono"><?= $this->e($row['date']) ?></div>
                        <div class="table__cell">
                            <div class="table__primary"><?= $this->e($row['period']) ?></div>
                            <div class="table__secondary u-truncate"><?= $this->e($row['summaryLine'] !== '' ? $row['summaryLine'] : ($row['status'] === 'pending_approval' ? 'čeká na kontrolu a odeslání' : 'koncept')) ?></div>
                        </div>
                        <div class="table__cell"><?= get_status($row['tone'], $row['label']) ?></div>
                        <div class="table__cell text-subtle" style="font-size:var(--font-size-label)"><?= $this->e($row['openedLabel']) ?></div>
                        <div class="table__cell table__cell--right"><?= $this->partial('partials/report-actions', ['row' => $row]) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="card card--note">Kopie každého odeslaného reportu zůstává uložená — ikona oka ukáže přesně to, co klient dostal. Celou frontu napříč weby najdete v <a href="<?= $reportsUrl ?>">Reportech</a>.</div>
</div>

<?= $this->partial('partials/report-delete-dialog', ['back' => 'weby/' . (int) $site['id'] . '/reporty', 'csrfToken' => $csrfToken]) ?>
