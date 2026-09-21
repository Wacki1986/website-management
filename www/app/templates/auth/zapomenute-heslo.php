<?php
/**
 * Zapomenuté heslo — formulář na e-mail. Odpověď je vždy stejná, ať e-mail
 * v evidenci je nebo není — viz ForgottenPasswordController::send().
 *
 * @var \App\Core\View\View $this
 * @var \App\Core\Kernel    $kernel
 * @var string              $csrfToken
 */
$this->extend('layout/auth', ['title' => $title]);
?>
<div class="auth__head">
    <h1 class="auth__title">Zapomenuté heslo</h1>
    <p class="text-subtle">Zadejte e-mail k účtu — pokud ho máte v evidenci, pošleme na něj odkaz pro nastavení nového hesla.</p>
</div>

<form class="card card--padded form" method="post" action="<?= get_url('zapomenute-heslo') ?>">
    <?php render_csrf($csrfToken) ?>

    <?= $this->form()->text(
        'email',
        'E-mail',
        type: 'email',
        attributes: ['required' => true, 'autocomplete' => 'email', 'autofocus' => true],
    ) ?>

    <button type="submit" class="btn btn--primary btn--block btn--lg">Poslat odkaz</button>
</form>

<p class="auth__foot"><a href="<?= get_url('prihlaseni') ?>">Zpět na přihlášení</a></p>
