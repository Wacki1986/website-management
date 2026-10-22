<?php
/**
 * Buňky Uptime, WP, PHP, Databáze a Aktualizace v řádku webu — společné
 * pro výpis Weby i tabulku „Vyžaduje řešení" na dashboardu. Data chystá
 * `App\Core\Sites\SiteCells::of()`.
 *
 * @var \App\Core\View\View $this
 * @var array{uptime: array{value: string, tone: string}, versions: array<int, array{value: string, tone: string, title: string}>, updates: array{count: int, title: string}} $cells
 */
?>
<div class="table__cell table__cell--mono table__cell--right<?= $cells['uptime']['tone'] !== '' ? ' text-' . $cells['uptime']['tone'] : '' ?>"><?= $this->e($cells['uptime']['value']) ?></div>
<?php foreach ($cells['versions'] as $version): ?>
    <div class="table__cell table__cell--mono u-truncate<?= $version['value'] === '' ? ' text-faint' : ($version['tone'] !== '' ? ' text-' . $version['tone'] : '') ?>"<?= $version['title'] !== '' ? ' title="' . $this->e($version['title']) . '"' : '' ?>><?= $version['value'] !== '' ? $this->e($version['value']) : '—' ?></div>
<?php endforeach; ?>
<div class="table__cell table__cell--right"><?= $cells['updates']['count'] > 0 ? '<span title="' . $this->e($cells['updates']['title']) . '">' . get_badge((string) $cells['updates']['count'], $cells['updates']['count'] >= 5 ? 'warning' : '') . '</span>' : '<span class="text-faint">–</span>' ?></div>
