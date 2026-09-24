<?php
/**
 * Spárování telefonu — povinný krok pro každý účet (dvoufázové přihlášení).
 *
 * Kernel sem pošle každý přihlášený účet bez spárovaného telefonu, proto
 * layout přihlášení a ne aplikace: menu by jen vedlo zpátky sem.
 *
 * QR kód kreslí `qr.js` z adresy v `data-qr` (knihovna se načte jen na
 * této stránce). Pod ním je klíč k ručnímu opsání pro případ, že
 * fotoaparát nespolupracuje.
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var bool                 $available       je `app_key` (čím tajemství zašifrovat)
 * @var string               $otpauthUri      obsah QR kódu (`otpauth://…`)
 * @var string               $secretFormatted klíč po čtveřicích
 * @var string               $account         pod jakým jménem se účet v aplikaci ukáže
 * @var array<string,string> $errors
 * @var string               $csrfToken
 */
$this->extend('layout/auth', ['title' => $title]);

// Kurzor do pole jen po chybě — jinak by stránka odskočila pod QR kód.
$form = $this->form($errors, $errors !== [] ? 'code' : null);
?>
<div class="auth__head">
    <h1 class="auth__title">Spárujte telefon</h1>
    <p class="text-subtle">Přihlášení do správy chce kromě hesla i kód z aplikace v telefonu. Nastavuje se jednou, zabere minutu.</p>
</div>

<div class="card card--padded form">
    <?php if (!$available): ?>
        <?php render_notice($this, 'error', 'Chybí app_key v config/env.php', 'Bez něj se tajemství pro aplikaci nedá bezpečně uložit. Vygenerujte ho příkazem php dev/tools/generate-tokens.php a doplňte na server.') ?>
    <?php else: ?>
        <ol class="two-factor-steps">
            <li>
                <strong>Nainstalujte si aplikaci</strong> — Google Authenticator, Microsoft Authenticator,
                nebo použijte správce hesel, který kódy umí (1Password, Bitwarden).
            </li>
            <li>
                <strong>Naskenujte QR kód</strong> — v aplikaci „Přidat účet" → „Naskenovat QR kód".
                <div class="two-factor-qr" data-qr="<?= $this->e($otpauthUri) ?>" role="img" aria-label="QR kód pro aplikaci Authenticator"></div>
                <details class="two-factor-manual">
                    <summary>Nejde naskenovat? Opište klíč ručně</summary>
                    <div class="two-factor-manual__body">
                        <div class="text-subtle">Účet: <?= $this->e($account) ?> · typ: časový (TOTP)</div>
                        <div class="row" style="gap:8px;flex-wrap:nowrap">
                            <code class="two-factor-manual__secret"><?= $this->e($secretFormatted) ?></code>
                            <button class="btn btn--secondary btn--icon" type="button" data-copy="<?= $this->e(str_replace(' ', '', $secretFormatted)) ?>" title="Zkopírovat klíč" aria-label="Zkopírovat klíč"><?= get_icon('copy', 'icon--sm') ?></button>
                        </div>
                    </div>
                </details>
            </li>
            <li>
                <strong>Opište kód z aplikace</strong> — tím se ověří, že je telefon spárovaný správně.
                <form method="post" action="<?= get_url('nastaveni/dvoufazove') ?>" class="form" style="margin-top:12px">
                    <?php render_csrf($csrfToken) ?>
                    <?= $form->text('code', 'Šestimístný kód', attributes: [
                        'required' => true,
                        'autocomplete' => 'one-time-code',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9 ]{6,7}',
                        'maxlength' => '7',
                        'class' => 'form__control--mono',
                    ], class: 'form__field--caps two-factor-code') ?>
                    <button type="submit" class="btn btn--primary btn--block"><?= get_btn_icon('shield') ?>Spárovat a pokračovat</button>
                </form>
            </li>
        </ol>
    <?php endif; ?>

    <?php // Kdo telefon po ruce nemá, musí mít kam odejít — jinde ho aplikace nepustí. ?>
    <form method="post" action="<?= get_url('odhlaseni') ?>" class="auth__row">
        <?php render_csrf($csrfToken) ?>
        <span class="text-subtle">Telefon teď nemáte?</span>
        <button type="submit" class="btn btn--ghost btn--sm">Odhlásit se</button>
    </form>
</div>
