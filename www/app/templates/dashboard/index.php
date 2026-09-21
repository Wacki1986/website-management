<?php
/**
 * Dashboard (návrh `dashboard.html`, `-allgood`, `-empty`, `-loading`,
 * `-apierror`, `-outage`, `-manyupdates`). Segmenty a filtr klienta jsou
 * odkazy/GET formulář, „Zkontrolovat vše" je POST.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array{all: int, problem: int, attention: int, ok: int, unknown: int} $counts
 * @var int                 $needs
 * @var array<string, mixed> $hero
 * @var array<string, array{value: int, sub: string}> $metrics
 * @var array<int, array<string, mixed>> $rows
 * @var array{state: string, at: ?string, error: string} $monitor
 * @var bool                $loading
 * @var string              $level
 * @var int|null            $clientId
 * @var string              $q
 * @var array<int, string>  $clients
 * @var string              $monitorUrl
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$url = static fn (array $override): string => get_url('/') . '?' . http_build_query(array_filter([
    'stav' => $level,
    'klient' => $clientId,
    'q' => $q,
] + $override, static fn ($v): bool => $v !== null && $v !== ''));

$segments = [
    ['key' => '', 'label' => 'Vše', 'count' => $needs, 'dot' => ''],
    ['key' => 'problem', 'label' => 'Problém', 'count' => $counts['problem'], 'dot' => 'error'],
    ['key' => 'attention', 'label' => 'Pozornost', 'count' => $counts['attention'] + $counts['unknown'], 'dot' => 'warning'],
];
?>
<div class="app__content">
    <?php if ($counts['all'] === 0): ?>
        <?php render_page_head('Dashboard', 'Přehled stavu všech webů v péči studia') ?>
        <section class="card">
            <?php render_empty(
                'Zatím žádný web v monitoringu',
                'Přidejte první web, nastavte mu API klíč pluginu MEDIAGRAFIK Monitor a dashboard se začne plnit.',
                'globe',
                '<a class="btn btn--primary" href="' . get_url('weby/pridat') . '">' . get_btn_icon('plus') . 'Přidat web</a>',
            ) ?>
        </section>
    <?php else: ?>
        <?php if ($monitor['state'] === 'failed' || $monitor['state'] === 'stale'): ?>
            <div class="alert alert--row">
                <span class="alert__dot"></span>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:var(--font-weight-semibold);color:var(--color-status-error-text)"><?= $monitor['state'] === 'stale' ? 'Monitor neběží' : 'Poslední průchod monitoru selhal' ?></div>
                    <div class="text-caption"><?= $this->e($monitor['error']) ?> Poslední úspěšná kontrola <?= $this->e(get_when($monitor['at'])) ?>. Zobrazená data mohou být zastaralá.</div>
                </div>
                <form method="post" action="<?= get_url('nastaveni/monitoring/spustit') ?>"><?php render_csrf($csrfToken) ?><button class="btn btn--secondary btn--sm" type="submit">Zkusit znovu</button></form>
                <a class="btn btn--ghost btn--sm" href="<?= $monitorUrl ?>">Nastavení</a>
            </div>
        <?php elseif ($monitor['state'] === 'never'): ?>
            <div class="alert alert--row alert--info">
                <?= get_icon('clock', 'icon--lg icon--subtle') ?>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:var(--font-weight-semibold)">Cron zatím neběžel</div>
                    <div class="text-caption">Nastavte hostingový cron podle Nastavení → Monitoring, nebo spusťte první průchod ručně.</div>
                </div>
                <form method="post" action="<?= get_url('nastaveni/monitoring/spustit') ?>"><?php render_csrf($csrfToken) ?><button class="btn btn--secondary btn--sm" type="submit">Spustit průchod</button></form>
            </div>
        <?php endif; ?>

        <div class="hero-grid">
            <section class="hero">
                <div class="hero__top">
                    <div class="hero__label<?= $hero['calm'] ? ' hero__label--ok' : '' ?>"><span class="dot dot--<?= $hero['calm'] ? 'ok' : 'error' ?>" style="width:8px;height:8px"></span><?= $hero['calm'] ? 'Vše pod kontrolou' : 'Vyžaduje zásah' ?></div>
                    <div class="hero__timestamp"><?= $this->e($hero['timestamp']) ?></div>
                </div>
                <div class="hero__body">
                    <?php if ($hero['calm']): ?>
                        <div class="hero__calm"><?= $this->e($hero['calmText']) ?></div>
                    <?php else: ?>
                        <?php foreach ($hero['items'] as $item): ?>
                            <div class="hero__item">
                                <?= get_avatar($item['name'], '', 'md') ?>
                                <div style="min-width:140px;flex:1">
                                    <div class="hero__item-title u-truncate"><?= $this->e($item['name']) ?></div>
                                    <div class="hero__item-note"><?= $this->e($item['note']) ?></div>
                                </div>
                                <div style="text-align:right;flex:none">
                                    <div style="font-size:var(--font-size-label);font-weight:var(--font-weight-semibold);color:var(--color-status-error-on-inverse)"><?= $this->e($item['duration']) ?></div>
                                    <div class="hero__timestamp"><?= $this->e($item['kind']) ?></div>
                                </div>
                                <a class="btn btn--inverse btn--sm" href="<?= get_url('weby/' . $item['id']) ?>">Řešit</a>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($hero['more'] > 0): ?>
                            <div class="hero__timestamp">a <?= $this->e(get_count($hero['more'], 'další web', 'další weby', 'dalších webů')) ?> s problémem — viz tabulka níže</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="hero__footer">
                    <span><?= $this->e($hero['footer']) ?></span>
                    <a href="<?= get_url('weby') ?>">Zobrazit →</a>
                </div>
            </section>

            <div class="metric-list">
                <div class="metric-row">
                    <div style="min-width:0"><div class="metric-row__label">Monitorované weby</div><div class="metric-row__sub"><?= $this->e($metrics['sites']['sub']) ?></div></div>
                    <div class="metric-row__value"><?= (int) $metrics['sites']['value'] ?></div>
                </div>
                <div class="metric-row">
                    <div style="min-width:0"><div class="metric-row__label">Vyžaduje pozornost</div><div class="metric-row__sub"><?= $this->e($metrics['attention']['sub']) ?></div></div>
                    <div class="metric-row__value<?= $metrics['attention']['value'] > 0 ? ' metric-row__value--warning' : '' ?>"><?= (int) $metrics['attention']['value'] ?></div>
                </div>
                <div class="metric-row">
                    <div style="min-width:0"><div class="metric-row__label">Čekající aktualizace</div><div class="metric-row__sub"><?= $this->e($metrics['updates']['sub']) ?></div></div>
                    <div class="metric-row__value"><?= (int) $metrics['updates']['value'] ?></div>
                </div>
                <div class="metric-row">
                    <div style="min-width:0"><div class="metric-row__label">Aktivní alerty</div><div class="metric-row__sub"><?= $this->e($metrics['alerts']['sub']) ?></div></div>
                    <div class="metric-row__value<?= $metrics['alerts']['value'] > 0 && $counts['problem'] > 0 ? ' metric-row__value--error' : '' ?>"><?= (int) $metrics['alerts']['value'] ?></div>
                </div>
            </div>
        </div>

        <section class="card card--scroll-x">
            <div class="card__header card__header--filters">
                <div style="min-width:0;margin-right:auto">
                    <div class="card__title">Vyžaduje řešení</div>
                    <div class="text-caption">Weby s problémem nebo vyžadující pozornost · v pořádku běžících <?= (int) $counts['ok'] ?> najdete v sekci Weby</div>
                </div>
                <form method="get" action="<?= get_url('/') ?>" class="row" style="gap:10px">
                    <?php if ($level !== ''): ?><input type="hidden" name="stav" value="<?= $this->e($level) ?>"><?php endif; ?>
                    <?php if ($clientId !== null): ?><input type="hidden" name="klient" value="<?= (int) $clientId ?>"><?php endif; ?>
                    <label class="search" style="max-width:280px">
                        <?= get_icon('search', 'icon--sm') ?>
                        <input class="search__input" type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Hledat web nebo klienta…" aria-label="Hledat">
                    </label>
                </form>
                <form method="post" action="<?= get_url('weby/zkontrolovat-vse') ?>"><?php render_csrf($csrfToken) ?><button class="btn btn--primary" type="submit"><?= get_btn_icon('refresh') ?>Zkontrolovat vše</button></form>
            </div>
            <div class="card__header card__header--filters">
                <div class="segmented">
                    <?php foreach ($segments as $segment): ?>
                        <a class="segmented__item<?= $level === $segment['key'] ? ' segmented__item--active' : '' ?>" href="<?= $url(['stav' => $segment['key']]) ?>">
                            <?php if ($segment['dot'] !== ''): ?><span class="segmented__dot" style="background:var(--color-status-<?= $segment['dot'] ?>)"></span><?php endif; ?>
                            <?= $this->e($segment['label']) ?><span class="segmented__count"><?= (int) $segment['count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <form method="get" action="<?= get_url('/') ?>">
                    <?php if ($level !== ''): ?><input type="hidden" name="stav" value="<?= $this->e($level) ?>"><?php endif; ?>
                    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= $this->e($q) ?>"><?php endif; ?>
                    <select name="klient" class="form__control form__control--inline" style="padding:6px 30px 6px 10px;font-size:var(--font-size-label)" aria-label="Klient" onchange="this.form.submit()">
                        <option value="">Klient · všichni</option>
                        <?php foreach ($clients as $id => $name): ?>
                            <option value="<?= (int) $id ?>"<?= $clientId === (int) $id ? ' selected' : '' ?>><?= $this->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a class="u-ml-auto" href="<?= get_url('weby') ?>" style="font-size:var(--font-size-label);font-weight:var(--font-weight-semibold)">Zobrazit všech <?= (int) $counts['all'] ?> webů →</a>
            </div>

            <div class="table table--dashboard">
                <div class="table__head"><div>Web</div><div>Stav</div><div class="table__cell table__cell--right">Uptime 30 d</div><div>WP</div><div>PHP</div><div>DB</div><div class="table__cell table__cell--right">Aktualizace</div><div>Servis</div><div class="table__cell table__cell--right">Kontrola</div><div></div></div>
                <?php if ($loading): ?>
                    <?php for ($i = 0; $i < 3; $i++): ?>
                        <div class="table__row" aria-hidden="true">
                            <div class="table__cell row" style="flex-wrap:nowrap;gap:13px"><div class="skeleton" style="width:32px;height:32px;border-radius:var(--border-radius-medium)"></div><div style="flex:1"><div class="skeleton skeleton--text" style="width:62%"></div><div class="skeleton" style="height:9px;width:34%;margin-top:7px"></div></div></div>
                            <div class="table__cell"><div class="skeleton skeleton--text" style="width:86px"></div></div>
                            <div class="table__cell"><div class="skeleton skeleton--text" style="width:54px"></div></div>
                            <div class="table__cell"><div class="skeleton skeleton--text" style="width:40px"></div></div>
                            <div class="table__cell"><div class="skeleton skeleton--text" style="width:36px"></div></div>
                            <div class="table__cell"><div class="skeleton skeleton--text" style="width:80px"></div></div>
                            <div class="table__cell"><div class="skeleton" style="width:40px;height:18px"></div></div>
                            <div class="table__cell"><div class="skeleton skeleton--text" style="width:70px"></div></div>
                            <div class="table__cell"><div class="skeleton skeleton--text" style="width:60px"></div></div>
                            <div class="table__cell"></div>
                        </div>
                    <?php endfor; ?>
                <?php elseif ($rows === []): ?>
                    <?php render_empty(
                        $needs === 0 ? 'Nic nevyžaduje řešení' : 'Nic neodpovídá filtru',
                        $needs === 0 ? 'Všechny weby běží bez odchylky. Přehled všech je v sekci Weby.' : 'Zkuste jiný segment, klienta nebo hledání.',
                        'check',
                    ) ?>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <a class="table__row table__row--link<?= $row['level'] === 'problem' ? ' table__row--error' : '' ?>" href="<?= get_url('weby/' . $row['id']) ?>">
                            <div class="table__cell row" style="flex-wrap:nowrap;gap:13px"><?= get_avatar($row['name']) ?><div style="min-width:0"><div class="table__primary u-truncate"><?= $this->e($row['name']) ?></div><div class="table__secondary u-truncate" style="margin-top:0"><?= $this->e($row['host']) ?></div></div></div>
                            <div class="table__cell"><?= get_status($row['state']['tone'], $row['state']['label']) ?></div>
                            <div class="table__cell table__cell--mono table__cell--right<?= $row['uptimeTone'] === 'faint' ? ' text-faint' : ($row['uptimeTone'] !== '' ? ' text-' . $row['uptimeTone'] : ' text-secondary') ?>"><?= $this->e($row['uptime']) ?></div>
                            <div class="table__cell table__cell--mono<?= $row['wpTone'] !== '' ? ' text-' . $row['wpTone'] : ' text-secondary' ?>"><?= $this->e($row['wp'] !== '' ? $row['wp'] : '—') ?></div>
                            <div class="table__cell table__cell--mono<?= $row['phpTone'] !== '' ? ' text-' . $row['phpTone'] : ' text-secondary' ?>"><?= $this->e($row['php'] !== '' ? $row['php'] : '—') ?></div>
                            <div class="table__cell table__cell--mono u-truncate"><?= $this->e($row['db'] !== '' ? $row['db'] : '—') ?></div>
                            <div class="table__cell table__cell--right"><?= $row['updates'] > 0 ? get_badge((string) $row['updates'], $row['updates'] >= 5 ? 'warning' : '') : '<span class="text-faint">–</span>' ?></div>
                            <div class="table__cell row" style="flex-wrap:nowrap;gap:8px"><span class="u-truncate<?= $row['serviceTone'] !== '' ? ' text-' . $row['serviceTone'] : '' ?>" style="font-size:var(--font-size-label)"><?= $this->e($row['service']) ?></span></div>
                            <div class="table__cell table__cell--right text-subtle" style="font-size:var(--font-size-label)"><?= $this->e($row['checked']) ?></div>
                            <div class="table__cell table__cell--right"><?= get_icon('chevron-right', 'icon--sm icon--subtle') ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card__footer card__footer--muted">
                <span><?= $this->e(get_count($needs, 'web vyžaduje řešení', 'weby vyžadují řešení', 'webů vyžaduje řešení') . ' · ' . $counts['ok'] . ' v pořádku') ?></span>
                <a class="u-ml-auto" href="<?= get_url('alerty') ?>">Otevřít alerty →</a>
            </div>
        </section>
    <?php endif; ?>
</div>
