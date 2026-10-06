<?php
/**
 * Přidání a úprava domény nebo hostingu projektu. Návrh obrazovku nemá —
 * formulář ze dvou karet: služba (u koho, kdy se obnovuje) a fakturace
 * (kdo platí, ceny, do kdy je vyfakturováno).
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var array                $project
 * @var string               $kind      domain | hosting
 * @var array|null           $service   null = nová
 * @var array<string,string> $values
 * @var array<string,string> $errors
 * @var string               $action
 * @var array<int, string>   $periods    měsíce => popisek
 * @var array<string,string> $currencies kód => značka
 * @var string               $expiryNote co o expiraci zjistil registr ('' = nová / hosting)
 * @var array|null           $mailDns    rozpis poslední kontroly DNS pošty (jen uložená doména)
 * @var string               $dnsAction  adresa „Zkontrolovat DNS teď" ('' = nová služba)
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form($errors, array_key_first($errors));
$isDomain = $kind === 'domain';
$back = get_url('projekty/' . (int) $project['id']);
?>
<header class="page-header">
    <div class="page-header__breadcrumb"><a href="<?= get_url('projekty') ?>">Projekty</a><span>/</span><a href="<?= $back ?>"><?= $this->e((string) $project['name']) ?></a><span>/</span><span><?= $this->e($title) ?></span></div>
    <div class="page-header__top">
        <div>
            <h1 class="page-header__title"><?= $this->e($title) ?></h1>
            <div class="page-header__meta"><?= $isDomain ? 'Expiraci domény zjišťuje monitor v registru jednou týdně.' : 'Datum obnovy hostingu se zadává ručně — po zaplacení ho posune „Obnoveno".' ?></div>
        </div>
    </div>
</header>

<div class="app__content">
    <form method="post" action="<?= $action ?>" class="split split--settings">
        <div class="stack">
            <?php render_csrf($csrfToken) ?>

            <?php if ($errors !== []): ?>
                <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
            <?php endif; ?>

            <section class="card card--padded">
                <div class="form">
                    <div class="card__title"><?= $isDomain ? 'Doména' : 'Hosting' ?></div>
                    <div class="form__row">
                        <?php if ($isDomain): ?>
                            <?= $form->text('name', 'Doména', $values['name'], required: true, class: 'form__field--caps',
                                attributes: ['placeholder' => 'pekarnanovak.cz', 'class' => 'form__control--mono', 'autofocus' => $service === null]) ?>
                            <?= $form->text('provider', 'Registrátor', $values['provider'], class: 'form__field--caps', attributes: ['placeholder' => 'zjistí se z registru'],
                                hint: 'Doplní se samo z registru. Přepište jen, když nesedí — pak už ho registr nepřepíše.') ?>
                        <?php else: ?>
                            <?= $form->text('provider', 'Poskytovatel', $values['provider'], required: true, class: 'form__field--caps',
                                attributes: ['placeholder' => 'Wedos', 'autofocus' => $service === null]) ?>
                            <?= $form->text('plan', 'Tarif', $values['plan'], class: 'form__field--caps', attributes: ['placeholder' => 'NoLimit']) ?>
                            <?= $form->text('name', 'Označení', $values['name'], class: 'form__field--caps',
                                attributes: ['placeholder' => 'webhosting pekarnanovak.cz'], hint: 'Když má klient u poskytovatele víc hostingů.') ?>
                        <?php endif; ?>
                        <?= $form->text('renews_on', $isDomain ? 'Expirace' : 'Další obnova', $values['renews_on'], type: 'date', class: 'form__field--caps',
                            hint: $expiryNote !== '' ? $expiryNote : ($isDomain ? 'Prázdné = zjistí se z registru.' : 'Konec zaplaceného období.')) ?>
                    </div>
                </div>
            </section>

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Fakturace</div>
                        <div class="card__note">Co platíme my, se objeví v přehledu Obnovy a fakturace, když se blíží obnova.</div>
                    </div>
                    <div class="form__field">
                        <span class="form__label form__label--caps">Kdo platí</span>
                        <div class="segmented" style="max-width:460px">
                            <label class="segmented__item"><input type="radio" name="paid_by" value="us"<?= $values['paid_by'] === 'us' ? ' checked' : '' ?> class="visually-hidden">Platíme my a přefakturujeme</label>
                            <label class="segmented__item"><input type="radio" name="paid_by" value="client"<?= $values['paid_by'] === 'client' ? ' checked' : '' ?> class="visually-hidden">Platí si klient</label>
                        </div>
                    </div>
                    <div class="form__row">
                        <?= $form->text('sale_price', 'Cena pro klienta', $values['sale_price'], class: 'form__field--caps', attributes: ['inputmode' => 'decimal', 'placeholder' => '390']) ?>
                        <?= $form->text('cost_price', 'Nákupní cena', $values['cost_price'], class: 'form__field--caps', attributes: ['inputmode' => 'decimal', 'placeholder' => '250'], hint: 'Jen pro nás — klient ji nevidí.') ?>
                        <?= $form->select('currency', 'Měna', $currencies, $values['currency'], class: 'form__field--caps') ?>
                        <?= $form->select('period_months', 'Platí se', $periods, $values['period_months'], class: 'form__field--caps') ?>
                        <?= $form->text('invoiced_until', 'Vyfakturováno do', $values['invoiced_until'], type: 'date', class: 'form__field--caps',
                            hint: 'Vyplní tlačítko „Vyfakturováno" v přehledu; ručně jen při zakládání.') ?>
                    </div>
                    <?= $form->textarea('note', 'Poznámka', $values['note'], class: 'form__field--caps', attributes: ['rows' => '3', 'placeholder' => 'Číslo zákazníka u poskytovatele, na koho je doména vedená…']) ?>
                    <div class="row">
                        <button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?><?= $service === null ? 'Přidat' : 'Uložit' ?></button>
                        <a class="btn btn--ghost" href="<?= $back ?>">Zrušit</a>
                    </div>
                </div>
            </section>
        </div>

        <aside class="split__aside">
            <?php if ($mailDns !== null): ?>
                <div class="card card--small-shadow">
                    <div class="card__header">
                        <div>
                            <div class="card__title" style="font-size:var(--font-size-body-large)">DNS pošty</div>
                            <div class="card__note"><?= get_status($mailDns['summary']['tone'], $mailDns['summary']['label']) ?><?= $mailDns['checkedAt'] !== '' ? ' · ' . $this->e($mailDns['checkedAt']) : '' ?></div>
                        </div>
                    </div>
                    <?php if ($mailDns['rows'] !== []): ?>
                        <div class="summary-list">
                            <?php foreach ($mailDns['rows'] as $dnsRow): ?>
                                <div class="summary-list__row"><span class="summary-list__label"><?= $this->e($dnsRow['label']) ?></span><span class="summary-list__value u-mono mail-dns__value"><?= $this->e($dnsRow['value']) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($mailDns['issues'] as $issue): ?>
                        <div class="check-list__item" style="padding:10px 22px"><?= get_dot($issue['tone']) ?><div class="text-caption"><?= $this->e($issue['text']) ?></div></div>
                    <?php endforeach; ?>
                    <div class="card__footer">
                        <?php // Tlačítko hlavního formuláře s jinou adresou — vnořený formulář HTML nedovolí. ?>
                        <button type="submit" class="btn btn--secondary btn--sm btn--block" formaction="<?= $dnsAction ?>" formnovalidate><?= get_btn_icon('refresh') ?>Zkontrolovat DNS teď</button>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card card--note">
                <div style="font-weight:var(--font-weight-semibold)">Jak to funguje</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed)">
                    Před obnovou přijde jedno upozornění e-mailem (Nastavení → Alerty a prahy → Obnova domén a hostingu).
                    Co platíme my, se v přehledu ukáže „k vyfakturování", dokud nekliknete na Vyfakturováno.
                </div>
            </div>
        </aside>
    </form>
</div>
