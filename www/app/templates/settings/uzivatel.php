<?php
/**
 * Úprava cizího účtu (Nastavení → Uživatelé → tužka v řádku): jméno,
 * přihlašovací jméno, e-mail a role. Heslo si každý nastavuje sám —
 * zapomenuté přes e-mail, proto na e-mailu záleží.
 *
 * @var \App\Core\View\View  $this
 * @var string               $title
 * @var string               $activeTab
 * @var array<string, mixed> $target     upravovaný účet
 * @var string               $targetName jak o účtu mluvit v nadpisu
 * @var array<string, mixed> $values     hodnoty do polí
 * @var array<string,string> $roles      kód => popisek
 * @var array<string,string> $errors
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form();
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--padded">
            <form method="post" action="<?= get_url('nastaveni/uzivatele/' . (int) $target['id'] . '/upravit') ?>" class="form">
                <?php render_csrf($csrfToken) ?>
                <div class="row" style="flex-wrap:nowrap;gap:14px">
                    <?= $this->partial('partials/avatar', ['user' => $target]) ?>
                    <div>
                        <div class="card__title">Upravit uživatele · <?= $this->e($targetName) ?></div>
                        <div class="card__note">E-mail slouží k obnově zapomenutého hesla a k pozvánce.</div>
                    </div>
                </div>

                <?php if ($errors !== []): ?>
                    <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
                <?php endif; ?>

                <div class="form__row">
                    <?= $form->text('name', 'Jméno', (string) ($values['name'] ?? ''), attributes: ['placeholder' => 'Petra Kolářová'], class: 'form__field--caps') ?>
                    <?= $form->text('username', 'Přihlašovací jméno', (string) ($values['username'] ?? ''), attributes: ['required' => true, 'class' => 'form__control--mono', 'spellcheck' => 'false'], class: 'form__field--caps') ?>
                    <?= $form->text('email', 'E-mail', (string) ($values['email'] ?? ''), type: 'email', class: 'form__field--caps') ?>
                    <?= $form->select('role', 'Role', $roles, (string) ($values['role'] ?? 'admin'), hint: 'Jen štítek v seznamu — všichni mohou vše.', class: 'form__field--caps') ?>
                </div>

                <div class="row">
                    <button type="submit" class="btn btn--primary">Uložit změny</button>
                    <a class="btn btn--ghost" href="<?= get_url('nastaveni/uzivatele') ?>">Zpět</a>
                </div>
            </form>
        </section>
    </div>
</div>
