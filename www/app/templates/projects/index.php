<?php
/**
 * Seznam projektů. Návrh obrazovku nemá — stejná stavba jako Klienti
 * (`klienti.html`): hledání, pilulky filtrů, řádek = odkaz na detail.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<int, array<string, mixed>> $projects řádky se `state`, `hosts`, `firstSite` (pro ikonu) a `renewal` (nejbližší obnova, null = žádná)
 * @var string              $q
 * @var string              $filter   '' | bez-klienta | bez-webu
 * @var array{all: int, bez-klienta: int, bez-webu: int} $counts
 * @var string              $meta
 * @var string              $tabsHtml  záložky Projekty / Obnovy a fakturace
 */
$this->extend('layout/shell', ['title' => $title]);

$filterUrl = static fn (string $key): string => get_url('projekty') . '?' . http_build_query(array_filter(['q' => $q, 'filtr' => $key]));
?>
<?php render_page_head('Projekty', $this->e($meta),
    '<a class="btn btn--primary" href="' . get_url('projekty/pridat') . '">' . get_btn_icon('plus') . 'Nový projekt</a>', $tabsHtml) ?>

<div class="app__content">
    <section class="card card--scroll-x">
        <form class="card__header card__header--filters" method="get" action="<?= get_url('projekty') ?>">
            <label class="search" style="max-width:340px"><?= get_icon('search', 'icon--sm') ?><input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Hledat projekt, klienta nebo doménu…" class="search__input" aria-label="Hledat"></label>
            <?php if ($filter !== ''): ?><input type="hidden" name="filtr" value="<?= $this->e($filter) ?>"><?php endif; ?>
            <div class="pill-group">
                <a class="pill<?= $filter === '' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('') ?>">Všechny <span class="pill__note"><?= $counts['all'] ?></span></a>
                <a class="pill<?= $filter === 'bez-klienta' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('bez-klienta') ?>">Bez klienta <span class="pill__note"><?= $counts['bez-klienta'] ?></span></a>
                <a class="pill<?= $filter === 'bez-webu' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('bez-webu') ?>">Bez webu <span class="pill__note"><?= $counts['bez-webu'] ?></span></a>
            </div>
            <noscript><button type="submit" class="btn btn--secondary btn--sm">Hledat</button></noscript>
        </form>

        <div class="table table--projects">
            <div class="table__head"><div>Projekt</div><div>Klient</div><div class="table__cell table__cell--right">Weby</div><div>Stav</div><div>Nejbližší obnova</div><div></div></div>

            <?php if ($projects === []): ?>
                <?php render_empty($counts['all'] === 0 ? 'Zatím žádný projekt' : 'Žádný projekt neodpovídá filtru',
                    $counts['all'] === 0 ? 'Projekt drží pohromadě web, doménu, hosting a e-maily jednoho zákazníka.' : 'Zkuste jiné hledání.',
                    'folder', $counts['all'] === 0 ? '<a class="btn btn--primary btn--sm" href="' . get_url('projekty/pridat') . '">' . get_btn_icon('plus') . 'Nový projekt</a>' : '') ?>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                    <a class="table__row table__row--link<?= $project['state']['level'] === 'problem' ? ' table__row--error' : '' ?>" href="<?= get_url('projekty/' . (int) $project['id']) ?>">
                        <div class="table__cell row" style="flex-wrap:nowrap;gap:12px">
                            <?= $project['firstSite'] !== null
                                ? get_site_avatar((int) $project['firstSite']['id'], (string) $project['name'], (string) ($project['firstSite']['icon'] ?? ''), 'sm')
                                : get_avatar((string) $project['name'], '', 'sm') ?>
                            <div style="min-width:0">
                                <div class="table__primary u-truncate"><?= $this->e((string) $project['name']) ?></div>
                                <div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($project['hosts']) ?></div>
                            </div>
                        </div>
                        <div class="table__cell u-truncate text-secondary"><?= $this->e((string) ($project['client_name'] ?? '—')) ?></div>
                        <div class="table__cell table__cell--right text-secondary" style="font-size:var(--font-size-label)"><?= (int) $project['site_count'] ?></div>
                        <div class="table__cell"><?= get_status($project['state']['tone'], $project['state']['label']) ?></div>
                        <div class="table__cell"><?= $project['renewal'] !== null ? get_status($project['renewal']['tone'], $project['renewal']['label']) : '<span class="text-faint">—</span>' ?></div>
                        <div class="table__cell table__cell--right"><?= get_icon('chevron-right', 'icon--sm icon--subtle') ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card__footer card__footer--muted"><span>Zobrazeno <?= count($projects) ?> z <?= $counts['all'] ?> <?= get_plural($counts['all'], 'projektu', 'projektů', 'projektů') ?></span><a class="u-ml-auto" href="<?= get_url('klienti') ?>">Přejít na klienty</a></div>
    </section>
</div>
