<?php
/**
 * Seznam klientů (návrh `klienti.html`).
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<int, array<string, mixed>> $clients  řádky s `contactName`, `siteCountLabel`, `city`
 * @var string              $q
 * @var string              $filter   '' | multi | person
 * @var array{all: int, multi: int, person: int} $counts
 * @var string              $meta
 */
$this->extend('layout/shell', ['title' => $title]);

$filterUrl = static fn (string $key): string => get_url('klienti') . '?' . http_build_query(array_filter(['q' => $q, 'filtr' => $key]));
?>
<?php render_page_head('Klienti', $this->e($meta),
    '<a class="btn btn--primary" href="' . get_url('klienti/pridat') . '">' . get_btn_icon('plus') . 'Přidat klienta</a>') ?>

<div class="app__content">
    <section class="card card--scroll-x">
        <form class="card__header card__header--filters" method="get" action="<?= get_url('klienti') ?>">
            <label class="search" style="max-width:340px"><?= get_icon('search', 'icon--sm') ?><input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Hledat jméno, firmu nebo e-mail…" class="search__input" aria-label="Hledat"></label>
            <?php if ($filter !== ''): ?><input type="hidden" name="filtr" value="<?= $this->e($filter) ?>"><?php endif; ?>
            <div class="pill-group">
                <a class="pill<?= $filter === '' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('') ?>">Všichni <span class="pill__note"><?= $counts['all'] ?></span></a>
                <a class="pill<?= $filter === 'multi' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('multi') ?>">Více webů <span class="pill__note"><?= $counts['multi'] ?></span></a>
                <a class="pill<?= $filter === 'person' ? ' pill--brand' : '' ?>" href="<?= $filterUrl('person') ?>">Bez DIČ (OSVČ) <span class="pill__note"><?= $counts['person'] ?></span></a>
            </div>
            <noscript><button type="submit" class="btn btn--secondary btn--sm">Hledat</button></noscript>
            <div class="text-subtle u-ml-auto" style="font-size:var(--font-size-label)">Řadit: <b>Firma</b></div>
        </form>

        <div class="table table--clients">
            <div class="table__head"><div>Firma</div><div>Kontaktní osoba</div><div>Telefon</div><div>E-mail</div><div class="table__cell table__cell--right">Weby</div><div></div></div>

            <?php if ($clients === []): ?>
                <?php render_empty($counts['all'] === 0 ? 'Zatím žádný klient' : 'Žádný klient neodpovídá filtru',
                    $counts['all'] === 0 ? 'Klient je jen kontakt — reporty a servis se nastavují u webů.' : 'Zkuste jiné hledání.',
                    'users', $counts['all'] === 0 ? '<a class="btn btn--primary btn--sm" href="' . get_url('klienti/pridat') . '">' . get_btn_icon('plus') . 'Přidat klienta</a>' : '') ?>
            <?php else: ?>
                <?php foreach ($clients as $client): ?>
                    <a class="table__row table__row--link" href="<?= get_url('klienti/' . (int) $client['id']) ?>">
                        <div class="table__cell row" style="flex-wrap:nowrap;gap:12px">
                            <?= get_avatar((string) $client['name'], '', 'sm') ?>
                            <div style="min-width:0">
                                <div class="table__primary u-truncate"><?= $this->e((string) $client['name']) ?></div>
                                <div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($client['city'] !== '' ? $client['city'] : ((int) $client['site_count'] > 1 ? $client['siteCountLabel'] . ' v péči' : '')) ?></div>
                            </div>
                        </div>
                        <div class="table__cell">
                            <div class="u-truncate text-secondary"><?= $this->e($client['contactName'] !== '' ? $client['contactName'] : '—') ?></div>
                            <?php if ((string) ($client['contact_role'] ?? '') !== ''): ?><div class="table__secondary u-truncate"><?= $this->e((string) $client['contact_role']) ?></div><?php endif; ?>
                        </div>
                        <div class="table__cell table__cell--mono u-truncate"><?= $this->e((string) $client['phone'] !== '' ? (string) $client['phone'] : '—') ?></div>
                        <div class="table__cell u-truncate text-secondary" style="font-size:var(--font-size-label)"><?= $this->e((string) $client['email'] !== '' ? (string) $client['email'] : '—') ?></div>
                        <div class="table__cell table__cell--right text-secondary" style="font-size:var(--font-size-label)"><?= $this->e($client['siteCountLabel']) ?></div>
                        <div class="table__cell table__cell--right"><?= get_icon('chevron-right', 'icon--sm icon--subtle') ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card__footer card__footer--muted"><span>Zobrazeno <?= count($clients) ?> z <?= $counts['all'] ?> <?= get_plural($counts['all'], 'klienta', 'klientů', 'klientů') ?></span><a class="u-ml-auto" href="<?= get_url('weby') ?>">Přejít na weby</a></div>
    </section>
</div>
