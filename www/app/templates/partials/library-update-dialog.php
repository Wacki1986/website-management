<?php
/**
 * Okno „Aktualizovat všude" z Knihovny pluginů (otevírá `confirm-dialog.js`,
 * průběh řídí `library-update.js`): weby k aktualizaci s prázdným kolečkem
 * vlevo, které se během běhu točí a změní na fajfku nebo křížek s důvodem;
 * pod nimi přeskočené weby i s důvodem. Každý web nese cestu pluginu,
 * jakou má on (`data-file`) — Monitor může ležet v jinak pojmenované složce.
 *
 * @var \App\Core\View\View  $this
 * @var array<string, mixed> $plugin řádek z `PluginLibraryController::row()` (dialogId, name, version, targets, skipped)
 * @var string               $csrfToken
 */
?>
<dialog class="modal modal--wide" id="<?= $this->e($plugin['dialogId']) ?>" aria-labelledby="<?= $this->e($plugin['dialogId']) ?>-title">
    <form class="modal__body" method="dialog" data-library-update>
        <?php render_csrf($csrfToken) ?>
        <div>
            <div class="card__title modal__title" id="<?= $this->e($plugin['dialogId']) ?>-title">Aktualizovat <?= $this->e($plugin['name'] . ' na ' . $plugin['version']) ?></div>
            <div class="card__note">Weby se aktualizují jeden po druhém. Nechte okno otevřené, dokud aktualizace neskončí — po zavření stránky se zbylé weby přeskočí (hotové zůstanou aktualizované).</div>
        </div>
        <div class="plugin-progress plugin-progress--inline" data-library-progress aria-live="polite" hidden>
            <div data-library-progress-text>Připravuji aktualizaci…</div>
            <div class="plugin-progress__bar"><div class="plugin-progress__fill" data-library-progress-fill></div></div>
        </div>
        <div>
            <div class="form__label"><?= $this->e(get_count(count($plugin['targets']), 'web', 'weby', 'webů')) ?> k aktualizaci</div>
            <ul class="progress-list">
                <?php foreach ($plugin['targets'] as $site): ?>
                    <li class="progress-list__item" data-library-target data-action="<?= $site['updateAction'] ?>" data-file="<?= $this->e($site['file']) ?>" data-name="<?= $this->e($site['name']) ?>">
                        <span class="progress-list__icon" data-library-icon title="Čeká"></span>
                        <span class="progress-list__name"><?= $this->e($site['name']) ?></span>
                        <span class="progress-list__version u-mono"><?= $this->e($site['version'] . ' → ' . $plugin['version']) ?></span>
                        <span class="progress-list__note" data-library-note hidden></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php if ($plugin['skipped'] !== []): ?>
            <div>
                <div class="form__label">Přeskočí se</div>
                <ul class="progress-list">
                    <?php foreach ($plugin['skipped'] as $site): ?>
                        <li class="progress-list__item progress-list__item--skipped">
                            <span class="progress-list__icon"><?= get_icon('minus', 'icon--sm') ?></span>
                            <span class="progress-list__name"><?= $this->e($site['name']) ?></span>
                            <span class="progress-list__version u-mono"><?= $this->e($site['version']) ?></span>
                            <span class="progress-list__note"><?= $this->e((string) $site['blocked']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-dialog-close data-library-close>Zrušit</button>
            <button type="submit" class="btn btn--primary" data-library-start><?= get_btn_icon('refresh') ?>Aktualizovat na <?= $this->e(get_count(count($plugin['targets']), 'webu', 'webech', 'webech')) ?></button>
        </div>
    </form>
</dialog>
