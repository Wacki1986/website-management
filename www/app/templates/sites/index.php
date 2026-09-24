<?php
/**
 * Seznam webů (návrh `weby.html`, `weby-seskupeno.html`, `weby-prazdno.html`).
 *
 * Filtry jsou obyčejné odkazy s parametry — fungují bez JavaScriptu.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<int, array<string, mixed>> $rows     řádky se `state`, `host`, `updates`, `versions` (WP, PHP, Databáze: value, tone, title)…
 * @var array<string, array<int, array<string, mixed>>> $groups   při seskupení: klient => řádky
 * @var bool                $grouped
 * @var string              $q
 * @var string              $level          '' | problem | attention | ok
 * @var int|null            $clientId
 * @var array<int, string>  $clients        id => název
 * @var array{all: int, problem: int, attention: int, ok: int} $counts
 * @var string              $meta
 * @var int                 $shown
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

// Adresa filtru: zachová hledání a klienta, mění jen stav.
$filterUrl = static fn (array $override): string => get_url('weby') . '?' . http_build_query(array_filter(array_merge([
    'q' => $q,
    'klient' => $clientId,
    'seskupit' => $grouped ? 1 : null,
    'stav' => $level,
], $override), static fn ($v): bool => $v !== null && $v !== '' && $v !== 0));

$segments = [
    ['key' => '', 'label' => 'Vše', 'count' => $counts['all'], 'dot' => ''],
    ['key' => 'problem', 'label' => 'Problém', 'count' => $counts['problem'], 'dot' => 'error'],
    ['key' => 'attention', 'label' => 'Pozornost', 'count' => $counts['attention'], 'dot' => 'warning'],
    ['key' => 'ok', 'label' => 'V pořádku', 'count' => $counts['ok'], 'dot' => 'ok'],
];

$renderRow = function (array $site) use ($csrfToken): void {
    $detail = get_url('weby/' . (int) $site['id']);
    $level = $site['state']['level'];
    ?>
    <a class="table__row table__row--link<?= $level === 'problem' ? ' table__row--error' : '' ?>" href="<?= $detail ?>">
        <div class="table__cell row" style="flex-wrap:nowrap;gap:13px">
            <?= get_site_avatar((int) $site['id'], (string) $site['name'], (string) ($site['icon'] ?? '')) ?>
            <div style="min-width:0">
                <div class="table__primary u-truncate"><?= $this->e((string) $site['name']) ?></div>
                <div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($site['host']) ?></div>
            </div>
        </div>
        <div class="table__cell u-truncate text-secondary"><?= $this->e((string) ($site['client_name'] ?? '—')) ?></div>
        <div class="table__cell"><?= get_status($site['state']['tone'], $site['state']['label']) ?></div>
        <div class="table__cell table__cell--mono table__cell--right<?= $site['uptimeTone'] === 'faint' ? ' text-faint' : ($site['uptimeTone'] !== '' ? ' text-' . $site['uptimeTone'] : '') ?>"><?= $this->e($site['uptime']) ?></div>
        <?php foreach ($site['versions'] as $version): ?>
            <div class="table__cell table__cell--mono u-truncate<?= $version['value'] === '' ? ' text-faint' : ($version['tone'] !== '' ? ' text-' . $version['tone'] : '') ?>"<?= $version['title'] !== '' ? ' title="' . $this->e($version['title']) . '"' : '' ?>><?= $version['value'] !== '' ? $this->e($version['value']) : '—' ?></div>
        <?php endforeach; ?>
        <div class="table__cell table__cell--right"><?= $site['updates'] > 0 ? get_badge((string) $site['updates'], $site['updates'] >= 5 ? 'warning' : '') : '<span class="text-faint">–</span>' ?></div>
        <div class="table__cell<?= $site['service']['tone'] !== '' ? ' text-' . $site['service']['tone'] : '' ?>" style="font-size:var(--font-size-label)"><?= $this->e($site['service']['label']) ?></div>
        <div class="table__cell<?= $site['report']['tone'] !== '' ? ' text-' . $site['report']['tone'] : ' text-secondary' ?>" style="font-size:var(--font-size-label)"><?= $this->e($site['report']['label']) ?></div>
        <div class="table__cell table__cell--right"><?= get_icon('chevron-right', 'icon--sm icon--subtle') ?></div>
    </a>
    <?php
};
?>
<?php render_page_head('Weby', $this->e($meta),
    '<a class="btn btn--primary" href="' . get_url('weby/pridat') . '">' . get_btn_icon('plus') . 'Přidat web</a>') ?>

<div class="app__content">
    <section class="card card--scroll-x">
        <form class="card__header card__header--filters" method="get" action="<?= get_url('weby') ?>">
            <label class="search" style="max-width:320px">
                <?= get_icon('search', 'icon--sm') ?>
                <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Hledat web nebo klienta…" class="search__input" aria-label="Hledat">
            </label>
            <?php if ($clientId !== null): ?><input type="hidden" name="klient" value="<?= (int) $clientId ?>"><?php endif; ?>
            <?php if ($grouped): ?><input type="hidden" name="seskupit" value="1"><?php endif; ?>
            <?php if ($level !== ''): ?><input type="hidden" name="stav" value="<?= $this->e($level) ?>"><?php endif; ?>

            <div class="segmented">
                <?php foreach ($segments as $segment): ?>
                    <a class="segmented__item<?= $level === $segment['key'] ? ' segmented__item--active' : '' ?>" href="<?= $filterUrl(['stav' => $segment['key']]) ?>">
                        <?php if ($segment['dot'] !== ''): ?><span class="segmented__dot" style="background:var(--color-status-<?= $segment['dot'] ?>)"></span><?php endif; ?>
                        <?= $this->e($segment['label']) ?><span class="segmented__count"><?= $segment['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="row u-ml-auto">
                <select name="klient" class="form__control form__control--inline" style="padding:6px 30px 6px 10px;font-size:var(--font-size-label)" aria-label="Klient" onchange="this.form.submit()">
                    <option value="">Klient · všichni</option>
                    <?php foreach ($clients as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= $clientId === (int) $id ? ' selected' : '' ?>><?= $this->e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="btn btn--secondary btn--sm<?= $grouped ? ' btn--active' : '' ?>" href="<?= $filterUrl(['seskupit' => $grouped ? null : 1]) ?>"><?= get_btn_icon('users') ?><?= $grouped ? 'Zrušit seskupení' : 'Seskupit podle klienta' ?></a>
                <noscript><button type="submit" class="btn btn--secondary btn--sm">Filtrovat</button></noscript>
            </div>
        </form>

        <div class="table table--sites">
            <div class="table__head"><div>Web</div><div>Klient</div><div>Stav</div><div class="table__cell table__cell--right">Uptime</div><div>WP</div><div>PHP</div><div>Databáze</div><div class="table__cell table__cell--right">Aktualizace</div><div>Servis</div><div>Report</div><div></div></div>

            <?php if ($rows === []): ?>
                <?php if ($counts['all'] === 0 && $q === '' && $clientId === null): ?>
                    <?php render_empty('Zatím žádný web v monitoringu', 'Přidejte první web — dostane API klíč pro plugin MEDIAGRAFIK Monitor a hned se začne hlídat.', 'globe',
                        '<a class="btn btn--primary btn--sm" href="' . get_url('weby/pridat') . '">' . get_btn_icon('plus') . 'Přidat web</a>') ?>
                <?php else: ?>
                    <?php render_empty('Žádný web neodpovídá filtru', 'Zkuste zrušit filtr stavu nebo hledat podle jiného jména.', 'search',
                        '<a class="btn btn--secondary btn--sm" href="' . get_url('weby') . '">Zrušit filtr</a>') ?>
                <?php endif; ?>
            <?php elseif ($grouped): ?>
                <?php foreach ($groups as $clientName => $groupRows): ?>
                    <div class="table__group"><span style="font-weight:var(--font-weight-semibold)"><?= $this->e($clientName) ?></span><span class="text-caption"><?= get_count(count($groupRows), 'web', 'weby', 'webů') ?></span></div>
                    <?php foreach ($groupRows as $site) { $renderRow($site); } ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($rows as $site) { $renderRow($site); } ?>
            <?php endif; ?>
        </div>

        <div class="card__footer card__footer--muted">
            <span>Zobrazeno <?= $shown ?> z <?= $counts['all'] ?> <?= get_plural($counts['all'], 'webu', 'webů', 'webů') ?></span>
            <a class="u-ml-auto" href="<?= get_url('/') ?>">Zpět na dashboard</a>
        </div>
    </section>
</div>
