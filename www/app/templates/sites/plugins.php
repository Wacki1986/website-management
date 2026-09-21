<?php
/**
 * Detail webu — Pluginy (návrh `detail-webu-pluginy.html`).
 *
 * v1 bez vzdálených akcí: „Aktualizovat vybrané" a akce v řádcích jsou
 * neaktivní s vysvětlením (rozhodnutí z plánu). Výběr řádků doplňuje
 * `selection.js`; bez skriptu jsou zaškrtávátka jen značky.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array<int, array<string, mixed>> $rows
 * @var string              $q
 * @var array{total: int, active: int, inactive: int, updates: int, securityUpdates: int, latestUpdate: ?array{at: string, name: string}} $metrics
 * @var bool                $hasSnapshot
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Pluginy']);
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="metric-grid">
        <div class="metric">
            <div class="metric__label">Pluginů celkem</div>
            <div class="metric__value"><?= $hasSnapshot ? $metrics['total'] : '—' ?></div>
            <div class="metric__note"><?= $hasSnapshot ? $metrics['active'] . ' aktivních · ' . $metrics['inactive'] . ' neaktivní' : 'čeká na data z pluginu' ?></div>
        </div>
        <div class="metric">
            <div class="metric__label">Čekající aktualizace</div>
            <div class="metric__value<?= $metrics['updates'] > 0 ? ' metric__value--warning' : '' ?>"><?= $hasSnapshot ? $metrics['updates'] : '—' ?></div>
            <div class="metric__note"><?= $metrics['securityUpdates'] > 0 ? $metrics['securityUpdates'] . ' bezpečnostní' : ($hasSnapshot ? 'žádná bezpečnostní' : '') ?></div>
        </div>
        <div class="metric<?= $metrics['inactive'] > 0 ? ' metric--error' : '' ?>">
            <div class="metric__label">Neaktivní pluginy</div>
            <div class="metric__value<?= $metrics['inactive'] > 0 ? ' metric__value--error' : '' ?>"><?= $hasSnapshot ? $metrics['inactive'] : '—' ?></div>
            <div class="metric__note"><?= $metrics['inactive'] > 0 ? 'riziko – doporučeno smazat' : ($hasSnapshot ? 'vše aktivní' : '') ?></div>
        </div>
        <div class="metric">
            <div class="metric__label">Poslední aktualizace</div>
            <div class="metric__value"><?= $metrics['latestUpdate'] !== null ? $this->e(get_czech_date($metrics['latestUpdate']['at'])) : '—' ?></div>
            <div class="metric__note u-truncate"><?= $metrics['latestUpdate'] !== null ? $this->e($metrics['latestUpdate']['name']) : 'zatím žádná zaznamenaná' ?></div>
        </div>
    </div>

    <section class="card">
        <form class="card__header" method="get" action="<?= get_url('weby/' . (int) $site['id'] . '/pluginy') ?>">
            <h2 class="card__title">Pluginy</h2>
            <label class="search"><?= get_icon('search', 'icon--sm') ?><input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Hledat plugin…" class="search__input" aria-label="Hledat plugin"></label>
            <div class="u-ml-auto row">
                <noscript><button type="submit" class="btn btn--secondary btn--sm">Hledat</button></noscript>
                <button class="btn btn--primary btn--sm" type="button" disabled title="Vzdálené aktualizace připravujeme — zatím aktualizujte přímo ve wp-admin." data-selection-button>Aktualizovat vybrané</button>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <?php render_empty($hasSnapshot ? 'Žádný plugin neodpovídá hledání' : 'Zatím žádná data', $hasSnapshot ? 'Zkuste jiný název.' : 'Seznam pluginů dorazí s první kontrolou přes plugin MEDIAGRAFIK Monitor.', 'plugin') ?>
        <?php else: ?>
            <div class="table table--plugins" data-selection>
                <div class="table__head">
                    <div><button class="checkbox" type="button" role="checkbox" aria-checked="false" data-selection-all><span class="checkbox__mark"></span></button></div>
                    <div>Plugin</div><div>Stav</div><div>Verze</div><div>Dostupná</div><div class="table__cell table__cell--right">Akce</div>
                </div>
                <?php foreach ($rows as $plugin): ?>
                    <div class="table__row<?= $plugin['inactive'] ? ' table__row--error' : '' ?>">
                        <div class="table__cell"><button class="checkbox" type="button" role="checkbox" aria-checked="false" data-selection-item><span class="checkbox__mark"></span></button></div>
                        <div class="table__cell">
                            <div class="table__primary u-truncate"><?= $this->e((string) $plugin['name']) ?></div>
                            <div class="table__secondary u-truncate"><?= $this->e((string) $plugin['author']) ?></div>
                        </div>
                        <div class="table__cell"><?= $plugin['inactive'] ? get_status('error', 'Neaktivní · riziko') : get_status('ok', 'Aktivní') ?></div>
                        <div class="table__cell table__cell--mono u-hide-mobile"><?= $this->e((string) $plugin['version']) ?></div>
                        <div class="table__cell table__cell--mono u-hide-mobile<?= $plugin['new_version'] !== null ? ' text-warning' : '' ?>"><?= $plugin['new_version'] !== null ? $this->e((string) $plugin['new_version']) : '—' ?></div>
                        <div class="table__cell table__cell--right u-hide-mobile text-faint" style="font-size:var(--font-size-label)"><?= $plugin['action'] !== '' ? $this->e($plugin['action']) . ' (v2)' : '' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
