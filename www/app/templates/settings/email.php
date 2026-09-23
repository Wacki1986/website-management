<?php
/**
 * Nastavení → Odchozí pošta (návrh `nastaveni-email.html`).
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var string               $activeTab
 * @var array<string,mixed>  $mail
 * @var array<int,string>    $mailProblems
 * @var array<string,string> $mailTransports
 * @var array<string,string> $mailSecurities
 * @var string               $mailLogoUrl
 * @var string               $lastTest       „Poslední test 8. 9. v 14:02 — odesláno za 2 s", nebo ''
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form();
$configured = $mailProblems === [] && (string) $mail['transport'] !== 'none';
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">

        <section class="card card--padded">
            <form method="post" action="<?= get_url('nastaveni/email') ?>" class="form">
                <?php render_csrf($csrfToken) ?>

                <div class="row" style="justify-content:space-between;align-items:flex-start">
                    <div>
                        <div class="card__title">Odchozí pošta (SMTP)</div>
                        <div class="card__note">Přes tento účet odcházejí alerty i klientské reporty.</div>
                    </div>
                    <?php if ($configured): ?>
                        <span class="pill pill--ok"><?= get_dot('ok') ?>Nastaveno</span>
                    <?php else: ?>
                        <span class="pill pill--muted"><?= get_dot('muted') ?>Nenastaveno</span>
                    <?php endif; ?>
                </div>

                <?php foreach ($mailProblems as $problem): ?>
                    <?php render_notice($this, 'error', message: $problem) ?>
                <?php endforeach; ?>

                <?= $form->select('transport', 'Způsob odesílání', $mailTransports, (string) $mail['transport'], class: 'form__field--caps',
                    hint: 'Ve vývoji se hodí „uložit do souboru" — e-maily končí ve storage/logs jako .eml.') ?>

                <div class="form__row">
                    <?= $form->text('host', 'Server', (string) $mail['host'], attributes: ['placeholder' => 'smtp.wedos.net', 'class' => 'form__control--mono'], class: 'form__field--caps') ?>
                    <?= $form->text('port', 'Port', (string) $mail['port'], type: 'number', attributes: ['class' => 'form__control--mono'], class: 'form__field--caps') ?>
                    <?= $form->text('username', 'Uživatel', (string) $mail['username'], attributes: ['class' => 'form__control--mono', 'autocomplete' => 'off'], class: 'form__field--caps') ?>
                    <?= $this->partial('partials/secret-field', [
                        'name' => 'password',
                        'label' => 'Heslo',
                        'hint' => (string) $mail['password_hint'],
                        'swapLabel' => 'Vyměnit heslo',
                        'placeholderHint' => 'Ukládá se šifrovaně.',
                    ]) ?>
                    <?= $form->text('from_name', 'Jméno odesílatele', (string) $mail['from_name'], attributes: ['placeholder' => 'MEDIAGRAFIK · správa webů'], class: 'form__field--caps') ?>
                    <?= $form->text('from_address', 'Adresa odesílatele', (string) $mail['from_address'], type: 'email', attributes: ['placeholder' => 'monitor@mediagrafik.cz', 'class' => 'form__control--mono'], class: 'form__field--caps') ?>
                </div>

                <div class="form__field">
                    <span class="form__label form__label--caps">Šifrování</span>
                    <div class="segmented" style="max-width:420px">
                        <?php foreach ($mailSecurities as $code => $label): ?>
                            <label class="segmented__item">
                                <input type="radio" name="security" value="<?= $this->e($code) ?>"<?= (string) $mail['security'] === $code ? ' checked' : '' ?> class="visually-hidden">
                                <?= $this->e(explode(' (', $label)[0]) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?= $form->text('mail_logo_url', 'Logo v e-mailech (URL)', $mailLogoUrl, type: 'url', class: 'form__field--caps',
                    hint: 'Absolutní adresa obrázku pro hlavičku e-mailů (výška 28 px). Prázdné = místo loga jméno odesílatele.',
                    attributes: ['placeholder' => 'https://mediagrafik.cz/logo.png']) ?>

                <div class="row"><button type="submit" class="btn btn--primary"><?= get_btn_icon('check') ?>Uložit nastavení</button></div>
            </form>

            <form method="post" action="<?= get_url('nastaveni/email/test') ?>" class="card__footer" style="padding:14px 0 0;margin-top:18px">
                <?php render_csrf($csrfToken) ?>
                <div class="row" style="flex-wrap:nowrap">
                    <input class="form__control form__control--inline" type="email" name="test_to" placeholder="jmeno@mediagrafik.cz" aria-label="Zkušební e-mail na" style="min-width:220px">
                    <button type="submit" class="btn btn--secondary btn--sm"><?= get_btn_icon('mail') ?>Odeslat testovací e-mail</button>
                </div>
                <?php if ($lastTest !== ''): ?>
                    <span class="text-subtle" style="font-size:var(--font-size-label)"><?= $this->e($lastTest) ?></span>
                <?php endif; ?>
            </form>
        </section>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Doručitelnost</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed);text-wrap:pretty">Používejte adresu na vlastní doméně s nastaveným SPF a DKIM. Bez toho klientům reporty padají do spamu — a vy o tom nevíte.</div>
            </div>
        </aside>
    </div>
</div>
