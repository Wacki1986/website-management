<?php
/**
 * Zápis provedeného servisu — formulář (návrh ho nemá; skládá se z karty
 * plánu servisu). Po uložení se posune další termín plánu.
 *
 * @var \App\Core\View\View  $this
 * @var array                $site
 * @var array<string, array{label: string, note: string, estimate: string, minutes: int, icon: string}> $kinds
 * @var array{performed_on: string, kind: string, description: string, minutes: ?int, status: string} $values
 * @var array<string,string> $errors
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Zapsat servis']);

$form = $this->form($errors, array_key_first($errors));
$base = 'weby/' . (int) $site['id'];
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--padded">
            <form method="post" action="<?= get_url($base . '/servis/zapsat') ?>" class="form">
                <?php render_csrf($csrfToken) ?>
                <div>
                    <div class="card__title">Zapsat provedený servis</div>
                    <div class="card__note">Co napíšete, uvidí klient v reportu — pište lidsky, ne technicky.</div>
                </div>

                <?php if ($errors !== []): ?>
                    <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
                <?php endif; ?>

                <div class="form__field">
                    <span class="form__label">Druh servisu</span>
                    <div class="choice">
                        <?php foreach ($kinds as $code => $kind): ?>
                            <label class="choice__item<?= $values['kind'] === $code ? ' choice__item--active' : '' ?>">
                                <input type="radio" name="kind" value="<?= $this->e($code) ?>"<?= $values['kind'] === $code ? ' checked' : '' ?> class="visually-hidden">
                                <div class="choice__title"><?= get_icon($kind['icon'], 'icon--sm ' . ($values['kind'] === $code ? 'icon--brand' : 'icon--subtle')) ?><?= $this->e($kind['label']) ?></div>
                                <div class="text-caption"><?= $this->e($kind['estimate']) ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
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
                        <label class="segmented__item<?= $values['status'] === 'done' ? ' segmented__item--active' : '' ?>"><input type="radio" name="status" value="done"<?= $values['status'] === 'done' ? ' checked' : '' ?> class="visually-hidden">Hotovo</label>
                        <label class="segmented__item<?= $values['status'] === 'skipped' ? ' segmented__item--active' : '' ?>"><input type="radio" name="status" value="skipped"<?= $values['status'] === 'skipped' ? ' checked' : '' ?> class="visually-hidden">Přeskočeno</label>
                    </div>
                    <div class="form__hint">Přeskočený servis se do reportu nepíše a termín neposouvá — jen vysvětluje mezeru v historii.</div>
                </div>

                <div class="row">
                    <button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Zapsat servis</button>
                    <a class="btn btn--ghost" href="<?= get_url($base . '/servis') ?>">Zrušit</a>
                </div>
            </form>
        </section>

        <aside class="split__aside">
            <div class="card card--note">Hotový servis posune další termín plánu na nejbližší v rytmu. Zapsané minuty se sčítají do „Servisů letos" v přehledu.</div>
        </aside>
    </div>
</div>
