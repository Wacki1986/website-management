<?php
/**
 * Obnovy a fakturace — všechny domény a hostingy projektů podle data
 * obnovy. Filtr „K vyfakturování" seskupí řádky po klientech se součtem
 * (podklad pro fakturu). Návrh obrazovku nemá; stavba jako seznam webů.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<int, array<string, mixed>> $rows   řádky z `ProjectServiceController::row()`
 * @var array<string, array{rows: array, total: string}> $groups klient => řádky a součet (jen filtr fakturace)
 * @var string              $filter  '' | brzy | fakturace
 * @var array<string, int>  $counts  filtr => počet
 * @var int                 $days    pravidlo „Obnova domén a hostingu" (dní předem)
 * @var string              $tabsHtml
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$filterUrl = static fn (string $key): string => get_url('projekty/obnovy') . ($key !== '' ? '?filtr=' . $key : '');
$head = '<div class="table__head"><div>Služba</div><div>Projekt</div><div>Obnova</div><div>Cena</div><div>Fakturace</div><div class="table__cell--right">Akce</div></div>';
?>
<?php render_page_head('Projekty', $this->e(get_count($counts[''], 'služba', 'služby', 'služeb') . ' · upozornění ' . $days . ' dní předem'),
    '<a class="btn btn--primary" href="' . get_url('projekty/pridat') . '">' . get_btn_icon('plus') . 'Nový projekt</a>', $tabsHtml) ?>

<div class="app__content">
    <section class="card card--scroll-x">
        <div class="card__header card__header--filters">
            <div class="pill-group">
                <a class="pill<?= $filter === '' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('') ?>">Všechny <span class="pill__note"><?= $counts[''] ?></span></a>
                <a class="pill<?= $filter === 'brzy' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('brzy') ?>">Obnova do <?= $days ?> dní <span class="pill__note"><?= $counts['brzy'] ?></span></a>
                <a class="pill<?= $filter === 'fakturace' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('fakturace') ?>">K vyfakturování <span class="pill__note"><?= $counts['fakturace'] ?></span></a>
            </div>
        </div>

        <div class="table table--renewals">
            <?php if ($rows === []): ?>
                <?php render_empty($counts[''] === 0 ? 'Zatím žádná doména ani hosting' : 'Nic neodpovídá filtru',
                    $counts[''] === 0 ? 'Domény a hosting se přidávají v detailu projektu.' : ($filter === 'fakturace' ? 'Všechno, co se blíží, je vyfakturované.' : 'V nejbližších dnech se nic neobnovuje.'),
                    'clock') ?>
            <?php elseif ($groups !== []): ?>
                <?= $head ?>
                <?php foreach ($groups as $client => $group): ?>
                    <div class="table__group"><span style="font-weight:var(--font-weight-semibold)"><?= $this->e($client) ?></span><span class="text-caption">celkem <?= $this->e($group['total']) ?></span></div>
                    <?php foreach ($group['rows'] as $row): ?>
                        <?= $this->partial('partials/service-row', ['row' => $row, 'showProject' => true, 'back' => 'obnovy', 'csrfToken' => $csrfToken]) ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?= $head ?>
                <?php foreach ($rows as $row): ?>
                    <?= $this->partial('partials/service-row', ['row' => $row, 'showProject' => true, 'back' => 'obnovy', 'csrfToken' => $csrfToken]) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card__footer card__footer--muted">
            <span>„Vyfakturováno" zapíše fakturu za příští období, „Obnoveno" posune datum obnovy o periodu. Expiraci domén hlídá monitor v registru.</span>
        </div>
    </section>
</div>

<?= $this->partial('partials/service-delete-dialog', ['back' => 'obnovy', 'csrfToken' => $csrfToken]) ?>
