<?php
/**
 * Nastavení → Servis: seznam úkolů ke každému druhu servisu (návrh tuhle
 * záložku nemá; skládá se jako Alerty a prahy — karta s formulářem
 * a poznámka vedle).
 *
 * Úkol na řádek. Zápis servisu si při uložení vezme kopii seznamu, takže
 * úprava tady mění jen nové zápisy.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var array<int, array{code: string, label: string, note: string, estimate: string, icon: string, text: string, count: int}> $kinds
 * @var int                 $maxItems
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <form method="post" action="<?= get_url('nastaveni/servis') ?>" class="card card--padded form">
            <?php render_csrf($csrfToken) ?>
            <div>
                <div class="card__title">Úkoly servisu</div>
                <div class="card__note">Co se má u kterého druhu servisu udělat. V zápisu servisu se z toho stane checklist k odškrtání.</div>
            </div>

            <?php foreach ($kinds as $kind): ?>
                <div class="form__field">
                    <label class="form__label" for="checklist_<?= $this->e($kind['code']) ?>"><?= get_icon($kind['icon'], 'icon--sm icon--subtle') ?> <?= $this->e($kind['label']) ?> <span class="text-caption">· <?= $this->e($kind['estimate']) ?></span></label>
                    <textarea class="form__control" id="checklist_<?= $this->e($kind['code']) ?>" name="checklist_<?= $this->e($kind['code']) ?>" rows="<?= max(4, $kind['count'] + 1) ?>"><?= $this->e($kind['text']) ?></textarea>
                    <div class="form__hint"><?= $this->e(mb_strtoupper(mb_substr($kind['note'], 0, 1)) . mb_substr($kind['note'], 1)) ?>. Úkol na řádek, nejvýš <?= $maxItems ?>.</div>
                </div>
            <?php endforeach; ?>

            <div class="row"><button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Uložit úkoly</button></div>
        </form>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Změna platí pro nové zápisy</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed);text-wrap:pretty">Každý zápis servisu si při uložení nechá svůj seznam i s odškrtnutím. Když úkol přidáte nebo smažete tady, dřívější zápisy zůstanou, jak byly — historie tak říká, co se tehdy opravdu dělalo.</div>
            </div>
            <div class="card card--note">Prázdný seznam = druh servisu bez checklistu. Textová část zápisu („Co jsme udělali") zůstává — tu vidí klient v reportu.</div>
        </aside>
    </div>
</div>
