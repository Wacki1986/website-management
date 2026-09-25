<?php
/**
 * Knihovna pluginů — placené a vlastní pluginy mimo wordpress.org (návrh
 * stránku nemá; skládá se jako seznam klientů: hlavička, karta, tabulka).
 *
 * Nahrání ZIPu nahradí předchozí verzi. Weby s pluginem MEDIAGRAFIK
 * Monitor od verze `$since` si novou verzi nabídnou k aktualizaci samy.
 *
 * „Aktualizovat všude" (ikona v Akcích) otevře okno se seznamem webů;
 * po potvrzení `library-update.js` pošle weby po jednom na jejich akci
 * aktualizace (jako záložka Pluginy webu) a průběh ukazuje přímo v okně —
 * kolečko, pak fajfka nebo křížek u každého webu. Po zavření se stránka
 * načte znovu s čerstvými verzemi.
 *
 * Weby ve sloupci jsou pilulky: oranžová se starší verzí, šedá aktuální.
 * U víc webů se šedé schovají za „+ N aktuálních" (rozbalí skript).
 *
 * Náš MEDIAGRAFIK Monitor má nad knihovnou vlastní kartu se stejným
 * řádkem — nenahrává se sem, rozdává ho správa (`storage/plugin/`), ale
 * po vydání nové verze se odsud rozešle na všechny weby.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<string, mixed>|null        $monitor  řádek Monitoru (null = správa žádnou verzi nerozdává)
 * @var array<int, array<string, mixed>> $rows     pluginy knihovny — řádky z `PluginLibraryController::row()`
 *                                                 (klíče popisuje partial `library-row`)
 * @var array<int, array<string, mixed>> $dialogs  řádky s weby k aktualizaci → okno `library-update-dialog`
 * @var string              $since      od které verze MEDIAGRAFIK Monitoru weby knihovnu znají
 * @var string              $maxUpload  limit nahrávání na hostingu (upload_max_filesize)
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?php render_page_head('Knihovna pluginů', $this->e('Placené a vlastní pluginy, které nejsou na wordpress.org — od každého jen nejnovější verze')) ?>

<div class="app__content">
    <div class="split split--settings">
        <form method="post" action="<?= get_url('knihovna') ?>" enctype="multipart/form-data" class="card card--padded form" data-pending>
            <?php render_csrf($csrfToken) ?>
            <div>
                <div class="card__title">Nahrát plugin</div>
                <div class="card__note">ZIP tak, jak ho vydává autor (nebo jak jde nahrát ve wp-admin). Název a verzi si správa přečte z pluginu sama; novější verze nahradí starší.</div>
            </div>
            <div class="row" style="gap:10px;flex-wrap:wrap">
                <input class="form__control" type="file" name="plugin" accept=".zip,application/zip" required aria-label="ZIP s pluginem" style="flex:1;min-width:240px">
                <button type="submit" class="btn btn--primary" data-pending-label="Nahrávám…"><?= get_btn_icon('plus') ?>Nahrát do knihovny</button>
            </div>
            <div class="form__hint">Hosting dovolí nahrát nejvýš <?= $this->e($maxUpload) ?>.</div>
        </form>

        <aside class="split__aside">
            <div class="card card--note">Weby si novou verzi nabídnou k aktualizaci samy (ve správě i ve wp-admin), když mají plugin MEDIAGRAFIK Monitor <?= $this->e($since) ?> nebo novější. Ikonou „Aktualizovat všude" ji rozešlete na všechny weby najednou. ZIPy nejsou veřejně ke stažení — každý web dostane odkaz podepsaný jen pro sebe.</div>
        </aside>
    </div>

    <?php if ($monitor !== null): ?>
        <section class="card card--scroll-x">
            <?php render_card_head('MEDIAGRAFIK Monitor', 'náš plugin — novou verzi rozdává správa sama, do knihovny se nenahrává') ?>
            <div class="table table--library">
                <div class="table__head"><div>Plugin</div><div>Verze</div><div>Na webech</div><div>Vydáno</div><div class="table__cell table__cell--right">Akce</div></div>
                <?= $this->partial('partials/library-row', ['plugin' => $monitor, 'csrfToken' => $csrfToken]) ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card card--scroll-x">
        <?php render_card_head('Pluginy v knihovně', get_count(count($rows), 'plugin', 'pluginy', 'pluginů')) ?>

        <?php if ($rows === []): ?>
            <?php render_empty('Knihovna je prázdná', 'Nahrajte první plugin — třeba placený, ke kterému máte licenci, ale WordPress ho sám aktualizovat neumí.', 'plugin') ?>
        <?php else: ?>
            <div class="table table--library">
                <div class="table__head"><div>Plugin</div><div>Verze</div><div>Na webech</div><div>Nahráno</div><div class="table__cell table__cell--right">Akce</div></div>
                <?php foreach ($rows as $plugin): ?>
                    <?= $this->partial('partials/library-row', ['plugin' => $plugin, 'csrfToken' => $csrfToken]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php foreach ($dialogs as $plugin): ?>
        <?= $this->partial('partials/library-update-dialog', ['plugin' => $plugin, 'csrfToken' => $csrfToken]) ?>
    <?php endforeach; ?>
</div>
