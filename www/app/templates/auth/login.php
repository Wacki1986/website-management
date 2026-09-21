<?php
/**
 * Přihlášení. Chybová hláška neprozrazuje, které pole neselo, a nerozlišuje
 * ani zablokování limitem. Do prvního pole patří jméno i e-mail.
 *
 * @var \App\Core\View\View  $this
 * @var \App\Core\Kernel     $kernel
 * @var string               $username
 * @var array<string,string> $errors
 * @var string               $csrfToken
 */
$this->extend('layout/auth', ['title' => $title]);

$form = $this->form();
?>
<div class="auth__head">
    <h1 class="auth__title">Přihlášení</h1>
    <p class="text-subtle">Interní nástroj studia pro správu klientských webů.</p>
</div>

<form class="card card--padded form" method="post" action="<?= get_url('prihlaseni') ?>">
    <?php render_csrf($csrfToken) ?>

    <?php if ($errors !== []): ?>
        <?php render_notice($this, 'error', message: (string) ($errors['password'] ?? $errors['_'] ?? reset($errors))) ?>
    <?php endif; ?>

    <?= $form->text(
        'username',
        'Přihlašovací jméno nebo e-mail',
        $username,
        attributes: ['required' => true, 'autocomplete' => 'username', 'autofocus' => true],
    ) ?>

    <?= $form->text(
        'password',
        'Heslo',
        type: 'password',
        attributes: ['required' => true, 'autocomplete' => 'current-password'],
    ) ?>

    <div class="auth__row">
        <?= $form->checkbox('remember', 'Zůstat přihlášen') ?>
        <a href="<?= get_url('zapomenute-heslo') ?>">Zapomněli jste heslo?</a>
    </div>

    <button type="submit" class="btn btn--primary btn--block btn--lg">Přihlásit se</button>
</form>
