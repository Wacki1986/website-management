<?php
/**
 * Nové heslo — cíl odkazu z e-mailu (obnova i pozvánka nového účtu).
 *
 * @var \App\Core\View\View  $this
 * @var \App\Core\Kernel     $kernel
 * @var string               $token
 * @var bool                 $valid
 * @var array<string,string> $errors
 * @var string               $csrfToken
 */
$this->extend('layout/auth', ['title' => $title]);

$form = $this->form($errors);
?>
<div class="auth__head">
    <h1 class="auth__title">Nové heslo</h1>
    <?php if (!$valid): ?>
        <p class="text-subtle">Odkaz už neplatí — buď se použil, nebo mu vypršela platnost.</p>
    <?php else: ?>
        <p class="text-subtle">Zadejte nové heslo ke svému účtu.</p>
    <?php endif; ?>
</div>

<?php if (!$valid): ?>
    <a href="<?= get_url('zapomenute-heslo') ?>" class="btn btn--primary btn--block btn--lg">Vyžádat nový odkaz</a>
<?php else: ?>
    <form class="card card--padded form" method="post" action="<?= get_url('obnova-hesla/' . $token) ?>">
        <?php render_csrf($csrfToken) ?>

        <?php if ($errors !== []): ?>
            <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
        <?php endif; ?>

        <?= $form->text(
            'password',
            'Nové heslo',
            type: 'password',
            attributes: ['required' => true, 'autocomplete' => 'new-password', 'autofocus' => true],
            hint: 'Aspoň 10 znaků. Odhlásí všechny relace včetně trvalého přihlášení.',
        ) ?>

        <?= $form->text(
            'password_confirm',
            'Nové heslo znovu',
            type: 'password',
            attributes: ['required' => true, 'autocomplete' => 'new-password'],
        ) ?>

        <button type="submit" class="btn btn--primary btn--block btn--lg">Nastavit heslo</button>
    </form>
<?php endif; ?>
