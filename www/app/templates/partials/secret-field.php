<?php
/**
 * Tajemství s odloženou výměnou (SMTP heslo).
 *
 * Uložené tajemství se nikdy neukazuje celé — jen maska s posledními
 * 4 znaky. Nové se zadává přes `<details>` „Vyměnit…": bez JavaScriptu jde
 * rozkliknout stejně. Prázdné pole při uložení hodnotu nemění.
 *
 * @var \App\Core\View\View $this
 * @var string $name
 * @var string $label
 * @var string $hint             poslední 4 znaky uloženého tajemství, '' = není nastaveno
 * @var string $swapLabel        „Vyměnit heslo"
 * @var string $placeholderHint  nápověda, dokud nic není uložené
 */
?>
<div class="form__field">
    <span class="form__label form__label--caps"><?= $this->e($label) ?></span>
    <?php if ($hint !== ''): ?>
        <details class="secret">
            <summary class="form__control form__control--mono secret__summary">
                <span>••••••••••••<?= $this->e($hint) ?></span>
                <span class="secret__swap"><?= $this->e($swapLabel) ?></span>
            </summary>
            <input type="password" name="<?= $this->e($name) ?>" class="form__control form__control--mono"
                   autocomplete="off" placeholder="Nová hodnota" style="margin-top:8px">
        </details>
        <span class="form__hint">Po uložení se hodnota už nezobrazí — vidíte jen poslední 4 znaky.</span>
    <?php else: ?>
        <input type="password" name="<?= $this->e($name) ?>" class="form__control form__control--mono" autocomplete="off">
        <span class="form__hint"><?= $this->e($placeholderHint) ?></span>
    <?php endif; ?>
</div>
