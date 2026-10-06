<?php
/**
 * Přidání webu do monitoringu. Návrh vlastní obrazovku nemá — je to
 * jednoduchý formulář podle karty „Připojení" v Nastavení webu.
 *
 * Pole a karty jen pro WordPress / jiný systém (`.platform-wordpress`,
 * `.platform-other`) přepíná CSS podle zaškrtnuté volby (`app/_extras.scss`).
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var array<string, mixed> $values
 * @var array<string,string> $errors
 * @var array<int, string>   $projects  id => „název (klient)"
 * @var array<int, string>   $clients   id => název
 * @var array<int, string>   $intervals minuty => popisek
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form($errors, array_key_first($errors));
?>
<header class="page-header">
    <div class="page-header__breadcrumb"><a href="<?= get_url('weby') ?>">Weby</a><span>/</span><span>Přidat web</span></div>
    <div class="page-header__top">
        <div>
            <h1 class="page-header__title">Přidat web do monitoringu</h1>
            <div class="page-header__meta">Stačí adresa. U WordPressu se API klíč pro plugin vygeneruje sám a ukáže se po uložení.</div>
        </div>
    </div>
</header>

<div class="app__content">
    <div class="split split--settings" data-platform-choice>
        <section class="card card--padded">
            <form method="post" action="<?= get_url('weby/pridat') ?>" class="form">
                <?php render_csrf($csrfToken) ?>

                <?php if ($errors !== []): ?>
                    <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
                <?php endif; ?>

                <div class="form__field">
                    <span class="form__label form__label--caps">Na čem web běží</span>
                    <div class="segmented" style="max-width:360px">
                        <label class="segmented__item"><input type="radio" name="platform" value="wordpress"<?= $values['platform'] === 'wordpress' ? ' checked' : '' ?> class="visually-hidden">WordPress</label>
                        <label class="segmented__item"><input type="radio" name="platform" value="other"<?= $values['platform'] === 'other' ? ' checked' : '' ?> class="visually-hidden">Jiný systém</label>
                    </div>
                    <div class="form__hint">Jiný systém (Shoptet, Webnode, statický web…) nemá plugin — hlídá se jen dostupnost, SSL certifikát a doména.</div>
                </div>

                <div class="form__row">
                    <?= $form->text('url', 'Adresa webu', (string) $values['url'], required: true, class: 'form__field--caps',
                        attributes: ['placeholder' => 'kavarnadobra.cz', 'class' => 'form__control--mono', 'autofocus' => true],
                        hint: 'Bez https:// se doplní samo. Web v podadresáři zadejte celý (firma.cz/blog).') ?>
                    <?= $form->text('name', 'Název', (string) $values['name'], class: 'form__field--caps',
                        attributes: ['placeholder' => 'Kavárna Dobrá'], hint: 'Prázdné = doména.') ?>
                    <?= $form->text('platform_name', 'Systém', (string) $values['platform_name'], class: 'form__field--caps platform-other',
                        attributes: ['placeholder' => 'Shoptet'], hint: 'Jen pro přehled v seznamu webů.') ?>
                    <?= $form->select('project_id', 'Projekt', $projects, $values['project_id'], placeholder: '— nový projekt z tohoto webu —', class: 'form__field--caps',
                        hint: 'Druhý web téhož zákazníka (třeba e-shop vedle firemního webu) patří do jeho projektu.') ?>
                    <?= $form->select('client_id', 'Klient nového projektu', $clients, $values['client_id'], placeholder: '— zatím bez klienta —', class: 'form__field--caps',
                        hint: 'Jen u nového projektu — existující projekt má klienta svého.') ?>
                    <?= $form->select('check_interval_min', 'Frekvence kontrol', $intervals, $values['check_interval_min'], class: 'form__field--caps') ?>
                </div>

                <div class="row">
                    <button type="submit" class="btn btn--primary"><?= get_btn_icon('plus') ?>Přidat web</button>
                    <a class="btn btn--ghost" href="<?= get_url('weby') ?>">Zrušit</a>
                </div>
            </form>
        </section>

        <aside class="split__aside">
            <div class="card card--note platform-wordpress">
                <div style="font-weight:var(--font-weight-semibold)">Co bude dál</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed)">
                    1. Web dostane API klíč.<br>
                    2. Na webu nainstalujte plugin MEDIAGRAFIK Monitor a klíč do něj vložte.<br>
                    3. „Zkontrolovat teď" načte verze, pluginy, obsah a zabezpečení.
                </div>
            </div>
            <div class="card card--note platform-other">
                <div style="font-weight:var(--font-weight-semibold)">Co bude dál</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed)">
                    Bez pluginu: monitor hlídá dostupnost, SSL certifikát a expiraci domény. Servis, reporty a přístupy fungují jako u WordPressu.
                </div>
            </div>
        </aside>
    </div>
</div>
