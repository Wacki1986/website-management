<?php
/**
 * Přidání webu do monitoringu. Návrh vlastní obrazovku nemá — je to
 * jednoduchý formulář podle karty „Připojení" v Nastavení webu.
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var array<string, mixed> $values
 * @var array<string,string> $errors
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
            <div class="page-header__meta">Stačí adresa. API klíč pro plugin se vygeneruje sám a ukáže se po uložení.</div>
        </div>
    </div>
</header>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--padded">
            <form method="post" action="<?= get_url('weby/pridat') ?>" class="form">
                <?php render_csrf($csrfToken) ?>

                <?php if ($errors !== []): ?>
                    <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
                <?php endif; ?>

                <div class="form__row">
                    <?= $form->text('url', 'Adresa webu', (string) $values['url'], required: true, class: 'form__field--caps',
                        attributes: ['placeholder' => 'kavarnadobra.cz', 'class' => 'form__control--mono', 'autofocus' => true],
                        hint: 'Bez https:// se doplní samo. Web v podadresáři zadejte celý (firma.cz/blog).') ?>
                    <?= $form->text('name', 'Název', (string) $values['name'], class: 'form__field--caps',
                        attributes: ['placeholder' => 'Kavárna Dobrá'], hint: 'Prázdné = doména.') ?>
                    <?= $form->select('client_id', 'Klient', $clients, $values['client_id'], placeholder: '— zatím bez klienta —', class: 'form__field--caps') ?>
                    <?= $form->select('check_interval_min', 'Frekvence kontrol', $intervals, $values['check_interval_min'], class: 'form__field--caps') ?>
                </div>

                <div class="row">
                    <button type="submit" class="btn btn--primary"><?= get_btn_icon('plus') ?>Přidat web</button>
                    <a class="btn btn--ghost" href="<?= get_url('weby') ?>">Zrušit</a>
                </div>
            </form>
        </section>

        <aside class="split__aside">
            <div class="card card--note">
                <div style="font-weight:var(--font-weight-semibold)">Co bude dál</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed)">
                    1. Web dostane API klíč.<br>
                    2. Na webu nainstalujte plugin MEDIAGRAFIK Monitor a klíč do něj vložte.<br>
                    3. „Zkontrolovat teď" načte verze, pluginy, obsah a zabezpečení.
                </div>
            </div>
        </aside>
    </div>
</div>
