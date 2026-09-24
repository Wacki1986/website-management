<?php
/**
 * Druhý krok přihlášení: kód z aplikace v telefonu, nebo záložní kód.
 * Jedno pole pro obojí — `TwoFactor::verify()` pozná druh podle tvaru.
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var array<string,string> $errors
 * @var string               $csrfToken
 */
$this->extend('layout/auth', ['title' => $title]);

$form = $this->form();
?>
<div class="auth__head">
    <h1 class="auth__title">Ověření</h1>
    <p class="text-subtle">Otevřete v telefonu aplikaci Authenticator a opište šestimístný kód u Správy webů.</p>
</div>

<form class="card card--padded form" method="post" action="<?= get_url('prihlaseni/overeni') ?>">
    <?php render_csrf($csrfToken) ?>

    <?php if ($errors !== []): ?>
        <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
    <?php endif; ?>

    <?= $form->text(
        'code',
        'Kód z aplikace',
        hint: 'Nemáte telefon po ruce? Zadejte jeden ze záložních kódů.',
        attributes: [
            'required' => true,
            'autocomplete' => 'one-time-code',
            'autofocus' => true,
            'maxlength' => '20',
            'spellcheck' => 'false',
            'class' => 'form__control--mono',
        ],
    ) ?>

    <button type="submit" class="btn btn--primary btn--block btn--lg">Ověřit a přihlásit</button>

    <div class="auth__row">
        <a href="<?= get_url('prihlaseni') ?>">Zpět na přihlášení</a>
    </div>
</form>
