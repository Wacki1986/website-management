<?php
/**
 * Nastavení → Základní nastavení — kdo správu provozuje (značka instance).
 * Návrh tuhle záložku nemá; skládá se z karet a formulářových prvků jako
 * Odchozí pošta.
 *
 * Loga a ikona se nahrávají tlačítkem (`<label>` se skrytým polem) a odešlou
 * se hned po výběru souboru (`file-upload.js`). Složka pluginu je po
 * napojení prvního webu jen ke čtení; odemkne ji zaškrtnutí potvrzení
 * (`unlock-field.js`) a server potvrzení kontroluje znovu.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<string, string> $values  name, web, email, phone, plugin — uložené hodnoty
 * @var string              $defaultPluginName  název pluginu, když pole zůstane prázdné
 * @var string              $pluginSlug        složka pluginu na webech
 * @var string              $pluginNamespace   REST namespace pluginu
 * @var bool                $slugLocked        na složku už odpovídá aspoň jeden web
 * @var string              $connectedLabel    „3 napojené weby"
 * @var array<int, array{slot: string, label: string, hint: string, uploaded: bool, hasPreview: bool, dark: bool}> $logos
 * @var bool                $hasIcon      nahraná vlastní ikona
 * @var int                 $iconMinSize  nejmenší strana ikony v px
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form();
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <div class="stack">
            <section class="card card--padded">
                <form method="post" action="<?= get_url('nastaveni/zakladni') ?>" class="form">
                    <?php render_csrf($csrfToken) ?>
                    <div>
                        <div class="card__title">Firma</div>
                        <div class="card__note">Kdo správu provozuje — objeví se v e-mailech, reportech, servisním protokolu a v pluginu na webech.</div>
                    </div>

                    <div class="form__row">
                        <?= $form->text('name', 'Název firmy', $values['name'], class: 'form__field--caps',
                            hint: 'Podpis reportů, popisek v aplikaci Authenticator, autor pluginu.') ?>
                        <?= $form->text('web', 'Web firmy', $values['web'], type: 'url', attributes: ['placeholder' => 'https://', 'class' => 'form__control--mono'], class: 'form__field--caps',
                            hint: 'Odkaz z pluginu a z hlavičky požadavků na weby.') ?>
                    </div>

                    <div class="form__row">
                        <?= $form->text('email', 'Kontaktní e-mail', $values['email'], type: 'email', class: 'form__field--caps',
                            hint: 'Patička reportů. Prázdné = adresa odesílatele z Odchozí pošty.') ?>
                        <?= $form->text('phone', 'Telefon', $values['phone'], type: 'tel', class: 'form__field--caps',
                            hint: 'Patička reportů. Nepovinné.') ?>
                    </div>

                    <div class="row"><button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Uložit</button></div>
                </form>
            </section>

            <section class="card card--padded">
                <form method="post" action="<?= get_url('nastaveni/zakladni/plugin') ?>" class="form">
                    <?php render_csrf($csrfToken) ?>
                    <div>
                        <div class="card__title">Plugin na webech</div>
                        <div class="card__note">Jméno, adresu správy a složku dostane plugin při stažení ZIPu.</div>
                    </div>

                    <?= $form->text('plugin', 'Název pluginu', $values['plugin'], attributes: ['placeholder' => $defaultPluginName, 'maxlength' => 60], class: 'form__field--caps',
                        hint: 'Jak se plugin jmenuje ve WordPressu a v textech správy. Prázdné = „' . $defaultPluginName . '“. Weby ho dostanou s příští aktualizací pluginu.') ?>

                    <?= $form->text('plugin_slug', 'Složka pluginu', $pluginSlug, attributes: ['maxlength' => 40, 'class' => 'form__control--mono', 'readonly' => $slugLocked, 'autocomplete' => 'off', 'spellcheck' => 'false'], class: 'form__field--caps',
                        hint: 'Složka ve WordPressu a adresa REST API: /wp-json/' . $pluginNamespace . '. Malá písmena, číslice a pomlčky.') ?>

                    <?php if ($slugLocked): ?>
                        <?php render_notice($this, 'warning', 'Složka je zamčená — odpovídá na ni ' . $connectedLabel,
                            'Po změně se všechny weby odpojí: WordPress složku pluginu přejmenovat neumí, na každý web je pak potřeba nahrát plugin znovu a vložit API klíč. Měňte jen ve výjimečném případě.') ?>
                        <?= $form->checkbox('plugin_slug_confirm', 'Rozumím, že se ' . $connectedLabel . ' odpojí, a chci složku změnit', attributes: ['data-unlocks' => 'plugin_slug']) ?>
                    <?php else: ?>
                        <div class="form__hint">Zatím na ni neodpovídá žádný web — teď je čas ji zvolit. Po napojení prvního webu se zamkne.</div>
                    <?php endif; ?>

                    <div class="row"><button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Uložit plugin</button></div>
                </form>
            </section>

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Loga</div>
                        <div class="card__note">SVG, PNG nebo JPG do 2 MB. Soubor vyberte tlačítkem, nebo ho na řádek loga přetáhněte — nahraje se hned; odebráním se vrátí výchozí.</div>
                    </div>

                    <?php foreach ($logos as $logo): ?>
                        <div class="brand-asset" data-dropzone>
                            <div class="brand-preview<?= $logo['dark'] ? ' brand-preview--dark' : '' ?>">
                                <?php if ($logo['hasPreview']): ?>
                                    <img src="<?= get_brand_logo($logo['slot']) ?>" alt="<?= $this->e($logo['label']) ?>">
                                <?php else: ?>
                                    bez loga
                                <?php endif; ?>
                            </div>
                            <div class="brand-asset__body">
                                <div>
                                    <div class="brand-asset__title"><?= $this->e($logo['label']) ?></div>
                                    <div class="form__hint"><?= $this->e($logo['hint']) ?></div>
                                </div>
                                <div class="brand-asset__actions">
                                    <form method="post" action="<?= get_url('nastaveni/zakladni/soubor/' . $logo['slot']) ?>" enctype="multipart/form-data">
                                        <?php render_csrf($csrfToken) ?>
                                        <label class="btn btn--secondary btn--sm">
                                            <?= get_btn_icon('upload') ?><?= $logo['uploaded'] ? 'Vyměnit' : 'Nahrát' ?>
                                            <input class="visually-hidden" type="file" name="file" accept="image/svg+xml,image/png,image/jpeg" data-autosubmit aria-label="<?= $this->e($logo['label']) ?> — vybrat soubor">
                                        </label>
                                    </form>
                                    <?php if ($logo['uploaded']): ?>
                                        <form method="post" action="<?= get_url('nastaveni/zakladni/soubor/' . $logo['slot'] . '/smazat') ?>">
                                            <?php render_csrf($csrfToken) ?>
                                            <button type="submit" class="btn btn--ghost btn--sm"><?= get_btn_icon('trash') ?>Odebrat</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Ikona aplikace</div>
                        <div class="card__note">Záložka prohlížeče, aplikace na ploše telefonu a upozornění.</div>
                    </div>
                    <div class="brand-asset" data-dropzone>
                        <div class="brand-preview brand-preview--icon">
                            <img src="<?= get_brand_icon('icon-192.png') ?>" alt="">
                        </div>
                        <div class="brand-asset__body">
                            <div class="form__hint">Čtvercové PNG aspoň <?= $iconMinSize ?> × <?= $iconMinSize ?> px, nejlépe znak na průhledném pozadí. Velikosti pro prohlížeč, iPhone i Android se z něj vyrobí samy. Soubor jde sem i přetáhnout.</div>
                            <div class="brand-asset__actions">
                                <form method="post" action="<?= get_url('nastaveni/zakladni/soubor/icon') ?>" enctype="multipart/form-data">
                                    <?php render_csrf($csrfToken) ?>
                                    <label class="btn btn--secondary btn--sm">
                                        <?= get_btn_icon('upload') ?><?= $hasIcon ? 'Vyměnit ikonu' : 'Nahrát ikonu' ?>
                                        <input class="visually-hidden" type="file" name="file" accept="image/png,image/jpeg" data-autosubmit aria-label="Ikona aplikace — vybrat soubor">
                                    </label>
                                </form>
                                <?php if ($hasIcon): ?>
                                    <form method="post" action="<?= get_url('nastaveni/zakladni/soubor/icon/smazat') ?>">
                                        <?php render_csrf($csrfToken) ?>
                                        <button type="submit" class="btn btn--ghost btn--sm"><?= get_btn_icon('trash') ?>Odebrat</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

    </div>
</div>
