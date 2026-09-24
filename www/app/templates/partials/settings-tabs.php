<?php
/**
 * Hlavička Nastavení aplikace se záložkami (návrh `nastaveni-*.html`).
 * Vlastní URL na záložku, ne JS přepínání panelů: funguje bez JavaScriptu
 * a dá se odkázat.
 *
 * @var \App\Core\View\View $this
 * @var string              $activeTab monitoring|alerty|servis|reporty|email|oznameni|uzivatele
 */
$tabs = [
    ['key' => 'monitoring', 'label' => 'Monitoring', 'url' => get_url('nastaveni/monitoring')],
    ['key' => 'alerty', 'label' => 'Alerty a prahy', 'url' => get_url('nastaveni/alerty')],
    ['key' => 'servis', 'label' => 'Servis', 'url' => get_url('nastaveni/servis')],
    ['key' => 'reporty', 'label' => 'Šablona reportu', 'url' => get_url('nastaveni/reporty')],
    ['key' => 'email', 'label' => 'Odchozí pošta', 'url' => get_url('nastaveni/email')],
    ['key' => 'oznameni', 'label' => 'Oznámení', 'url' => get_url('nastaveni/oznameni')],
    ['key' => 'uzivatele', 'label' => 'Uživatelé', 'url' => get_url('nastaveni/uzivatele')],
];
?>
<header class="page-header page-header--list">
    <div class="page-header__top">
        <div>
            <h1 class="page-header__title">Nastavení aplikace</h1>
            <div class="page-header__meta">Platí pro všechny weby, pokud u konkrétního webu nenastavíte jinak</div>
        </div>
    </div>
    <?= get_tabs($tabs, $activeTab) ?>
</header>
