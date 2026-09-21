<?php
/**
 * Detail webu — Obsah (návrh `detail-webu-obsah.html`).
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array<int, array<string, mixed>> $rows
 * @var string|null         $fetchedAt
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Obsah']);
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <section class="card">
        <?php render_card_head('Typy obsahu', $fetchedAt !== null ? 'Naposledy načteno ' . get_when($fetchedAt) : 'Čeká na data z pluginu') ?>

        <?php if ($rows === []): ?>
            <?php render_empty('Zatím žádná data', 'Přehled obsahu dorazí s první kontrolou přes plugin MEDIAGRAFIK Monitor.', 'report') ?>
        <?php else: ?>
            <div class="table table--content">
                <div class="table__head"><div>Typ obsahu</div><div class="table__cell table__cell--right">Publikováno</div><div class="table__cell table__cell--right">Koncepty</div><div>Poslední příspěvek</div><div>Stáří</div></div>
                <?php foreach ($rows as $row): ?>
                    <div class="table__row">
                        <div class="table__cell">
                            <div class="table__primary"><?= $this->e($row['label']) ?></div>
                            <div class="table__secondary u-mono"><?= $this->e($row['slug']) ?></div>
                        </div>
                        <div class="table__cell table__cell--mono table__cell--right"><?= $row['published'] ?></div>
                        <div class="table__cell table__cell--mono table__cell--right text-subtle"><?= $row['drafts'] ?></div>
                        <div class="table__cell u-truncate text-secondary"><?= $this->e($row['latestTitle']) ?></div>
                        <div class="table__cell"><?= get_status($row['age']['tone'], $row['age']['label']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
