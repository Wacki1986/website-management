<?php
/**
 * Nastavení → Alerty a prahy (návrh `nastaveni-alerty.html`).
 *
 * Každé pravidlo: popis, práh (číslo) a přepínač. Vypnuté pravidlo alert
 * nevygeneruje; existující otevřené se při další kontrole samy zavřou.
 * Pravidlo bez prahu (`valueKey` null) má místo čísla prázdné místo.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<int, array{key: string, valueKey: ?string, label: string, text: string, value: int, unit: string, min: int, max: int, on: bool, note: string}> $rules
 * @var array|null          $lastRun
 * @var string              $appVersion
 * @var int                 $checksToday
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <form method="post" action="<?= get_url('nastaveni/alerty') ?>" class="card card--padded">
            <?php render_csrf($csrfToken) ?>
            <div>
                <div class="card__title">Prahy alertů</div>
                <div class="card__note">Kdy má monitor upozornit. Vypnuté pravidlo alert nevygeneruje.</div>
            </div>

            <?php foreach ($rules as $rule): ?>
                <div class="threshold-row">
                    <div style="min-width:0">
                        <div class="threshold-row__title"><?= get_dot($rule['on'] ? 'warning' : 'muted') ?><?= $this->e($rule['label']) ?></div>
                        <div class="text-caption" style="margin-top:4px"><?= $this->e($rule['text']) ?><?= $rule['note'] !== '' ? ' ' . $this->e($rule['note']) : '' ?></div>
                    </div>
                    <?= $rule['valueKey'] !== null ? get_stepper($rule['key'] . '_max', $rule['value'], $rule['min'], $rule['max'], $rule['unit'], $rule['label'], class: 'stepper--sm' . ($rule['on'] ? '' : ' stepper--off')) : '<div></div>' ?>
                    <div class="row" style="justify-content:flex-end"><?= get_toggle($rule['key'] . '_on', $rule['on'], $rule['label']) ?></div>
                </div>
            <?php endforeach; ?>

            <div class="row" style="padding-top:14px"><button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Uložit prahy</button></div>
        </form>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Méně alertů, více pozornosti</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed);text-wrap:pretty">Každé pravidlo, které pravidelně vyvolá alert, na který nikdo nereaguje, snižuje důvěru ve zbytek. Raději práh zvedněte nebo pravidlo vypněte. Výpadek webu, prošlý certifikát a PHP bez podpory se hlásí vždy.</div>
            </div>
            <div class="card card--small-shadow">
                <div class="summary-list">
                    <div class="summary-list__row"><span class="summary-list__label">Verze aplikace</span><span class="summary-list__value"><?= $this->e($appVersion) ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Monitor</span><span class="summary-list__value"><?= $lastRun !== null ? (($lastRun['ok'] ?? false) ? 'běží' : 'selhal') . ' · poslední cyklus ' . $this->e(substr((string) ($lastRun['at'] ?? ''), 11, 5)) : 'zatím neběžel' ?></span></div>
                    <div class="summary-list__row"><span class="summary-list__label">Kontrol dnes</span><span class="summary-list__value"><?= number_format($checksToday, 0, ',', ' ') ?></span></div>
                </div>
            </div>
        </aside>
    </div>
</div>
