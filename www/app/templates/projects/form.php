<?php
/**
 * Založení a úprava projektu. Návrh obrazovku nemá — formulář jako
 * u klienta, jen tři pole.
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var array|null           $project  null = nový projekt
 * @var array{name: string, client_id: ?int, note: string} $values
 * @var array<string,string> $errors
 * @var array<int, string>   $clients  id => název
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form($errors, array_key_first($errors));
$action = $project === null ? get_url('projekty/pridat') : get_url('projekty/' . (int) $project['id'] . '/upravit');
$back = $project === null ? get_url('projekty') : get_url('projekty/' . (int) $project['id']);
?>
<header class="page-header">
    <div class="page-header__breadcrumb"><a href="<?= get_url('projekty') ?>">Projekty</a><span>/</span><span><?= $project === null ? 'Nový projekt' : $this->e((string) $project['name']) ?></span></div>
    <div class="page-header__top">
        <div>
            <h1 class="page-header__title"><?= $this->e($title) ?></h1>
            <div class="page-header__meta">Projekt drží pohromadě web, doménu, hosting a e-maily jednoho zákazníka.</div>
        </div>
    </div>
</header>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--padded">
            <form method="post" action="<?= $action ?>" class="form">
                <?php render_csrf($csrfToken) ?>

                <?php if ($errors !== []): ?>
                    <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
                <?php endif; ?>

                <div class="form__row">
                    <?= $form->text('name', 'Název', $values['name'], required: true, class: 'form__field--caps',
                        attributes: ['placeholder' => 'pekarnanovak.cz', 'autofocus' => $project === null], hint: 'Obvykle hlavní doména.') ?>
                    <?= $form->select('client_id', 'Klient', $clients, $values['client_id'], placeholder: '— zatím bez klienta —', class: 'form__field--caps',
                        hint: $project !== null ? 'Změna klienta se promítne do všech webů projektu.' : '') ?>
                </div>
                <?= $form->textarea('note', 'Poznámka', $values['note'], class: 'form__field--caps', attributes: ['rows' => '4', 'placeholder' => 'Co k projektu patří, na co si dát pozor…']) ?>

                <div class="row">
                    <button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?><?= $project === null ? 'Založit projekt' : 'Uložit projekt' ?></button>
                    <a class="btn btn--ghost" href="<?= $back ?>">Zrušit</a>
                </div>
            </form>
        </section>

        <aside class="split__aside">
            <div class="card card--note">
                <div style="font-weight:var(--font-weight-semibold)">Co do projektu patří</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed)">
                    Weby v monitoringu — nový přidáte z detailu projektu, existující sem přesunete v jeho Nastavení.
                    Domény, hosting a e-mailové schránky přibudou v dalších verzích.
                </div>
            </div>
        </aside>
    </div>
</div>
