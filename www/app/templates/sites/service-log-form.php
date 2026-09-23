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
 * @var \App\Core\View\View  $this
 * @var array                $site
 * @var array<string, array{label: string, note: string, estimate: string, minutes: int, icon: string}> $kinds
 * @var array{performed_on: string, kind: string, description: string, minutes: ?int, status: string} $values
 * @var array<string, array<int, array{label: string, done: bool}>> $checklists úkoly po druzích servisu
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
                    <div class="card__note"><?= $isEdit ? $this->e($editedNote) : 'Co napíšete, uvidí klient v reportu — pište lidsky, ne technicky.' ?></div>
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
                                <div class="text-caption"><?= $this->e($kind['estimate']) ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form__field">
                    <span class="form__label">Co se má udělat</span>
                    <?php foreach ($checklists as $code => $items): ?>
                        <div class="service-checklist service-checklist--<?= $this->e($code) ?>">
                            <div class="service-checklist__title"><?= $this->e($kinds[$code]['label']) ?></div>
                            <?php if ($items === []): ?>
                                <div class="form__hint">Tenhle druh servisu nemá seznam úkolů.</div>
                            <?php endif; ?>
                            <?php foreach ($items as $i => $item): ?>
                                <label class="form__check"><input type="checkbox" name="done[<?= $this->e($code) ?>][]" value="<?= $i ?>"<?= $item['done'] ? ' checked' : '' ?>><span><?= $this->e($item['label']) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="form__hint">Seznam úkolů pro každý druh upravíte v <a href="<?= get_url('nastaveni/servis') ?>">Nastavení → Servis</a>. Co nestihnete teď, odškrtnete později úpravou zápisu.</div>
                </div>

                <div class="form__row">
                    <?= $form->text('performed_on', 'Datum', $values['performed_on'], type: 'date', required: true, class: 'form__field--caps', measure: 'none') ?>
                    <?= $form->text('minutes', 'Čas (minuty)', $values['minutes'] !== null ? (string) $values['minutes'] : '', type: 'number', class: 'form__field--caps', attributes: ['min' => '0', 'step' => '5', 'class' => 'form__control--mono'], measure: 'none', hint: 'Do reportu i do statistiky „servisů letos".') ?>
                </div>

                <?= $form->textarea('description', 'Co jsme udělali', $values['description'], required: true, class: 'form__field--caps',
                    attributes: ['rows' => '4', 'placeholder' => 'Aktualizace WordPressu a 11 pluginů, kontrola záloh a rezervačního formuláře'],
                    hint: 'Věta pro klienta. Technické detaily patří do historie změn, tu plní monitor sám.') ?>

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
