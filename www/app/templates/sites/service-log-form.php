<?php
/**
 * Zápis servisu — nový i úprava (návrh ho nemá; skládá se z karty plánu
 * servisu). Nový hotový servis posune další termín plánu, úprava ne.
 *
 * Checklist se vykreslí pro každý druh servisu; vidět je jen ten, jehož
 * druh je vybraný (`.service-checklist` + `:has` v `app/_extras.scss`),
 * takže přepnutí druhu funguje bez skriptu. Odešle se jen checklist
 * vybraného druhu — ostatní controller zahodí.
 *
 * Vlastní úkoly (`extra[druh][n][label|done]`) jde přepsat i odebrat:
 * se skriptem křížkem a „Přidat další úkol“ (`service-tasks.js`, klon
 * `<template>`), bez skriptu je na konci vždy jeden prázdný řádek
 * a úkol se odebere smazáním textu.
 *
 * @var \App\Core\View\View  $this
 * @var array                $site
 * @var array<string, array{label: string, note: string, icon: string}> $kinds
 * @var array{performed_on: string, kind: string, description: string, minutes: ?int, status: string} $values
 * @var array<string, array<int, array{label: string, done: bool, extra: bool, index: int}>> $checklists úkoly po druzích servisu (`extra` = vlastní úkol zápisu)
 * @var array<string,string> $errors
 * @var bool                 $isEdit      úprava existujícího zápisu
 * @var string               $formAction  adresa formuláře (už escapovaná)
 * @var string               $editedNote  kdo a kdy zápis vytvořil / upravil (jen u úpravy)
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — ' . ($isEdit ? 'Upravit servis' : 'Zapsat servis')]);

$form = $this->form($errors, array_key_first($errors));
$base = 'weby/' . (int) $site['id'];
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--padded">
            <form method="post" action="<?= $formAction ?>" class="form">
                <?php render_csrf($csrfToken) ?>
                <div>
                    <div class="card__title"><?= $isEdit ? 'Upravit záznam servisu' : 'Zapsat provedený servis' ?></div>
                    <div class="card__note"><?= $isEdit ? $this->e($editedNote) : 'Hotové úkoly a poznámku uvidí klient v reportu — pište lidsky, ne technicky.' ?></div>
                </div>

                <?php if ($errors !== []): ?>
                    <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
                <?php endif; ?>

                <div class="form__field">
                    <span class="form__label">Druh servisu</span>
                    <div class="choice">
                        <?php foreach ($kinds as $code => $kind): ?>
                            <label class="choice__item">
                                <input type="radio" name="kind" value="<?= $this->e($code) ?>"<?= $values['kind'] === $code ? ' checked' : '' ?> class="visually-hidden">
                                <div class="choice__title"><?= get_icon($kind['icon'], 'icon--sm icon--subtle') ?><?= $this->e($kind['label']) ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form__field">
                    <span class="form__label">Co se má udělat</span>
                    <?php foreach ($checklists as $code => $items): ?>
                        <div class="service-checklist service-checklist--<?= $this->e($code) ?>" data-service-tasks="<?= $this->e($code) ?>">
                            <div class="service-checklist__title"><?= $this->e($kinds[$code]['label']) ?></div>
                            <div class="task-list" data-task-list>
                                <?php foreach ($items as $item): ?>
                                    <?php if ($item['extra']): ?>
                                        <div class="task-list__item task-list__item--extra" data-task>
                                            <label class="checkbox"><input type="checkbox" name="extra[<?= $this->e($code) ?>][<?= $item['index'] ?>][done]" value="1"<?= $item['done'] ? ' checked' : '' ?> class="visually-hidden" aria-label="Hotovo"><span class="checkbox__mark"></span></label>
                                            <input type="text" class="task-list__input" name="extra[<?= $this->e($code) ?>][<?= $item['index'] ?>][label]" value="<?= $this->e($item['label']) ?>" maxlength="150" aria-label="Vlastní úkol">
                                            <button type="button" class="btn btn--ghost btn--icon task-list__remove" title="Odebrat úkol" aria-label="Odebrat úkol <?= $this->e($item['label']) ?>" data-task-remove hidden><?= get_icon('close', 'icon--sm') ?></button>
                                        </div>
                                    <?php else: ?>
                                        <label class="task-list__item">
                                            <span class="checkbox"><input type="checkbox" name="done[<?= $this->e($code) ?>][]" value="<?= $item['index'] ?>"<?= $item['done'] ? ' checked' : '' ?> class="visually-hidden"><span class="checkbox__mark"></span></span>
                                            <span class="task-list__label"><?= $this->e($item['label']) ?></span>
                                        </label>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <div class="task-list__item task-list__item--extra task-list__item--blank" data-task-blank>
                                    <label class="checkbox"><input type="checkbox" name="extra[<?= $this->e($code) ?>][new][done]" value="1" checked class="visually-hidden" aria-label="Hotovo"><span class="checkbox__mark"></span></label>
                                    <input type="text" class="task-list__input" name="extra[<?= $this->e($code) ?>][new][label]" value="" maxlength="150" placeholder="Další úkol, např. oprava formuláře poptávky" aria-label="Další úkol">
                                </div>
                            </div>
                            <button type="button" class="btn btn--ghost btn--sm task-list__add" data-task-add hidden><?= get_btn_icon('plus') ?>Přidat další úkol</button>
                        </div>
                    <?php endforeach; ?>
                    <template data-task-template>
                        <div class="task-list__item task-list__item--extra" data-task>
                            <label class="checkbox"><input type="checkbox" value="1" checked class="visually-hidden" aria-label="Hotovo" data-task-done><span class="checkbox__mark"></span></label>
                            <input type="text" class="task-list__input" value="" maxlength="150" placeholder="Další úkol" aria-label="Vlastní úkol" data-task-label>
                            <button type="button" class="btn btn--ghost btn--icon task-list__remove" title="Odebrat úkol" aria-label="Odebrat úkol" data-task-remove><?= get_icon('close', 'icon--sm') ?></button>
                        </div>
                    </template>
                    <div class="form__hint">Seznam úkolů pro každý druh upravíte v <a href="<?= get_url('nastaveni/servis') ?>">Nastavení → Servis</a>; vlastní úkol platí jen pro tento zápis. Co nestihnete teď, odškrtnete později úpravou zápisu.</div>
                </div>

                <div class="form__row">
                    <?= $form->text('performed_on', 'Datum', $values['performed_on'], type: 'date', required: true, class: 'form__field--caps', measure: 'none') ?>
                    <?= $form->text('minutes', 'Čas (minuty)', $values['minutes'] !== null ? (string) $values['minutes'] : '', type: 'number', class: 'form__field--caps', attributes: ['min' => '0', 'step' => '5', 'class' => 'form__control--mono'], measure: 'none', hint: 'Do reportu i do statistiky „servisů letos".') ?>
                </div>

                <?= $form->textarea('description', 'Poznámka k servisu (nepovinné)', $values['description'], class: 'form__field--caps',
                    attributes: ['rows' => '3', 'placeholder' => 'Např. doporučujeme obnovit fotografie v galerii — jsou z roku 2019.'],
                    hint: 'Klient v reportu uvidí hotové úkoly pod sebou a pod nimi tuhle poznámku. Technické detaily patří do historie změn, tu plní monitor sám.') ?>

                <div class="form__field">
                    <span class="form__label form__label--caps">Stav</span>
                    <div class="segmented" style="max-width:320px">
                        <label class="segmented__item"><input type="radio" name="status" value="done"<?= $values['status'] === 'done' ? ' checked' : '' ?> class="visually-hidden">Hotovo</label>
                        <label class="segmented__item"><input type="radio" name="status" value="skipped"<?= $values['status'] === 'skipped' ? ' checked' : '' ?> class="visually-hidden">Přeskočeno</label>
                    </div>
                    <div class="form__hint">Přeskočený servis se do reportu nepíše a termín neposouvá — jen vysvětluje mezeru v historii.</div>
                </div>

                <div class="row">
                    <button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?><?= $isEdit ? 'Uložit změny' : 'Zapsat servis' ?></button>
                    <a class="btn btn--ghost" href="<?= get_url($base . '/servis') ?>">Zrušit</a>
                </div>
            </form>
        </section>

        <aside class="split__aside">
            <div class="card card--note"><?= $isEdit
                ? 'Úprava termín plánu neposouvá — ten posunul už původní zápis. Změny se projeví v příštím reportu, který ještě neodešel.'
                : 'Hotový servis posune další termín plánu na nejbližší v rytmu. Zapsané minuty se sčítají do „Servisů letos" v přehledu.' ?></div>
        </aside>
    </div>
</div>
