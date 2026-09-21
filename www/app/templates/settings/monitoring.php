<?php
/**
 * Nastavení → Monitoring (návrh `nastaveni-monitoring.html`) + cron.
 *
 * Krokovací pole z návrhu jsou tu skutečná `<input type="number">`; tlačítka
 * +/− doplňuje `stepper.js`, bez něj se píše přímo do pole.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<string, int>  $values      klíče z MonitorSettings::DEFAULTS
 * @var string              $alertEmails
 * @var string|null         $cronToken   plný token (jen pro zkopírování do cPanelu)
 * @var string              $cronLine
 * @var array|null          $lastRun
 * @var int                 $siteCount
 * @var int                 $checksToday
 * @var string              $appVersion
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form();
$intervals = [5 => '5 minut', 15 => '15 minut', 30 => '30 minut', 60 => '1 hodina'];
$stepper = function (string $name, int $value, string $unit, int $min, int $max, int $step = 1) use ($form): string {
    return '<div class="stepper" data-stepper>'
        . '<button class="stepper__btn" type="button" data-stepper-down aria-label="Snížit">' . get_icon('minus', 'icon--sm') . '</button>'
        . '<input class="stepper__value" type="number" name="' . $form::idFor($name) . '" value="' . $value . '" min="' . $min . '" max="' . $max . '" step="' . $step . '" data-stepper-unit="' . $unit . '" aria-label="' . $name . '">'
        . '<button class="stepper__btn" type="button" data-stepper-up aria-label="Zvýšit">' . get_icon('plus', 'icon--sm') . '</button>'
        . '</div>';
};
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <div class="stack">
            <section class="card card--padded">
                <form method="post" action="<?= get_url('nastaveni/monitoring') ?>" class="form">
                    <?php render_csrf($csrfToken) ?>
                    <div>
                        <div class="card__title">Kontroly webů</div>
                        <div class="card__note">Výchozí hodnoty pro nově přidané weby. U jednotlivých webů jde frekvence přepsat.</div>
                    </div>

                    <div class="form__field">
                        <span class="form__label form__label--caps">Frekvence kontrol</span>
                        <div class="segmented">
                            <?php foreach ($intervals as $minutes => $label): ?>
                                <label class="segmented__item<?= $values['monitor_interval_min'] === $minutes ? ' segmented__item--active' : '' ?>"><input type="radio" name="monitor_interval_min" value="<?= $minutes ?>"<?= $values['monitor_interval_min'] === $minutes ? ' checked' : '' ?> class="visually-hidden"><?= $this->e($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form__hint">Každý web se zkontroluje každých <?= $values['monitor_interval_min'] ?> minut · <?= number_format($siteCount * intdiv(1440, max(1, $values['monitor_interval_min'])), 0, ',', ' ') ?> kontrol denně při <?= get_count($siteCount, 'webu', 'webech', 'webech') ?></div>
                    </div>

                    <div class="form__row">
                        <div class="form__field">
                            <span class="form__label form__label--caps">Timeout odpovědi</span>
                            <?= $stepper('monitor_timeout_s', $values['monitor_timeout_s'], 's', 3, 60) ?>
                            <div class="form__hint">po této době se kontrola počítá jako selhaná</div>
                        </div>
                        <div class="form__field">
                            <span class="form__label form__label--caps">Selhání před alertem</span>
                            <?= $stepper('monitor_fail_threshold', $values['monitor_fail_threshold'], '×', 1, 10) ?>
                            <div class="form__hint">chrání před falešným poplachem při krátkém výpadku</div>
                        </div>
                        <div class="form__field">
                            <span class="form__label form__label--caps">Historie kontrol</span>
                            <?= $stepper('monitor_history_months', $values['monitor_history_months'], 'měsíců', 1, 60) ?>
                            <div class="form__hint">starší záznamy se mažou, denní součty a reporty zůstávají</div>
                        </div>
                        <div class="form__field">
                            <span class="form__label form__label--caps">Data z pluginu</span>
                            <?= $stepper('monitor_snapshot_hours', $values['monitor_snapshot_hours'], 'h', 1, 48) ?>
                            <div class="form__hint">jak často se stahují verze a pluginy z webu</div>
                        </div>
                        <div class="form__field">
                            <span class="form__label form__label--caps">Ranní souhrn v</span>
                            <?= $stepper('monitor_digest_hour', $values['monitor_digest_hour'], 'h', 0, 23) ?>
                            <div class="form__hint">souhrn aktualizací a denní úklid</div>
                        </div>
                    </div>

                    <?= $form->text('alert_emails', 'Alerty posílat na', $alertEmails, class: 'form__field--caps', measure: 'none',
                        attributes: ['placeholder' => 'studio@mediagrafik.cz, petra@mediagrafik.cz'],
                        hint: 'Více adres oddělte čárkou. Prázdné = jen upozornění na telefon.') ?>

                    <div class="row"><button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Uložit nastavení</button></div>
                </form>
            </section>

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Cron na hostingu</div>
                        <div class="card__note">Monitor běží jen, když ho něco volá. Do cPanelu → Cron Jobs vložte tento řádek (každých 5 minut; frekvence webů se řídí nastavením výše).</div>
                    </div>

                    <?php if ($cronToken === null): ?>
                        <?php render_notice($this, 'warning', 'Cron zatím nemá token', 'Vygenerujte ho tlačítkem — bez něj je adresa cronu zavřená.') ?>
                    <?php else: ?>
                        <div class="form__field">
                            <span class="form__label form__label--caps">Řádek pro cPanel</span>
                            <div class="row" style="flex-wrap:nowrap">
                                <div class="form__control form__control--mono u-break-anywhere" style="flex:1;font-size:var(--font-size-caption)"><?= $this->e($cronLine) ?></div>
                                <button class="btn btn--secondary" type="button" data-copy="<?= $this->e($cronLine) ?>">Zkopírovat</button>
                            </div>
                            <span class="form__hint">Bez cPanel API: tentýž běh spustí <code>php dev/tools/monitor-cron.php</code> ze systémového cronu.</span>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <form method="post" action="<?= get_url('nastaveni/monitoring/token') ?>">
                            <?php render_csrf($csrfToken) ?>
                            <button type="submit" class="btn btn--secondary btn--sm"><?= $cronToken === null ? 'Vygenerovat token' : 'Vyměnit token' ?></button>
                        </form>
                        <form method="post" action="<?= get_url('nastaveni/monitoring/spustit') ?>">
                            <?php render_csrf($csrfToken) ?>
                            <button type="submit" class="btn btn--primary btn--sm"><?= get_btn_icon('refresh') ?>Spustit průchod teď</button>
                        </form>
                    </div>

                    <?php if ($lastRun !== null): ?>
                        <div class="summary-list">
                            <div class="summary-list__row"><span class="summary-list__label">Poslední běh</span><span class="summary-list__value"><?= $this->e(get_when((string) ($lastRun['at'] ?? ''))) ?> · <?= $this->e((string) ($lastRun['seconds'] ?? '?')) ?> s</span></div>
                            <div class="summary-list__row"><span class="summary-list__label">Výsledek</span><span class="summary-list__value"><?= ($lastRun['ok'] ?? false) ? get_status('ok', 'v pořádku') : get_status('error', (string) ($lastRun['error'] ?? 'chyba')) ?></span></div>
                            <div class="summary-list__row"><span class="summary-list__label">Zkontrolováno</span><span class="summary-list__value"><?= (int) ($lastRun['checked'] ?? 0) ?> webů · <?= (int) ($lastRun['down'] ?? 0) ?> nedostupných · SSL <?= (int) ($lastRun['ssl'] ?? 0) ?> · data z pluginu <?= (int) ($lastRun['pulled'] ?? 0) ?></span></div>
                        </div>
                    <?php else: ?>
                        <?php render_notice($this, 'info', message: 'Cron zatím neběžel. Až poběží, uvidíte tady čas a výsledek posledního průchodu.') ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Jak často kontrolovat</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed);text-wrap:pretty">15 minut je rozumný kompromis mezi rychlostí zjištění a zátěží hostingu. U kritických webů (rezervace, e-shop) nastavte 5 minut přímo v detailu webu.</div>
            </div>
            <div class="card card--small-shadow">
                <div class="summary-list">
                    <div class="summary-list__row"><span class="summary-list__label">Verze aplikace</span><span class="summary-list__value"><?= $this->e($appVersion) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Monitor</span><span class="summary-list__value"><?= $lastRun !== null ? (($lastRun['ok'] ?? false) ? 'běží' : 'selhal') . ' · poslední cyklus ' . $this->e(substr((string) ($lastRun['at'] ?? ''), 11, 5)) : 'zatím neběžel' ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Weby</span><span class="summary-list__value"><?= $siteCount ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Kontrol dnes</span><span class="summary-list__value"><?= number_format($checksToday, 0, ',', ' ') ?></span></div>
                </div>
            </div>
        </aside>
    </div>
</div>
