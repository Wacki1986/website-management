<?php
/**
 * Detail webu — SEO → všechny slabě hodnocené stránky („Zobrazit všechny"
 * na záložce SEO). Plugin jich posílá nejvýš 200, od nejslabší.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array<int, array<string, mixed>> $pages  řádky `partials/seo-pages-table`
 * @var string|null         $checkedAt
 * @var string              $backUrl
 * @var string              $note
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — SEO stránky']);
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <section class="card">
        <?php render_card_head('Stránky se slabším hodnocením', $note . ($checkedAt !== null ? ' Načteno ' . get_when($checkedAt) . '.' : ''),
            '<a class="btn btn--secondary btn--sm" href="' . $backUrl . '">Zpět na SEO</a>') ?>

        <?php if ($pages === []): ?>
            <?php render_empty('Žádné slabé stránky', 'Všechny hodnocené stránky jsou dobré, nebo data zatím nepřišla.', 'check') ?>
        <?php else: ?>
            <?= $this->partial('partials/seo-pages-table', ['pages' => $pages]) ?>
        <?php endif; ?>
    </section>
</div>
