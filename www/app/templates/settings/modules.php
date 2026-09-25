<?php
/**
 * Nastavení → Moduly: volitelná měření webů (návrh tuhle záložku nemá;
 * skládá se jako Servis — karta s formulářem a poznámka vedle).
 *
 * Karta na modul: globální vypínač, volba pro nové weby a kolik webů má
 * modul zapnutý. „Zapnout u všech webů" je samostatný formulář — tlačítko
 * ho volá atributem `form`, formuláře se nesmí vnořovat.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<int, array{key: string, label: string, title: string, text: string, plugin_since: string, on: bool, newSites: bool, siteCount: int, offerAll: bool}> $modules
 * @var int                 $totalSites
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <div class="stack">
            <?php foreach ($modules as $module): ?>
                <section class="card card--padded">
                    <form method="post" action="<?= get_url('nastaveni/moduly') ?>" class="form">
                        <?php render_csrf($csrfToken) ?>
                        <input type="hidden" name="module" value="<?= $this->e($module['key']) ?>">
                        <div>
                            <div class="card__title"><?= $this->e($module['title']) ?></div>
                            <div class="card__note"><?= $this->e($module['text']) ?></div>
                        </div>
                        <div class="summary-list">
                            <div class="summary-list__row" style="align-items:center">
                                <div>
                                    <div style="font-weight:var(--font-weight-semibold)">Modul zapnutý</div>
                                    <div class="text-caption">vypnutý modul se neukazuje nikde — ani u webů, v reportech a alertech</div>
                                </div>
                                <?= get_toggle('on', $module['on'], 'Modul ' . $module['label'] . ' zapnutý') ?>
                            </div>
                            <div class="summary-list__row" style="align-items:center">
                                <div>
                                    <div style="font-weight:var(--font-weight-semibold)">Zapnout u nových webů</div>
                                    <div class="text-caption">nově přidaný web dostane modul rovnou zapnutý</div>
                                </div>
                                <?= get_toggle('new_sites', $module['newSites'], 'Zapnout u nových webů') ?>
                            </div>
                            <div class="summary-list__row"><span class="summary-list__label">Zapnuto u webů</span><span class="summary-list__value"><?= $module['siteCount'] ?> z <?= $totalSites ?></span></div>
                            <div class="summary-list__row"><span class="summary-list__label">Plugin na webu</span><span class="summary-list__value">MEDIAGRAFIK Monitor <?= $this->e($module['plugin_since']) ?> a novější</span></div>
                        </div>
                        <div class="row">
                            <button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Uložit</button>
                            <?php if ($module['offerAll']): ?>
                                <button type="submit" class="btn btn--secondary" form="module-all-<?= $this->e($module['key']) ?>">Zapnout u všech webů</button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <form method="post" action="<?= get_url('nastaveni/moduly/' . $module['key'] . '/vsechny-weby') ?>" id="module-all-<?= $this->e($module['key']) ?>" hidden>
                        <?php render_csrf($csrfToken) ?>
                    </form>
                </section>
            <?php endforeach; ?>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Jen tam, kde to dává smysl</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed);text-wrap:pretty">Modul je měření navíc, které nepotřebuje každý web. Zapíná se tady pro celou aplikaci a potom u jednotlivých webů v Nastavení webu → Hlídání. Web s vypnutým modulem nic navíc nepočítá ani neposílá.</div>
            </div>
            <div class="card card--note">Data modulu přicházejí s daty z pluginu (podle nastavení Monitoring → Data z pluginu), hned po zapnutí je načte „Zkontrolovat teď" u webu.</div>
        </aside>
    </div>
</div>
