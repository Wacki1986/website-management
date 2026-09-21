<?php
/**
 * 404 — stránka neexistuje. Kreslí se přihlášenému i nepřihlášenému.
 *
 * @var \App\Core\View\View $this
 * @var \App\Core\Kernel    $kernel
 */
$this->extend(($currentUser ?? null) === null ? 'layout/auth' : 'layout/shell', ['title' => $title]);
?>
<div class="app__content">
    <section class="card">
        <div class="caps" style="text-align:center;padding-top:22px">404 · Stránka nenalezena</div>
        <?php render_empty(
            'Tady nic není',
            'Stránka neexistuje nebo byla přesunuta. Pokud jste sem přišli z odkazu uvnitř aplikace, dejte nám vědět — to by se stávat nemělo.',
            'search',
            '<a class="btn btn--primary" href="' . get_url('/') . '">Na dashboard</a>',
        ) ?>
    </section>
</div>
