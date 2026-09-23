<?php
/**
 * Náhled reportu před odesláním (návrh `nahled-reportu.html`, `-withnote`,
 * `-sent`, `-allgood`, `-mobil`).
 *
 * Tělo e-mailu je hotové HTML z `ReportRenderer::body()` — vkládá se
 * neescapované, protože ho skládá aplikace, ne uživatel (poznámka
 * klienta je uvnitř escapovaná). Přepínač Počítač/Mobil je odkaz
 * (`?zobrazeni=mobil`), JS ho jen zrychlí.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<string, mixed> $report
 * @var array<string, mixed> $row
 * @var bool                $mobile
 * @var string              $emailBody
 * @var string              $subject
 * @var array<int, array{key: string, label: string, note: string, on: bool}> $sections
 * @var int                 $noteMax
 * @var array<string, string> $quickNotes
 * @var bool                $sentState
 * @var string              $meta
 * @var array<string, string> $summary
 * @var string              $testEmail
 * @var string              $siteUrl
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title . ' — ' . $report['site_name']]);

$base = 'reporty/' . (int) $report['id'];
$editable = !$sentState;
$noteLength = mb_strlen((string) $report['note']);
?>
<header class="page-header">
    <div class="page-header__breadcrumb"><a href="<?= get_url('reporty') ?>">Reporty</a><span>/</span><span><?= $this->e($report['site_name'] . ' · ' . mb_strtolower((string) $report['period_label'])) ?></span></div>
    <div class="page-header__top">
        <div>
            <h1 class="page-header__title"><?= $sentState ? 'Odeslaný report' : 'Náhled reportu před odesláním' ?></h1>
            <div class="page-header__meta"><?= $this->e($meta) ?></div>
        </div>
        <div class="page-header__actions">
            <form method="post" action="<?= get_url($base . '/test') ?>">
                <?php render_csrf($csrfToken) ?>
                <button class="btn btn--secondary" type="submit" title="<?= $testEmail !== '' ? 'Odešle na ' . $this->e($testEmail) : 'Váš účet nemá e-mail' ?>"><?= get_btn_icon('mail') ?>Poslat sobě na zkoušku</button>
            </form>
            <?php if ($sentState && $report['status'] === 'sent'): ?>
                <a class="btn btn--secondary" href="<?= get_url($base) ?>" target="_blank" rel="noopener"><?= get_btn_icon('check') ?>Odesláno</a>
            <?php else: ?>
                <form method="post" action="<?= get_url($base . '/odeslat') ?>">
                    <?php render_csrf($csrfToken) ?>
                    <button class="btn btn--primary" type="submit"<?= $report['recipients'] === [] ? ' disabled title="Web nemá adresáta reportu"' : '' ?>><?= get_btn_icon('check') ?><?= $sentState ? 'Odeslat znovu' : 'Odeslat klientovi' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="app__content">
    <div class="split split--report">
        <div class="stack" style="gap:12px">
            <div class="row" style="justify-content:space-between">
                <div class="segmented" style="flex:none" data-preview-device>
                    <a class="segmented__item<?= !$mobile ? ' segmented__item--active' : '' ?>" href="<?= get_url($base . '/nahled') ?>" data-device="desktop">Počítač</a>
                    <a class="segmented__item<?= $mobile ? ' segmented__item--active' : '' ?>" href="<?= get_url($base . '/nahled') ?>?zobrazeni=mobil" data-device="mobile">Mobil</a>
                </div>
                <div class="text-subtle" style="font-size:var(--font-size-label)">Předmět: <b><?= $this->e($subject) ?></b></div>
            </div>
            <div class="email-paper">
                <div class="email-paper__sheet<?= $mobile ? ' email-paper__sheet--mobile' : '' ?>" data-preview-sheet><?= $emailBody ?></div>
            </div>
        </div>

        <aside class="split__aside">
            <?php if ($sentState): ?>
                <div class="score-card <?= $report['status'] === 'sent' ? 'score-card--ok' : 'score-card--error' ?>">
                    <div class="score-card__label"><span class="score-card__dot"></span><?= $this->e($row['label']) ?></div>
                    <div class="score-card__note"><?= $report['status'] === 'sent'
                        ? 'Report odešel na ' . $this->e(implode(', ', $report['recipients'])) . '. Kopie je uložená v záložce Reporty u webu.'
                        : $this->e((string) $report['error']) . ' Opravte adresu u webu a odešlete znovu.' ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= get_url($base . '/poznamka') ?>" class="card card--padded">
                <?php render_csrf($csrfToken) ?>
                <div class="card__title">Poznámka pro klienta</div>
                <div class="card__note">Nepovinné. Objeví se v reportu jako modrý blok — sem patří, co jste udělali navíc.</div>
                <textarea class="form__control" name="note" rows="5" style="margin-top:12px" maxlength="<?= $noteMax ?>" placeholder="Tento měsíc jsme navíc…" data-note<?= $editable ? '' : ' disabled' ?>><?= $this->e((string) $report['note']) ?></textarea>
                <div class="row" style="justify-content:space-between;margin-top:8px">
                    <span class="text-caption"><span data-note-counter><?= $noteLength ?></span> / <?= $noteMax ?> znaků</span>
                    <?php if ($editable): ?>
                        <span class="row" style="gap:12px">
                            <?php if ($noteLength > 0): ?><button type="submit" name="note" value="" class="btn--link" style="font-size:var(--font-size-label);font-weight:var(--font-weight-semibold)">Vymazat</button><?php endif; ?>
                            <button type="submit" class="btn btn--secondary btn--sm">Uložit poznámku</button>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($editable): ?>
                    <div class="pill-group" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--color-border-subtle)" data-note-pills hidden>
                        <?php foreach ($quickNotes as $label => $text): ?>
                            <button class="pill" type="button" style="font-size:var(--font-size-caption)" data-note-add="<?= $this->e($text) ?>">+ <?= $this->e($label) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </form>

            <form method="post" action="<?= get_url($base . '/sekce') ?>" class="card card--small-shadow" style="padding:6px 20px" data-unsaved>
                <?php render_csrf($csrfToken) ?>
                <?php foreach ($sections as $section): ?>
                    <div class="summary-list__row" style="align-items:center">
                        <div><div style="font-size:var(--font-size-body);font-weight:var(--font-weight-medium)"><?= $this->e($section['label']) ?></div><div class="text-caption"><?= $this->e($section['note']) ?></div></div>
                        <?php if ($editable): ?>
                            <?= get_toggle('sections[]', $section['on'], $section['label'], $section['key']) ?>
                        <?php else: ?>
                            <?= get_status($section['on'] ? 'ok' : 'muted', $section['on'] ? 'ano' : 'ne') ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($editable): ?>
                    <div class="card__footer" style="padding:12px 0 8px"><button type="submit" class="btn btn--secondary btn--sm btn--block" data-unsaved-button data-unsaved-label="Uložit změny sekcí">Uložit sekce</button></div>
                <?php endif; ?>
            </form>

            <div class="card card--small-shadow">
                <div class="summary-list">
                    <?php foreach ($summary as $label => $value): ?>
                        <div class="summary-list__row"><span class="summary-list__label"><?= $this->e($label) ?></span><span class="summary-list__value u-truncate" title="<?= $this->e($value) ?>"><?= $this->e($value) ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row" style="justify-content:space-between">
                <a href="<?= $siteUrl ?>" style="font-size:var(--font-size-label)">Reporty webu <?= $this->e($report['site_name']) ?></a>
                <?php if ($editable): ?>
                    <form method="post" action="<?= get_url($base . '/smazat') ?>"><?php render_csrf($csrfToken) ?><button type="submit" class="btn--link text-subtle" style="font-size:var(--font-size-label)">Zahodit koncept</button></form>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
