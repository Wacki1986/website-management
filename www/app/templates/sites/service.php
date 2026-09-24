<?php
/**
 * Detail webu — Servis (návrh `detail-webu-servis.html`).
 *
 * Druh servisu jsou rádia stylovaná jako `.choice__item`, opakování rádia
 * jako `.segmented__item`, přepínač plánu `get_toggle()` — všechno
 * obyčejná pole jednoho formuláře, bez JavaScriptu.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array{is_active: bool, kind: string, frequency: string, first_date: string} $values
 * @var array<string, array{label: string, note: string, icon: string}> $kinds
 * @var array<string, array{label: string, months: int}> $frequencies
 * @var array<int, array{date: string, countdown: string, tone: string}> $upcoming
 * @var array{date: string, countdown: string, overdue: bool, note: string}|null $next
 * @var string              $planNote
 * @var array{kind: string, frequency: string, year: string} $summary
 * @var array<int, array<string, mixed>> $logs  řádky historie; `text` = popis, nebo odškrtnuté úkoly, `progress` = „3 z 4 úkolů" (nebo ''), `editUrl` = úprava zápisu
 * @var string              $logsNote
 * @var string              $prefill
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Servis']);

$base = 'weby/' . (int) $site['id'];
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split">
        <section class="card card--padded">
            <form method="post" action="<?= get_url($base . '/servis/plan') ?>" class="form">
                <?php render_csrf($csrfToken) ?>
                <div class="row" style="justify-content:space-between;align-items:flex-start">
                    <div>
                        <div class="card__title">Plán servisu</div>
                        <div class="card__note"><?= $this->e($planNote) ?></div>
                    </div>
                    <?= get_toggle('is_active', $values['is_active'], 'Plán servisu zapnutý') ?>
                </div>

                <div class="form__field">
                    <span class="form__label">1 · Druh servisu</span>
                    <div class="choice">
                        <?php foreach ($kinds as $code => $kind): ?>
                            <label class="choice__item">
                                <input type="radio" name="kind" value="<?= $this->e($code) ?>"<?= $values['kind'] === $code ? ' checked' : '' ?> class="visually-hidden">
                                <div class="choice__title"><?= get_icon($kind['icon'], 'icon--sm icon--subtle') ?><?= $this->e($kind['label']) ?></div>
                                <div class="choice__note"><?= $this->e($kind['note']) ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form__row">
                    <div class="form__field">
                        <label class="form__label" for="first_date">2 · První servis</label>
                        <input class="form__control" type="date" id="first_date" name="first_date" value="<?= $this->e($values['first_date']) ?>">
                        <div class="form__hint">Od tohoto data se počítají další termíny.</div>
                    </div>
                    <div class="form__field">
                        <span class="form__label">3 · Jak často se opakuje</span>
                        <div class="segmented">
                            <?php foreach ($frequencies as $code => $frequency): ?>
                                <label class="segmented__item"><input type="radio" name="frequency" value="<?= $this->e($code) ?>"<?= $values['frequency'] === $code ? ' checked' : '' ?> class="visually-hidden"><?= $this->e($frequency['label']) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form__hint"><?= $values['first_date'] !== '' ? 'Další termíny: ' . mb_strtolower($this->e($frequencies[$values['frequency']]['label'])) . ' od ' . $this->e(get_czech_date($values['first_date'])) . '.' : 'Vyplňte první servis.' ?></div>
                    </div>
                </div>

                <?php if ($upcoming !== []): ?>
                    <div class="panel">
                        <div class="caps">Nadcházející termíny</div>
                        <div class="pill-group" style="margin-top:10px">
                            <?php foreach ($upcoming as $term): ?>
                                <span class="pill<?= $term['tone'] === 'error' ? ' pill--error' : ($term['tone'] === 'active' ? ' pill--active' : '') ?>"><?= $this->e($term['date']) ?><span class="pill__note"><?= $this->e($term['countdown']) ?></span></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card__footer" style="padding:14px 0 0">
                    <div class="text-subtle" style="font-size:var(--font-size-label)"><?= $values['is_active'] ? 'Plán je uložený.' : 'Plán je vypnutý.' ?></div>
                    <div class="row u-ml-auto">
                        <a class="btn btn--ghost btn--sm" href="<?= get_url($base . '/servis') ?>">Zrušit změny</a>
                        <button class="btn btn--secondary btn--sm" type="submit"><?= get_btn_icon('check') ?>Uložit plán</button>
                    </div>
                </div>
            </form>
        </section>

        <aside class="split__aside">
            <?php if ($next !== null): ?>
                <div class="score-card <?= $next['overdue'] ? 'score-card--error' : 'score-card--ok score-card--quiet' ?>">
                    <div class="score-card__label"><span class="score-card__dot"></span><?= $next['overdue'] ? 'Servis po termínu' : 'Příští servis' ?></div>
                    <div class="score-card__value"><?= $this->e($next['date']) ?></div>
                    <div class="score-card__note"><?= $this->e($next['note']) ?></div>
                    <div class="alert__actions">
                        <form method="post" action="<?= get_url($base . '/servis/posunout') ?>"><?php render_csrf($csrfToken) ?><button class="btn btn--secondary btn--sm" type="submit">Posunout o týden</button></form>
                        <a class="btn btn--primary btn--sm" href="<?= get_url($base . '/servis/zapsat') ?>"><?= get_btn_icon('plus') ?>Zapsat servis</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="score-card score-card--quiet">
                    <div class="score-card__label"><?= get_dot('muted') ?>Bez plánu</div>
                    <div class="score-card__note">Zapněte plán a nastavte první servis — termíny se pak hlídají a připomínají.</div>
                </div>
            <?php endif; ?>

            <div class="card card--small-shadow">
                <div class="summary-list">
                    <div class="summary-list__row"><span class="summary-list__label">Druh</span><span class="summary-list__value"><?= $this->e($summary['kind']) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Opakování</span><span class="summary-list__value"><?= $this->e($summary['frequency']) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Servisů letos</span><span class="summary-list__value"><?= $this->e($summary['year']) ?></span></div>
                </div>
            </div>
            <div class="card card--note">Zapsaný servis se objeví v nejbližším klientském reportu — klient uvidí datum, druh a co jste udělali.</div>
        </aside>
    </div>

    <section class="card">
        <?php render_card_head('Historie servisů', $logsNote,
            '<a class="btn btn--primary btn--sm" href="' . get_url($base . '/servis/zapsat') . '">' . get_btn_icon('plus') . 'Zapsat provedený servis</a>') ?>

        <?php if ($logs === []): ?>
            <?php render_empty('Zatím žádný servis', 'První zapsaný servis se objeví tady i v klientském reportu.', 'clock') ?>
        <?php else: ?>
            <div class="table table--service">
                <div class="table__head"><div>Datum</div><div>Druh</div><div>Co jsme udělali</div><div class="table__cell table__cell--right">Čas</div><div>Stav</div><div class="table__cell table__cell--right">Akce</div></div>
                <?php foreach ($logs as $log): ?>
                    <div class="table__row">
                        <div class="table__cell table__cell--mono"><?= $this->e($log['date']) ?></div>
                        <div class="table__cell row" style="flex-wrap:nowrap;gap:9px"><?= get_icon($log['icon'], 'icon--sm ' . ($log['done'] ? 'icon--subtle' : 'icon--warning')) ?><span class="u-truncate"><?= $this->e($log['kindLabel']) ?></span></div>
                        <div class="table__cell text-secondary" style="text-wrap:pretty"><?= $this->e($log['text']) ?><?php if ((string) $log['user_name'] !== ''): ?><span class="text-caption"> · <?= $this->e((string) $log['user_name']) ?></span><?php endif; ?><?php if ($log['progress'] !== ''): ?><span class="text-caption"> · <?= $this->e($log['progress']) ?></span><?php endif; ?></div>
                        <div class="table__cell table__cell--mono table__cell--right text-subtle"><?= $this->e($log['time']) ?></div>
                        <div class="table__cell"><?= $log['done'] ? get_status('ok', 'Hotovo') : get_status('warning', 'Přeskočeno') ?></div>
                        <div class="table__cell table__cell--right">
                            <form class="row-actions" method="post" action="<?= get_url($base . '/servis/' . (int) $log['id'] . '/smazat') ?>">
                                <?php render_csrf($csrfToken) ?>
                                <a class="btn btn--ghost btn--icon" href="<?= $log['editUrl'] ?>" title="Upravit záznam" aria-label="Upravit záznam z <?= $this->e($log['date']) ?>"><?= get_icon('edit', 'icon--sm') ?></a>
                                <button type="submit" class="btn btn--ghost btn--icon" title="Smazat záznam" aria-label="Smazat záznam z <?= $this->e($log['date']) ?>"><?= get_icon('trash', 'icon--sm') ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
