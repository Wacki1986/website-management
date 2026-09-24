<?php
/**
 * Nastavení → Šablona reportu: náhled vzorového reportu, texty šablony se
 * upravují kliknutím přímo v něm (`report-template.js`). Návrh má jen
 * tlačítko „Upravit šablonu" na stránce Reporty.
 *
 * Upravitelné texty nesou `data-tpl="klíč"` (renderer s volbou `editable`,
 * předměty tady). Skript drží rozpracované texty ve skrytých polích
 * formuláře `template-form`; ukládá se až tlačítkem v liště.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var string              $emailBody    HTML vzorového reportu z ReportRenderer (už escapované, s `data-tpl`)
 * @var array<int, array{key: string, label: string, text: string}> $subjects předměty s dosazenými značkami
 * @var array<string, string> $texts      klíč => text šablony (se značkami)
 * @var int                 $changedCount kolik textů se liší od výchozích (+1 za změněné pořadí sekcí)
 * @var string              $sectionOrder pořadí posouvatelných sekcí, klíče oddělené čárkou
 * @var string              $editorConfig JSON pro skript (pole, texty, hodnoty značek)
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <section class="card" data-template-editor data-config="<?= $this->e($editorConfig) ?>">
        <div class="card__header template-bar">
            <div>
                <h2 class="card__title">Šablona klientského reportu</h2>
                <div class="card__note">Klikněte na text s tužkou a přepište ho, sekce posunete šipkami po najetí myší. Náhled je na vzorovém reportu se všemi sekcemi; texty platí pro všechny weby.</div>
            </div>
            <div class="row u-ml-auto" style="flex-wrap:nowrap">
                <span class="text-subtle template-bar__status" data-template-status aria-live="polite">Bez změn</span>
                <button type="button" class="btn btn--ghost btn--sm" data-template-discard hidden>Zahodit změny</button>
                <?php if ($changedCount > 0): ?>
                    <a href="<?= get_url('nastaveni/reporty') ?>" class="btn btn--ghost btn--sm" data-confirm="template-reset" data-template-reset>Vrátit výchozí texty</a>
                <?php endif; ?>
                <button type="submit" form="template-form" class="btn btn--primary btn--sm" data-template-save disabled><?= get_btn_icon('check') ?>Uložit šablonu</button>
            </div>
        </div>

        <form id="template-form" method="post" action="<?= get_url('nastaveni/reporty') ?>">
            <?php render_csrf($csrfToken) ?>
            <?php foreach ($texts as $key => $text): ?>
                <input type="hidden" name="<?= $this->e($key) ?>" value="<?= $this->e($text) ?>" data-template-input="<?= $this->e($key) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="section_order" value="<?= $this->e($sectionOrder) ?>" data-template-order>
        </form>

        <div class="template-subjects">
            <?php foreach ($subjects as $subject): ?>
                <div class="template-subjects__row">
                    <span class="template-subjects__label"><?= $this->e($subject['label']) ?></span>
                    <span class="template-subjects__text"><span data-tpl="<?= $this->e($subject['key']) ?>"><?= $this->e($subject['text']) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="template-preview">
            <div class="email-paper__sheet" data-template-sheet><?= $emailBody ?></div>
        </div>
    </section>

    <dialog class="modal" id="template-reset" aria-labelledby="template-reset-title">
        <form class="modal__body" method="post" action="<?= get_url('nastaveni/reporty') ?>">
            <?php render_csrf($csrfToken) ?>
            <input type="hidden" name="reset" value="1">
            <div>
                <div class="card__title modal__title" id="template-reset-title">Vrátit výchozí texty?</div>
                <div class="card__note">Všechny upravené texty šablony se nahradí výchozím zněním. Platí pro reporty, které ještě neodešly.</div>
            </div>
            <div class="modal__actions">
                <button type="button" class="btn btn--ghost" data-dialog-close>Zrušit</button>
                <button type="submit" class="btn btn--primary">Vrátit výchozí</button>
            </div>
        </form>
    </dialog>
</div>
