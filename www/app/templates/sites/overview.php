<?php
/**
 * Detail webu — Přehled (návrh `detail-webu-prehled*.html`).
 *
 * Varianty podle STAVY.md: `default` (otevřený alert v inverzní kartě),
 * `allGood` (klidová karta), `loading` (skeleton bez snapshoty),
 * `apiError` (pás nad obsahem — v `partials/site-header`).
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array<string, array<string, mixed>>|null $metrics  wp/php/db/theme, null = ještě žádná data
 * @var array<int, array{label: string, value: string, tone: string}> $summary
 * @var array<int, array<string, mixed>> $events   posledních 6 událostí
 * @var int                 $eventCount
 * @var string|null         $snapshotAt
 * @var array{days: array<int, array<string, mixed>>, percent: ?float, checks: int, downtime: string, outages: int, avgMs: ?int, axis: array<int, string>} $uptime
 * @var array<int, array<string, mixed>> $openAlerts  otevřené alerty, nejzávažnější první
 * @var string|null         $lastIncident
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name']]);

$main = $openAlerts[0] ?? null;
$percent = $uptime['percent'];
$percentTone = $percent === null ? 'text-faint' : ($percent < 99 ? 'metric__value--error' : ($percent < 99.9 ? 'metric__value--warning' : ''));
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split">
        <div class="stack">

            <?php if ($metrics === null): ?>
                <div class="metric-grid">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="metric metric--compact"><div class="skeleton skeleton--text" style="width:40%"></div><div class="skeleton skeleton--title" style="margin-top:10px;width:60%"></div></div>
                    <?php endfor; ?>
                </div>
            <?php else: ?>
                <div class="metric-grid">
                    <div class="metric metric--compact">
                        <div class="metric__label">WordPress</div>
                        <div class="metric__value"><?= $this->e($metrics['wp']['value']) ?></div>
                        <div class="metric__note"><?= $metrics['wp']['update'] !== null ? get_badge('→ ' . $metrics['wp']['update'], 'warning') : 'aktuální' ?></div>
                    </div>
                    <div class="metric metric--compact<?= $metrics['php']['eol'] ? ' metric--error' : '' ?>">
                        <div class="metric__label">PHP</div>
                        <div class="metric__value<?= $metrics['php']['eol'] ? ' metric__value--error' : '' ?>"><?= $this->e($metrics['php']['value']) ?></div>
                        <div class="metric__note"><?= $metrics['php']['eol'] ? get_badge('konec podpory', 'error') : ($metrics['php']['daysLeft'] !== null && $metrics['php']['daysLeft'] < 180 ? get_badge('podpora končí za ' . $metrics['php']['daysLeft'] . ' d', 'warning') : 'podporované') ?></div>
                    </div>
                    <div class="metric metric--compact">
                        <div class="metric__label">Databáze</div>
                        <div class="metric__value"><?= $this->e($metrics['db']['value']) ?></div>
                        <div class="metric__note"><?= $this->e($metrics['db']['size']) ?></div>
                    </div>
                    <div class="metric metric--compact">
                        <div class="metric__label">Šablona</div>
                        <div class="metric__value u-truncate"><?= $this->e($metrics['theme']['value']) ?></div>
                        <div class="metric__note"><?= $this->e($metrics['theme']['note']) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <section class="card card--padded">
                <div class="row" style="justify-content:space-between;align-items:flex-start">
                    <div>
                        <div class="metric__label">Uptime · posledních 30 dní</div>
                        <div class="row" style="gap:12px;align-items:baseline;margin-top:4px">
                            <div class="metric__value <?= $percentTone ?>"><?= $percent !== null ? $this->e(number_format($percent, $percent >= 99.95 ? 0 : 1, ',', ' ')) . ' %' : '—' ?></div>
                            <div class="text-subtle"><?= $uptime['checks'] === 0 ? 'zatím žádná kontrola dostupnosti' : ($uptime['outages'] > 0 ? get_count($uptime['outages'], 'výpadek', 'výpadky', 'výpadků') . ($uptime['downtime'] !== '' ? ' · ' . $uptime['downtime'] : '') : 'bez výpadku') ?></div>
                        </div>
                    </div>
                    <div class="row" style="gap:18px">
                        <?php if ($uptime['avgMs'] !== null): ?><span class="text-subtle"><b><?= $uptime['avgMs'] ?> ms</b> ø odezva</span><?php endif; ?>
                        <span class="text-subtle"><b><?= number_format($uptime['checks'], 0, ',', ' ') ?></b> kontrol</span>
                    </div>
                </div>
                <div class="uptime">
                    <div class="uptime__grid">
                        <?php foreach ($uptime['days'] as $day): ?>
                            <span class="uptime__day<?= $day['tone'] === 'error' ? ' uptime__day--error' : ($day['tone'] === 'warning' ? ' uptime__day--warning' : ($day['tone'] === 'none' ? ' uptime__day--none' : '')) ?>" title="<?= $this->e(get_czech_date($day['day'])) ?>: <?= $day['checks'] === 0 ? 'bez kontrol' : $day['checks'] . ' kontrol, ' . $day['failed'] . ' selhalo' ?>"></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="uptime__axis"><span><?= $this->e($uptime['axis'][0]) ?></span><span><?= $this->e($uptime['axis'][1]) ?></span><span><?= $this->e($uptime['axis'][2]) ?></span></div>
                    <div class="uptime__legend">
                        <span class="uptime__legend-item"><span class="uptime__swatch uptime__swatch--ok"></span>100 %</span>
                        <span class="uptime__legend-item"><span class="uptime__swatch uptime__swatch--warning"></span>výpadek do 30 min</span>
                        <span class="uptime__legend-item"><span class="uptime__swatch uptime__swatch--error"></span>výpadek nad 30 min</span>
                    </div>
                </div>
            </section>

            <section class="card">
                <?php render_card_head('Historie změn', '', $eventCount > 6 ? '<a href="' . get_url('weby/' . (int) $site['id'] . '/historie') . '">Zobrazit vše</a>' : '') ?>
                <?php if ($events === []): ?>
                    <?php render_empty('Zatím žádná událost', 'Historie se plní kontrolami, změnami verzí, servisy a reporty.', 'clock') ?>
                <?php else: ?>
                    <div class="table table--history">
                        <?php foreach ($events as $event): ?>
                            <div class="table__row">
                                <div class="table__cell"><?= get_dot((string) $event['tone']) ?></div>
                                <div class="table__cell"><?= $this->e((string) $event['message']) ?></div>
                                <div class="table__cell caps u-hide-mobile"><?= $this->e($event['kindLabel']) ?></div>
                                <div class="table__cell table__cell--right text-subtle"><?= $this->e($event['when']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="split__aside">
            <?php if ($main !== null): ?>
                <div class="alert alert--inverse">
                    <div class="alert__label"><span class="alert__dot"></span>Aktivní alert · <?= $this->e($main['age']) ?></div>
                    <div class="alert__title"><?= $this->e((string) $main['title']) ?></div>
                    <div class="alert__body">Od <?= $this->e($main['since']) ?>. <?= $this->e((string) $main['body']) ?></div>
                    <div class="alert__actions">
                        <form method="post" action="<?= get_url('alerty/' . (int) $main['id'] . '/vyresit') ?>"><?php render_csrf($csrfToken) ?><button class="btn btn--inverse btn--sm" type="submit">Označit vyřešeno</button></form>
                        <form method="post" action="<?= get_url('alerty/' . (int) $main['id'] . '/ignorovat') ?>"><?php render_csrf($csrfToken) ?><button class="btn btn--ghost-inverse btn--sm" type="submit">Ignorovat</button></form>
                    </div>
                </div>
                <?php if (count($openAlerts) > 1): ?>
                    <div class="card card--small-shadow">
                        <div class="check-list">
                            <?php foreach (array_slice($openAlerts, 1) as $alert): ?>
                                <div class="check-list__item check-list__item--<?= $alert['severity'] === 'error' ? 'error' : 'warning' ?>">
                                    <span class="check-list__dot"></span>
                                    <div>
                                        <div class="check-list__title"><?= $this->e((string) $alert['title']) ?></div>
                                        <div class="check-list__text">od <?= $this->e($alert['since']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card__footer"><a class="btn btn--secondary btn--sm btn--block" href="<?= get_url('alerty?web=' . (int) $site['id']) ?>">Všechny alerty webu</a></div>
                    </div>
                <?php endif; ?>
            <?php elseif ($metrics === null && $uptime['checks'] === 0): ?>
                <div class="score-card score-card--quiet">
                    <div class="score-card__label"><?= get_dot('muted') ?>Čeká na první data</div>
                    <div class="score-card__note">Nainstalujte na web plugin MEDIAGRAFIK Monitor, vložte API klíč z Nastavení a klikněte na „Zkontrolovat teď".</div>
                </div>
            <?php else: ?>
                <div class="score-card score-card--ok score-card--quiet">
                    <div class="score-card__label"><span class="score-card__dot"></span>Bez otevřených alertů</div>
                    <div class="score-card__note"><?= $this->e($lastIncident ?? ($snapshotAt !== null ? 'Data z pluginu načtena ' . get_when((string) $snapshotAt) . '.' : 'Web odpovídá, žádný incident v historii.')) ?></div>
                </div>
            <?php endif; ?>

            <div class="card card--small-shadow">
                <div class="summary-list">
                    <?php foreach ($summary as $row): ?>
                        <div class="summary-list__row">
                            <span class="summary-list__label"><?= $this->e($row['label']) ?></span>
                            <span class="summary-list__value<?= $row['tone'] !== '' ? ' text-' . $row['tone'] : '' ?>"><?= $this->e($row['value']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card card--note">
                <div class="row" style="justify-content:space-between">
                    <span style="font-weight:var(--font-weight-semibold)">Klientský report</span>
                    <a href="<?= get_url('weby/' . (int) $site['id'] . '/reporty') ?>"><?= $reportCard['active'] ? 'Reporty' : 'Nastavit' ?></a>
                </div>
                <div class="text-subtle" style="margin-top:5px"><?= $this->e($reportCard['line1']) ?><br><?= $this->e($reportCard['line2']) ?></div>
            </div>
        </aside>
    </div>
</div>
