<?php
/**
 * Okno instalace pluginu z knihovny — jedno pro obě místa:
 *
 *  - „Přidat z knihovny" na záložce Pluginy webu: položky jsou pluginy,
 *    všechny míří na akci instalace toho webu (formulář má `action`),
 *  - „Nainstalovat na weby" v Knihovně pluginů: položky jsou weby, každá
 *    míří na akci instalace svého webu (formulář bez `action`).
 *
 * Otevírá ho `confirm-dialog.js`, instalaci řídí `plugin-install.js`:
 * zaškrtnuté položky pošle po jednom (`data-action`, `data-file`)
 * a zaškrtávátko u nich vymění za průběh — kolečko, pak fajfka nebo
 * křížek s důvodem. Nezaškrtnuté a nedostupné položky se během instalace
 * schovají. Pod položkami k instalaci jsou ty, které nejdou, i s důvodem.
 *
 * @var \App\Core\View\View  $this
 * @var array<string, mixed> $dialog id, title, note, targetsLabel, start (popisek tlačítka),
 *                                   action (adresa formuláře, null = každá položka má svou),
 *                                   targets — položky k instalaci {name, secondary, detail, file, action, value},
 *                                   skipped — nedostupné položky {name, secondary, detail, blocked}
 * @var string               $csrfToken
 */
?>
<dialog class="modal modal--wide" id="<?= $this->e($dialog['id']) ?>" aria-labelledby="<?= $this->e($dialog['id']) ?>-title">
    <form class="modal__body"<?= $dialog['action'] !== null ? ' method="post" action="' . $dialog['action'] . '"' : ' method="dialog"' ?> data-plugin-install>
        <?php render_csrf($csrfToken) ?>
        <div>
            <div class="card__title modal__title" id="<?= $this->e($dialog['id']) ?>-title"><?= $this->e($dialog['title']) ?></div>
            <div class="card__note"><?= $this->e($dialog['note']) ?></div>
        </div>
        <div class="plugin-progress plugin-progress--inline" data-install-progress aria-live="polite" hidden>
            <div data-install-progress-text>Připravuji instalaci…</div>
            <div class="plugin-progress__bar"><div class="plugin-progress__fill" data-install-progress-fill></div></div>
        </div>
        <?php if ($dialog['targets'] !== []): ?>
            <div>
                <div class="form__label"><?= $this->e($dialog['targetsLabel']) ?></div>
                <div class="progress-list">
                    <?php foreach ($dialog['targets'] as $item): ?>
                        <label class="progress-list__item progress-list__item--pick" data-install-item data-action="<?= $item['action'] ?>" data-file="<?= $this->e($item['file']) ?>" data-name="<?= $this->e($item['name']) ?>">
                            <span class="progress-list__icon" data-install-icon><span class="checkbox"><input type="checkbox" name="plugins[]" value="<?= $this->e($item['value']) ?>" class="visually-hidden" data-install-check><span class="checkbox__mark"></span></span></span>
                            <span class="progress-list__name"><?= $this->e($item['name']) ?><?php if ($item['secondary'] !== ''): ?> <span class="progress-list__secondary"><?= $this->e($item['secondary']) ?></span><?php endif; ?></span>
                            <span class="progress-list__version u-mono"><?= $this->e($item['detail']) ?></span>
                            <span class="progress-list__note" data-install-note hidden></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label class="form__check" data-install-option>
                <input type="checkbox" name="activate" value="1" checked data-install-activate>
                <span><b>Po instalaci aktivovat</b> — plugin se rovnou zapne. Když aktivace selže, plugin zůstane na webu vypnutý.</span>
            </label>
        <?php endif; ?>
        <?php if ($dialog['skipped'] !== []): ?>
            <div data-install-skipped>
                <div class="form__label">Nejde nainstalovat</div>
                <ul class="progress-list">
                    <?php foreach ($dialog['skipped'] as $item): ?>
                        <li class="progress-list__item progress-list__item--skipped">
                            <span class="progress-list__icon"><?= get_icon('minus', 'icon--sm') ?></span>
                            <span class="progress-list__name"><?= $this->e($item['name']) ?><?php if ($item['secondary'] !== ''): ?> <span class="progress-list__secondary"><?= $this->e($item['secondary']) ?></span><?php endif; ?></span>
                            <span class="progress-list__version u-mono"><?= $this->e($item['detail']) ?></span>
                            <span class="progress-list__note"><?= $this->e((string) $item['blocked']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-dialog-close data-install-close>Zrušit</button>
            <?php if ($dialog['targets'] !== []): ?>
                <button type="submit" class="btn btn--primary" data-install-start disabled><?= get_btn_icon('plus') ?><?= $this->e($dialog['start']) ?></button>
            <?php endif; ?>
        </div>
    </form>
</dialog>
