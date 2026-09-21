<?php
/**
 * Hláška v obsahu stránky — pás `.alert.alert--row` z návrhu (STAVY.md:
 * souhrnná chyba nad formulářem, úspěch `.alert--ok`).
 *
 * Typy: success | error | warning | info. Ikonu i barvu určuje typ.
 *
 * @var \App\Core\View\View $this
 * @var string              $type
 * @var string              $message
 * @var string              $title
 * @var array<int, string>  $list    výčet pod textem (typicky chyby formuláře)
 * @var array{url: string, label: string}|null $link odkaz vpravo
 */
$type = in_array($type ?? 'info', ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
$title = $title ?? '';
$message = $message ?? '';
$list = $list ?? [];
$link = $link ?? null;

if ($title === '' && $message === '' && $list === []) {
    return;
}

[$modifier, $icon, $iconClass] = match ($type) {
    'success' => ['alert--ok', 'check', 'icon--ok'],
    'warning' => ['alert--warning', 'alert', 'icon--warning'],
    'info' => ['alert--info', 'report', 'icon--brand'],
    default => ['', 'alert', 'icon--error'],
};
?>
<div class="alert alert--row <?= $modifier ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
    <?= get_icon($icon, 'icon--lg ' . $iconClass) ?>
    <div class="alert__content">
        <?php if ($title !== ''): ?>
            <div class="alert__row-title"><?= $this->e($title) ?></div>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <div class="<?= $title !== '' ? 'text-caption' : 'alert__row-title' ?>"><?= $this->e($message) ?></div>
        <?php endif; ?>

        <?php if ($list !== []): ?>
            <ul class="alert__list">
                <?php foreach ($list as $item): ?>
                    <li><?= $this->e($item) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php // Odkaz se předává strukturovaně, ne jako HTML v textu — hláška
          // tak nikdy nemůže vypustit neošetřenou značku. ?>
    <?php if ($link !== null): ?>
        <a class="btn btn--secondary btn--sm" href="<?= $this->e($link['url']) ?>"><?= $this->e($link['label']) ?></a>
    <?php endif; ?>
</div>
