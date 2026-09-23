<?php
/**
 * Alerty (návrh `alerty.html`, `-vyber`, `-vyresene`, `-prazdno`).
 *
 * Hromadné akce: skutečná zaškrtávátka `ids[]` ve formuláři — bez skriptu
 * se odešlou tlačítky v pruhu (pruh je vidět vždy, skript ho jen doplní
 * o počet). Jednotlivé akce v řádku jsou vlastní formuláře.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<string, array<int, array<string, mixed>>> $groups  Dnes/Včera/… => řádky
 * @var string              $status   open|resolved|ignored|all
 * @var string              $severity ''|error|warning|info
 * @var int|null            $siteId
 * @var array<int, string>  $sites
 * @var array{open: int, error: int, warning: int, info: int, ignored: int, resolved: int, today: int} $counts
 * @var array{age: string, label: string}|null $oldest
 * @var string              $meta
 * @var int                 $shown
 * @var int                 $total
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$url = static fn (array $override): string => get_url('alerty') . '?' . http_build_query(array_filter(array_merge([
    'stav' => $status === 'open' ? null : $status,
    'zavaznost' => $severity,
    'web' => $siteId,
], $override), static fn ($v): bool => $v !== null && $v !== ''));

$segments = [
    ['key' => '', 'label' => 'Vše', 'count' => $counts['open'], 'dot' => ''],
    ['key' => 'error', 'label' => 'Kritické', 'count' => $counts['error'], 'dot' => 'error'],
    ['key' => 'warning', 'label' => 'Varování', 'count' => $counts['warning'], 'dot' => 'warning'],
    ['key' => 'info', 'label' => 'Informace', 'count' => $counts['info'], 'dot' => 'muted'],
];
$states = [['key' => 'open', 'label' => 'Nevyřešené'], ['key' => 'resolved', 'label' => 'Vyřešené'], ['key' => 'ignored', 'label' => 'Ignorované'], ['key' => 'all', 'label' => 'Vše']];
?>
<?php render_page_head('Alerty', $this->e($meta),
    '<a class="btn btn--secondary" href="' . get_url('nastaveni/alerty') . '">Nastavit prahy</a>'
    . ($counts['open'] > 0 ? '<form method="post" action="' . get_url('alerty/vyresit-nove') . '">' . get_csrf($csrfToken) . '<button type="submit" class="btn btn--primary">' . get_btn_icon('check') . 'Vyřešit vše nové</button></form>' : '')) ?>

<div class="app__content">
    <div class="metric-grid">
        <div class="metric">
            <div class="metric__label">Nevyřešené</div>
            <div class="metric__value"><?= $counts['open'] ?></div>
            <div class="metric__note">napříč weby</div>
        </div>
        <div class="metric<?= $counts['error'] > 0 ? ' metric--error' : '' ?>">
            <div class="metric__label row" style="gap:8px"><?= $counts['error'] > 0 ? get_dot('error') : '' ?>Kritické</div>
            <div class="metric__value<?= $counts['error'] > 0 ? ' metric__value--error' : '' ?>"><?= $counts['error'] ?></div>
            <div class="metric__note">web mimo provoz nebo neplatné SSL</div>
        </div>
        <div class="metric">
            <div class="metric__label">Nové dnes</div>
            <div class="metric__value"><?= $counts['today'] ?></div>
            <div class="metric__note"><?= $counts['today'] > 0 ? 'od půlnoci' : 'zatím klid' ?></div>
        </div>
        <div class="metric<?= $oldest !== null ? ' metric--warning' : '' ?>">
            <div class="metric__label row" style="gap:8px"><?= $oldest !== null ? get_dot('warning') : '' ?>Nejdelší otevřený</div>
            <div class="metric__value<?= $oldest !== null ? ' metric__value--warning' : '' ?>"><?= $oldest !== null ? $this->e($oldest['age']) : '—' ?></div>
            <div class="metric__note u-truncate"><?= $oldest !== null ? $this->e($oldest['label']) : 'žádný otevřený' ?></div>
        </div>
    </div>

    <section class="card card--scroll-x">
        <div class="card__header card__header--filters">
            <div class="segmented">
                <?php foreach ($segments as $segment): ?>
                    <a class="segmented__item<?= $severity === $segment['key'] ? ' segmented__item--active' : '' ?>" href="<?= $url(['zavaznost' => $segment['key']]) ?>">
                        <?php if ($segment['dot'] !== ''): ?><span class="segmented__dot" style="background:var(--color-<?= $segment['dot'] === 'muted' ? 'text-faint' : 'status-' . $segment['dot'] ?>)"></span><?php endif; ?>
                        <?= $this->e($segment['label']) ?><span class="segmented__count"><?= $segment['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="pill-group">
                <?php foreach ($states as $state): ?>
                    <a class="pill<?= $status === $state['key'] ? ' pill--brand' : '' ?>" href="<?= $url(['stav' => $state['key'] === 'open' ? null : $state['key']]) ?>"><?= $this->e($state['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <form method="get" action="<?= get_url('alerty') ?>" class="u-ml-auto">
                <?php if ($status !== 'open'): ?><input type="hidden" name="stav" value="<?= $this->e($status) ?>"><?php endif; ?>
                <?php if ($severity !== ''): ?><input type="hidden" name="zavaznost" value="<?= $this->e($severity) ?>"><?php endif; ?>
                <select name="web" class="form__control form__control--inline" style="padding:6px 30px 6px 10px;font-size:var(--font-size-label)" aria-label="Web" onchange="this.form.submit()">
                    <option value="">Web · všechny</option>
                    <?php foreach ($sites as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= $siteId === (int) $id ? ' selected' : '' ?>><?= $this->e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <form method="post" action="<?= get_url('alerty/hromadne') ?>" data-selection>
            <?php render_csrf($csrfToken) ?>

            <?php if ($status === 'open' && $groups !== []): ?>
                <div class="bulk-bar" data-selection-bar hidden>
                    <div class="bulk-bar__note" data-selection-note>Vybráno 0 alertů</div>
                    <div class="bulk-bar__actions">
                        <button class="btn btn--primary btn--sm" type="submit" name="action" value="resolve"><?= get_btn_icon('check') ?>Označit vyřešené</button>
                        <button class="btn btn--secondary btn--sm" type="submit" name="action" value="ignore">Ignorovat</button>
                        <button class="btn btn--ghost btn--sm" type="button" data-selection-clear>Zrušit výběr</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table table--alerts">
                <?php if ($groups === []): ?>
                    <?php render_empty(
                        $status === 'open' ? 'Žádné nevyřešené alerty' : 'Nic tu není',
                        $status === 'open' ? 'Všechno je vyřešené nebo ignorované. Monitor kontroluje weby podle nastavené frekvence.' : 'Filtr nic nevrátil.',
                        'check',
                    ) ?>
                <?php else: ?>
                    <?php foreach ($groups as $groupLabel => $rows): ?>
                        <div class="table__group table__group--caps"><span><?= $this->e($groupLabel) ?></span><span style="font-weight:var(--font-weight-medium);letter-spacing:0;text-transform:none"><?= get_count(count($rows), 'alert', 'alerty', 'alertů') ?></span></div>
                        <?php foreach ($rows as $alert): ?>
                            <div class="table__row table__row--top<?= $alert['severity'] === 'error' && $alert['status'] === 'open' ? ' table__row--error' : '' ?><?= $alert['status'] === 'ignored' ? ' alert-row--muted' : '' ?>">
                                <div class="table__cell">
                                    <?php if ($alert['status'] === 'open'): ?>
                                        <label class="checkbox checkbox--input"><input type="checkbox" name="ids[]" value="<?= (int) $alert['id'] ?>" data-selection-item class="visually-hidden"><span class="checkbox__mark"></span></label>
                                    <?php endif; ?>
                                </div>
                                <div class="table__cell row" style="flex-wrap:nowrap;align-items:flex-start;gap:12px">
                                    <?= get_icon($alert['icon'], 'icon--lg ' . $alert['iconClass']) ?>
                                    <div style="min-width:0">
                                        <div class="table__primary <?= $alert['titleClass'] ?>" style="font-size:var(--font-size-body-large)"><?= $this->e((string) $alert['title']) ?></div>
                                        <div class="table__secondary" style="font-size:var(--font-size-label);text-wrap:pretty"><?= $this->e((string) $alert['body']) ?></div>
                                        <div class="row" style="gap:14px;margin-top:8px">
                                            <?php if ((string) $alert['rule_label'] !== ''): ?><span class="text-caption"><?= $this->e((string) $alert['rule_label']) ?></span><?php endif; ?>
                                            <?php if ($alert['occurrencesLabel'] !== ''): ?><span class="text-caption"><?= $this->e($alert['occurrencesLabel']) ?></span><?php endif; ?>
                                            <?php if ($alert['status'] !== 'open' && (string) $alert['resolve_note'] !== ''): ?><span class="text-caption"><?= $this->e((string) $alert['resolve_note']) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <a class="table__cell row" style="flex-wrap:nowrap;gap:10px;color:var(--color-text-primary);text-decoration:none" href="<?= get_url('weby/' . (int) $alert['site_id']) ?>">
                                    <?= get_site_avatar((int) $alert['site_id'], (string) $alert['site_name'], (string) ($alert['site_icon'] ?? ''), 'sm') ?>
                                    <div style="min-width:0"><div class="u-truncate" style="font-size:var(--font-size-label);font-weight:var(--font-weight-medium)"><?= $this->e((string) $alert['site_name']) ?></div><div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e((string) ($alert['client_name'] ?? $alert['host'])) ?></div></div>
                                </a>
                                <div class="table__cell">
                                    <div style="font-size:var(--font-size-label);color:var(--color-text-secondary)"><?= $this->e($alert['when']) ?></div>
                                    <div class="text-caption<?= $alert['status'] === 'open' && $alert['severity'] === 'error' ? ' text-error' : '' ?>" style="margin-top:3px"><?= $this->e($alert['status'] === 'open' ? $alert['durationLabel'] : $alert['resolvedNote']) ?></div>
                                </div>
                                <div class="table__cell"><?= get_status($alert['stateTone'], $alert['stateLabel']) ?></div>
                                <div class="table__cell row" style="justify-content:flex-end;gap:8px">
                                    <?php if ($alert['status'] === 'open'): ?>
                                        <button class="btn btn--secondary btn--icon" type="submit" form="alert-resolve-<?= (int) $alert['id'] ?>" title="Označit vyřešené"><?= get_icon('check', 'icon--sm') ?></button>
                                        <button class="btn btn--secondary btn--icon" type="submit" form="alert-ignore-<?= (int) $alert['id'] ?>" title="Ignorovat"><?= get_icon('close', 'icon--sm icon--subtle') ?></button>
                                    <?php else: ?>
                                        <button class="btn btn--secondary btn--sm" type="submit" form="alert-reopen-<?= (int) $alert['id'] ?>">Vrátit</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </form>

        <?php // Formuláře řádkových akcí stojí mimo hromadný formulář (vnořené <form> HTML nedovolí);
              // tlačítka na ně míří atributem form="". ?>
        <?php foreach ($groups as $rows): ?>
            <?php foreach ($rows as $alert): ?>
                <?php if ($alert['status'] === 'open'): ?>
                    <form id="alert-resolve-<?= (int) $alert['id'] ?>" method="post" action="<?= get_url('alerty/' . (int) $alert['id'] . '/vyresit') ?>" hidden><?php render_csrf($csrfToken) ?></form>
                    <form id="alert-ignore-<?= (int) $alert['id'] ?>" method="post" action="<?= get_url('alerty/' . (int) $alert['id'] . '/ignorovat') ?>" hidden><?php render_csrf($csrfToken) ?></form>
                <?php else: ?>
                    <form id="alert-reopen-<?= (int) $alert['id'] ?>" method="post" action="<?= get_url('alerty/' . (int) $alert['id'] . '/otevrit') ?>" hidden><?php render_csrf($csrfToken) ?></form>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="card__footer card__footer--muted">
            <span>Zobrazeno <?= $shown ?> z <?= $total ?> <?= get_plural($total, 'alertu', 'alertů', 'alertů') ?></span>
            <a class="u-ml-auto" href="<?= get_url('weby') ?>">Přejít na weby</a>
        </div>
    </section>
</div>
