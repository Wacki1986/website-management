<?php
/**
 * Kontaktní osoby — záložka vedle seznamu klientů. Každá osoba je tu
 * jednou, i když jedná za víc firem; u firmy je vidět její role tam.
 * Hledání při psaní (`live-search.js`) vymění tabulku.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var array<int, array<string, mixed>> $contacts osoby s `name`, `url`, `clients`
 *      (name, url, note — „hlavní kontakt, jednatel"), `deleteUrl`, `deleteBlocked`, `deleteNote`
 * @var string              $q
 * @var int                 $total     počet osob bez hledání
 * @var string              $meta
 * @var string              $tabsHtml  záložky Firmy / Kontaktní osoby
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);
?>
<?php render_page_head('Klienti', $this->e($meta),
    '<a class="btn btn--primary" href="' . get_url('klienti/kontakty/pridat') . '">' . get_btn_icon('plus') . 'Přidat osobu</a>', $tabsHtml) ?>

<div class="app__content">
    <section class="card card--scroll-x">
        <form class="card__header card__header--filters" method="get" action="<?= get_url('klienti/kontakty') ?>" data-live-search>
            <label class="search" style="max-width:340px"><?= get_icon('search', 'icon--sm') ?><input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Hledat jméno, e-mail, telefon nebo firmu…" class="search__input" aria-label="Hledat"></label>
            <div class="text-subtle u-ml-auto" style="font-size:var(--font-size-label)">Řadit: <b>Příjmení</b></div>
        </form>

        <div class="table table--contacts" data-live-region="table">
            <div class="table__head"><div>Osoba</div><div>Klienti</div><div>Telefon</div><div>E-mail</div><div class="table__cell--right">Akce</div></div>

            <?php if ($contacts === []): ?>
                <?php render_empty($total === 0 ? 'Zatím žádná kontaktní osoba' : 'Žádná osoba neodpovídá hledání',
                    $total === 0 ? 'Osoby přibývají s klienty — nebo je přidejte tady a ke klientům připojte.' : 'Zkuste jiné hledání.',
                    'users', $total === 0 ? '<a class="btn btn--primary btn--sm" href="' . get_url('klienti/kontakty/pridat') . '">' . get_btn_icon('plus') . 'Přidat osobu</a>' : '') ?>
            <?php else: ?>
                <?php foreach ($contacts as $contact): ?>
                    <div class="table__row">
                        <div class="table__cell row" style="flex-wrap:nowrap;gap:12px">
                            <?= get_avatar($contact['name'], '', 'sm') ?>
                            <a class="table__primary u-truncate" href="<?= $contact['url'] ?>"><?= $this->e($contact['name']) ?></a>
                        </div>
                        <div class="table__cell contact-clients">
                            <?php if ($contact['clients'] === []): ?>
                                <span class="text-faint">u žádného klienta</span>
                            <?php endif; ?>
                            <?php foreach ($contact['clients'] as $client): ?>
                                <div class="u-truncate"><a href="<?= $client['url'] ?>"><?= $this->e($client['name']) ?></a><?php if ($client['note'] !== ''): ?><span class="text-subtle"> · <?= $this->e($client['note']) ?></span><?php endif; ?></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="table__cell table__cell--mono u-truncate"><?= $this->e((string) $contact['phone'] !== '' ? (string) $contact['phone'] : '—') ?></div>
                        <div class="table__cell u-truncate text-secondary" style="font-size:var(--font-size-label)"><?= $this->e((string) $contact['email'] !== '' ? (string) $contact['email'] : '—') ?></div>
                        <div class="table__cell table__cell--right">
                            <div class="row-actions">
                                <a class="btn btn--ghost btn--icon" href="<?= $contact['url'] ?>" title="Upravit osobu" aria-label="Upravit <?= $this->e($contact['name']) ?>"><?= get_icon('edit', 'icon--sm') ?></a>
                                <?php if ($contact['deleteBlocked'] !== ''): ?>
                                    <button type="button" class="btn btn--ghost btn--icon" disabled title="<?= $this->e($contact['deleteBlocked']) ?>" aria-label="<?= $this->e($contact['deleteBlocked']) ?>"><?= get_icon('trash', 'icon--sm') ?></button>
                                <?php else: ?>
                                    <a class="btn btn--ghost btn--icon" href="<?= $contact['url'] ?>" title="Smazat osobu" aria-label="Smazat <?= $this->e($contact['name']) ?>" data-confirm="contact-delete" data-confirm-action="<?= $contact['deleteUrl'] ?>" data-confirm-title="<?= $this->e('Smazat osobu ' . $contact['name'] . '?') ?>" data-confirm-note="<?= $this->e($contact['deleteNote']) ?>"><?= get_icon('trash', 'icon--sm') ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?= $this->partial('partials/contact-delete-dialog', ['csrfToken' => $csrfToken]) ?>
