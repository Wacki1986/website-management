<?php
/**
 * Reporty — fronta a historie (návrh `reporty-naplanovane.html`,
 * `-odeslane`, `-problemy`). Segmenty jsou odkazy; naplánované řádky
 * bez reportu vedou na náhled, který koncept založí.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<string, array<int, array<string, mixed>>> $groups
 * @var string              $status pending|sent|failed|all
 * @var string              $q
 * @var string              $period
 * @var array<int, string>  $periods
 * @var array<string, mixed> $counts
 * @var string              $pendingNote
 * @var string              $failedNote
 * @var string              $meta
 * @var int                 $shown
 * @var bool                $canSendScheduled
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$url = static fn (array $override): string => get_url('reporty') . '?' . http_build_query(array_filter(array_merge([
    'stav' => $status === 'pending' ? null : $status,
    'q' => $q,
    'obdobi' => $period,
], $override), static fn ($v): bool => $v !== null && $v !== ''));

$segments = [
    ['key' => 'pending', 'label' => 'Naplánované', 'count' => $counts['scheduled']],
    ['key' => 'sent', 'label' => 'Odeslané', 'count' => $counts['sent_month']],
    ['key' => 'failed', 'label' => 'Problémy', 'count' => $counts['failed']],
    ['key' => 'all', 'label' => 'Vše', 'count' => null],
];

$periodLabel = static function (string $ym): string {
    [$y, $m] = explode('-', $ym);

    return get_czech_month((int) $m) . ' ' . $y;
};
?>
<?php render_page_head('Reporty', $this->e($meta),
    $canSendScheduled ? '<form method="post" action="' . get_url('reporty/odeslat-naplanovane') . '">' . get_csrf($csrfToken) . '<button type="submit" class="btn btn--primary">' . get_btn_icon('mail') . 'Odeslat naplánované</button></form>' : '') ?>

<div class="app__content">
    <div class="metric-grid">
        <div class="metric">
            <div class="metric__label">Odesláno tento měsíc</div>
            <div class="metric__value"><?= (int) $counts['sent_month'] ?></div>
            <div class="metric__note">z <?= $this->e(get_count((int) $counts['sitesWithReport'], 'webu s reportem', 'webů s reportem', 'webů s reportem')) ?></div>
        </div>
        <div class="metric">
            <div class="metric__label">Otevřenost</div>
            <div class="metric__value"><?= $counts['openRate'] !== null ? (int) $counts['openRate'] . ' %' : '—' ?></div>
            <div class="metric__note"><?= $counts['sent_90d'] > 0 ? $this->e(get_count((int) $counts['opened_90d'], 'klient report otevřel', 'klienti report otevřeli', 'klientů report otevřelo')) . ' (90 dní)' : 'zatím žádný odeslaný' ?></div>
        </div>
        <div class="metric<?= $counts['pending'] > 0 ? ' metric--warning' : '' ?>">
            <div class="metric__label row" style="gap:8px"><?= $counts['pending'] > 0 ? get_dot('warning') : '' ?>Čeká na schválení</div>
            <div class="metric__value<?= $counts['pending'] > 0 ? ' metric__value--warning' : '' ?>"><?= (int) $counts['pending'] ?></div>
            <div class="metric__note u-truncate"><?= $this->e($pendingNote) ?></div>
        </div>
        <div class="metric<?= $counts['failed'] > 0 ? ' metric--error' : '' ?>">
            <div class="metric__label row" style="gap:8px"><?= $counts['failed'] > 0 ? get_dot('error') : '' ?>Nedoručeno</div>
            <div class="metric__value<?= $counts['failed'] > 0 ? ' metric__value--error' : '' ?>"><?= (int) $counts['failed'] ?></div>
            <div class="metric__note u-truncate"><?= $this->e($failedNote) ?></div>
        </div>
    </div>

    <?php if ($counts['failed'] > 0 && $status !== 'failed'): ?>
        <div class="alert alert--row">
            <?= get_icon('alert', 'icon--lg icon--error') ?>
            <div style="flex:1;min-width:0">
                <div style="font-weight:var(--font-weight-semibold);color:var(--color-status-error-text)"><?= $this->e(get_count((int) $counts['failed'], 'report se nepodařilo doručit', 'reporty se nepodařilo doručit', 'reportů se nepodařilo doručit')) ?></div>
                <div class="text-caption"><?= $this->e((string) $counts['failed_error']) ?> Opravte adresu u webu a odešlete report znovu z náhledu.</div>
            </div>
            <a class="btn btn--secondary btn--sm" href="<?= $url(['stav' => 'failed']) ?>">Zobrazit</a>
        </div>
    <?php endif; ?>

    <section class="card card--scroll-x">
        <div class="card__header card__header--filters">
            <div class="segmented">
                <?php foreach ($segments as $segment): ?>
                    <a class="segmented__item<?= $status === $segment['key'] ? ' segmented__item--active' : '' ?>" href="<?= $url(['stav' => $segment['key'] === 'pending' ? null : $segment['key']]) ?>"><?= $this->e($segment['label']) ?><?php if ($segment['count'] !== null): ?><span class="segmented__count"><?= (int) $segment['count'] ?></span><?php endif; ?></a>
                <?php endforeach; ?>
            </div>
            <form method="get" action="<?= get_url('reporty') ?>" class="row" style="flex:1;gap:10px">
                <?php if ($status !== 'pending'): ?><input type="hidden" name="stav" value="<?= $this->e($status) ?>"><?php endif; ?>
                <label class="search" style="max-width:300px">
                    <?= get_icon('search', 'icon--sm') ?>
                    <input class="search__input" type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Hledat web nebo klienta…" aria-label="Hledat">
                </label>
                <select name="obdobi" class="form__control form__control--inline u-ml-auto" style="padding:6px 30px 6px 10px;font-size:var(--font-size-label)" aria-label="Období" onchange="this.form.submit()">
                    <option value="">Období · vše</option>
                    <?php foreach ($periods as $ym): ?>
                        <option value="<?= $this->e($ym) ?>"<?= $period === $ym ? ' selected' : '' ?>><?= $this->e($periodLabel($ym)) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn--secondary btn--sm" type="submit">Filtrovat</button></noscript>
            </form>
        </div>

        <div class="table table--report-queue">
            <?php if ($groups === []): ?>
                <?php render_empty(
                    $status === 'pending' ? 'Žádný naplánovaný report' : ($status === 'failed' ? 'Žádný problém s doručením' : 'Nic tu není'),
                    $status === 'pending' ? 'Reporty se zapínají u každého webu v Nastavení → Klientské reporty.' : 'Filtr nic nevrátil.',
                    $status === 'failed' ? 'check' : 'report',
                ) ?>
            <?php else: ?>
                <div class="table__head"><div>Web</div><div>Klient</div><div>Období</div><div>Termín</div><div>Příjemce</div><div>Stav</div><div></div></div>
                <?php foreach ($groups as $groupLabel => $rows): ?>
                    <div class="table__group table__group--caps"><span><?= $this->e($groupLabel) ?></span><span style="font-weight:var(--font-weight-medium);letter-spacing:0;text-transform:none"><?= $this->e(get_count(count($rows), 'report', 'reporty', 'reportů')) ?></span></div>
                    <?php foreach ($rows as $row): ?>
                        <div class="table__row<?= $row['isProblem'] ? ' table__row--error' : '' ?>">
                            <a class="table__cell row" style="flex-wrap:nowrap;gap:11px;color:var(--color-text-primary);text-decoration:none" href="<?= get_url('weby/' . $row['siteId'] . '/reporty') ?>">
                                <?= get_site_avatar($row['siteId'], $row['siteName'], $row['siteIcon'], 'sm') ?>
                                <div style="min-width:0"><div class="table__primary u-truncate" style="font-size:var(--font-size-label)"><?= $this->e($row['siteName']) ?></div><div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($row['summaryLine'] !== '' ? $row['summaryLine'] : $row['host']) ?></div></div>
                            </a>
                            <div class="table__cell u-truncate text-secondary" style="font-size:var(--font-size-label)"><?= $this->e($row['clientName'] !== '' ? $row['clientName'] : '—') ?></div>
                            <div class="table__cell u-truncate text-secondary" style="font-size:var(--font-size-label)"><?= $this->e($row['period']) ?></div>
                            <div class="table__cell"><div class="u-nowrap<?= $row['when']['tone'] !== '' ? ' text-' . $row['when']['tone'] : '' ?>" style="font-size:var(--font-size-label);<?= $row['when']['tone'] === '' ? 'color:var(--color-text-secondary)' : '' ?>"><?= $this->e($row['when']['main']) ?></div><?php if ($row['when']['sub'] !== ''): ?><div class="text-caption u-nowrap"><?= $this->e($row['when']['sub']) ?></div><?php endif; ?></div>
                            <div class="table__cell u-truncate text-secondary" style="font-size:var(--font-size-label)"><?= $this->e($row['recipients'] !== '' ? $row['recipients'] : '—') ?></div>
                            <div class="table__cell"><?= get_status($row['tone'], $row['label']) ?></div>
                            <div class="table__cell row" style="justify-content:flex-end;gap:8px">
                                <a href="<?= $row['previewUrl'] ?>" style="font-size:var(--font-size-label);font-weight:var(--font-weight-semibold)"><?= $this->e($row['action']) ?></a>
                                <?php if ($row['status'] === 'sent' || $row['status'] === 'partial'): ?>
                                    <a class="btn--menu" href="<?= get_url('reporty/' . $row['id']) ?>" target="_blank" rel="noopener" title="Otevřít odeslané HTML"><?= get_icon('external', 'icon--sm icon--subtle') ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card__footer card__footer--muted">
            <span>Zobrazeno <?= $this->e(get_count($shown, 'report', 'reporty', 'reportů')) ?></span>
        </div>
    </section>
</div>
