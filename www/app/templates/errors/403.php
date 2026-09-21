<?php
/**
 * 403 — přístup odepřen (IP filtr).
 *
 * @var \App\Core\View\View $this
 * @var \App\Core\Kernel    $kernel
 * @var string              $message
 */
$this->extend(($currentUser ?? null) === null ? 'layout/auth' : 'layout/shell', ['title' => $title]);
?>
<div class="app__content">
    <section class="card">
        <div class="caps" style="text-align:center;padding-top:22px">403 · Přístup odepřen</div>
        <?php render_empty(
            'Přístup odepřen',
            $message !== '' ? $message : 'Přístup k aplikaci je omezen na povolené adresy.',
            'shield',
            '<a class="btn btn--primary" href="">Zkusit znovu</a>',
        ) ?>
    </section>
</div>
