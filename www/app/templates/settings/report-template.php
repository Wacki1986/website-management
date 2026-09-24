<?php
/**
 * Nastavení → Šablona reportu: pevné texty klientského e-mailu (návrh má
 * jen tlačítko „Upravit šablonu" na stránce Reporty; skládá se jako
 * Nastavení → Servis — formulář a vedle náhled).
 *
 * Prázdné pole = výchozí znění (ukazuje se jako placeholder), uložené jsou
 * jen přepsané texty. Náhled je na posledním reportu, po uložení.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<int, array{key: string, label: string, default: string, rows: int, max: int, hint?: string, value: string, changed: bool}> $fields
 * @var array<string, string> $placeholders značka => význam
 * @var int                 $changedCount kolik textů se liší od výchozích
 * @var string              $previewBody  HTML těla e-mailu (z ReportRenderer, už escapované), '' = zatím žádný report
 * @var string              $previewSubject
 * @var string              $previewNote
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <form method="post" action="<?= get_url('nastaveni/reporty') ?>" class="card card--padded form">
            <?php render_csrf($csrfToken) ?>
            <div>
                <div class="card__title">Texty klientského reportu</div>
                <div class="card__note">Prázdné pole = výchozí text (je vidět šedě v poli). Platí pro všechny weby a pro reporty, které ještě neodešly.</div>
            </div>

            <?php foreach ($fields as $field): ?>
                <div class="form__field">
                    <label class="form__label" for="tpl_<?= $this->e($field['key']) ?>"><?= $this->e($field['label']) ?><?= $field['changed'] ? ' <span class="text-caption text-warning">· upraveno</span>' : '' ?></label>
                    <?php if ($field['rows'] > 1): ?>
                        <textarea class="form__control" id="tpl_<?= $this->e($field['key']) ?>" name="<?= $this->e($field['key']) ?>" rows="<?= $field['rows'] ?>" maxlength="<?= $field['max'] ?>" placeholder="<?= $this->e($field['default']) ?>"><?= $this->e($field['value']) ?></textarea>
                    <?php else: ?>
                        <input class="form__control" type="text" id="tpl_<?= $this->e($field['key']) ?>" name="<?= $this->e($field['key']) ?>" value="<?= $this->e($field['value']) ?>" maxlength="<?= $field['max'] ?>" placeholder="<?= $this->e($field['default']) ?>">
                    <?php endif; ?>
                    <?php if (($field['hint'] ?? '') !== ''): ?>
                        <div class="form__hint"><?= $this->e($field['hint']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="row">
                <button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Uložit šablonu</button>
                <?php if ($changedCount > 0): ?>
                    <button type="submit" name="reset" value="1" class="btn btn--ghost" formnovalidate>Vrátit výchozí texty</button>
                <?php endif; ?>
            </div>
        </form>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Zástupné značky</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed)">Do textu je vložte i se složenými závorkami, v e-mailu se nahradí údaji reportu.</div>
                <div class="summary-list" style="margin-top:10px">
                    <?php foreach ($placeholders as $code => $meaning): ?>
                        <div class="summary-list__row"><span class="summary-list__label text-mono"><?= $this->e($code) ?></span><span class="summary-list__value"><?= $this->e($meaning) ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card card--note">Poznámku ke konkrétnímu reportu a zapnuté sekce dál nastavujete u reportu v náhledu. Tady jsou texty, které se opakují v každém.</div>
        </aside>
    </div>

    <section class="card">
        <?php render_card_head('Náhled', $previewNote) ?>
        <?php if ($previewBody !== ''): ?>
            <div style="padding:0 var(--layout-card-padding) var(--layout-card-padding)">
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-bottom:10px">Předmět: <b><?= $this->e($previewSubject) ?></b></div>
                <div class="email-paper">
                    <div class="email-paper__sheet"><?= $previewBody ?></div>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>
