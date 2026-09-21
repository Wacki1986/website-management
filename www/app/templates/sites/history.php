<?php
/**
 * Celá historie změn webu — stránkovaná („Zobrazit vše" z Přehledu).
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array<int, array<string, mixed>> $events
 * @var \App\Core\Views\Pagination $pagination
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Historie']);

$steps = $pagination->steps();
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <section class="card">
        <?php render_card_head('Historie změn', get_count($pagination->total, 'událost', 'události', 'událostí')) ?>

        <?php if ($events === []): ?>
            <?php render_empty('Zatím žádná událost', 'Historie se plní kontrolami, změnami verzí, servisy a reporty.', 'clock') ?>
        <?php else: ?>
            <div class="table table--history">
                <?php foreach ($events as $event): ?>
                    <div class="table__row">
                        <div class="table__cell"><?= get_dot((string) $event['tone']) ?></div>
                        <div class="table__cell">
                            <?= $this->e((string) $event['message']) ?>
                            <?php if ((string) $event['user_name'] !== ''): ?><span class="text-caption"> · <?= $this->e((string) $event['user_name']) ?></span><?php endif; ?>
                        </div>
                        <div class="table__cell caps u-hide-mobile"><?= $this->e($event['kindLabel']) ?></div>
                        <div class="table__cell table__cell--right text-subtle"><?= $this->e($event['when']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($pagination->pageCount() > 1): ?>
            <div class="card__footer card__footer--muted">
                <span>Zobrazeno <?= $pagination->from() ?>–<?= $pagination->to() ?> z <?= $pagination->total ?></span>
                <div class="row u-ml-auto">
                    <?php if ($steps['prev'] !== null): ?><a class="btn btn--secondary btn--sm" href="<?= $this->e($steps['prev']) ?>">Novější</a><?php endif; ?>
                    <?php if ($steps['next'] !== null): ?><a class="btn btn--secondary btn--sm" href="<?= $this->e($steps['next']) ?>">Starší</a><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>
