<?php
/**
 * Hlavička podstránek projektu (trezor přístupů): drobeček zpět na projekt,
 * název, klient.
 *
 * @var \App\Core\View\View $this
 * @var array               $project  s `client_name`
 */
?>
<header class="page-header">
    <div class="page-header__breadcrumb">
        <a href="<?= get_url('projekty') ?>">Projekty</a><span>/</span><a href="<?= get_url('projekty/' . (int) $project['id']) ?>"><?= $this->e((string) $project['name']) ?></a><span>/</span><span>Přístupy</span>
    </div>
    <div class="page-header__top">
        <div>
            <h1 class="page-header__title">Přístupy · <?= $this->e((string) $project['name']) ?></h1>
            <div class="page-header__meta">
                <?php if ($project['client_id'] !== null): ?>
                    <a href="<?= get_url('klienti/' . (int) $project['client_id']) ?>"><?= $this->e((string) $project['client_name']) ?></a>
                <?php else: ?>
                    <span class="text-faint">bez klienta</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--secondary btn--sm" href="<?= get_url('projekty/' . (int) $project['id']) ?>">Zpět na projekt</a>
        </div>
    </div>
</header>
