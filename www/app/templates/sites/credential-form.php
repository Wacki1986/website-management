<?php
/**
 * Přidání a úprava přístupu v trezoru. Pole podle druhu
 * (`SiteCredentials::KINDS`) — FTP má server a protokol, hosting odkaz
 * do administrace, databáze jméno databáze.
 *
 * Uložené heslo se do formuláře nevrací nikdy: prázdné pole při úpravě
 * znamená „nechat, jak je".
 *
 * @var \App\Core\View\View  $this
 * @var array                $site
 * @var string               $kind
 * @var string               $kindLabel
 * @var array<string, bool>  $fields      která pole druh má
 * @var string               $urlLabel    popisek odkazu (administrace hostingu, phpMyAdmin…)
 * @var string               $hostLabel
 * @var string               $hostPlaceholder
 * @var bool                 $hostRequired server je povinný (FTP)
 * @var array<string, mixed> $values
 * @var array<string,string> $errors
 * @var bool                 $isEdit
 * @var bool                 $hasPassword u úpravy: heslo je uložené
 * @var array<string,string> $protocols   kód => popisek
 * @var string               $formAction  adresa formuláře (už escapovaná)
 * @var string               $backUrl
 * @var string               $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — ' . ($isEdit ? 'Upravit přístup' : 'Nový přístup')]);

$form = $this->form($errors, array_key_first($errors));
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--padded">
            <form method="post" action="<?= $formAction ?>" class="form" autocomplete="off">
                <?php render_csrf($csrfToken) ?>
                <div>
                    <div class="card__title"><?= $this->e(($isEdit ? 'Upravit přístup · ' : 'Nový přístup · ') . $kindLabel) ?></div>
                    <div class="card__note">Heslo a poznámka se ukládají šifrovaně.</div>
                </div>

                <?php if ($errors !== []): ?>
                    <?php render_notice($this, 'error', message: (string) reset($errors)) ?>
                <?php endif; ?>

                <div class="form__row">
                    <?php if (isset($fields['label'])): ?>
                        <?= $form->text('label', 'Název', $values['label'], required: true, attributes: ['placeholder' => 'Google Analytics', 'maxlength' => '150']) ?>
                    <?php endif; ?>

                    <?php if (isset($fields['protocol'])): ?>
                        <?= $form->select('protocol', 'Protokol', $protocols, $values['protocol'], hint: 'SFTP nebo FTPS, když je hosting umí — obyčejné FTP posílá heslo nešifrovaně.') ?>
                    <?php endif; ?>

                    <?php if (isset($fields['url'])): ?>
                        <?= $form->text('url', $urlLabel, $values['url'], type: 'url', attributes: ['placeholder' => 'https://', 'class' => 'form__control--mono'], span: 12) ?>
                    <?php endif; ?>

                    <?php if (isset($fields['host'])): ?>
                        <?= $form->text('host', $hostLabel, $values['host'], required: $hostRequired, attributes: ['placeholder' => $hostPlaceholder, 'class' => 'form__control--mono', 'spellcheck' => 'false']) ?>
                    <?php endif; ?>

                    <?php if (isset($fields['port'])): ?>
                        <?= $form->text('port', 'Port', $values['port'] ?? '', type: 'number', hint: 'Prázdné = výchozí (SFTP 22, FTP 21).', attributes: ['min' => '1', 'max' => '65535']) ?>
                    <?php endif; ?>

                    <?php if (isset($fields['database_name'])): ?>
                        <?= $form->text('database_name', 'Název databáze', $values['database_name'], attributes: ['class' => 'form__control--mono', 'spellcheck' => 'false']) ?>
                    <?php endif; ?>
                </div>

                <div class="form__row">
                    <?= $form->text('username', 'Uživatel', $values['username'], attributes: ['class' => 'form__control--mono', 'spellcheck' => 'false', 'autocomplete' => 'off']) ?>
                    <?php // `new-password`: správce hesel prohlížeče sem jinak doplní heslo do Správy webů. ?>
                    <?= $form->text('password', 'Heslo', '', type: 'password',
                        hint: $hasPassword ? 'Heslo je uložené. Nechte prázdné, zůstane beze změny.' : '',
                        attributes: ['class' => 'form__control--mono', 'autocomplete' => 'new-password', 'placeholder' => $hasPassword ? '••••••••' : '']) ?>
                </div>

                <?= $form->textarea('note', 'Poznámka', $values['note'], hint: 'Cokoli dalšího — kdo účet spravuje, kam volat, PIN k podpoře hostingu.', attributes: ['rows' => '3', 'maxlength' => '5000']) ?>

                <div class="row">
                    <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Uložit změny' : 'Uložit přístup' ?></button>
                    <a class="btn btn--ghost" href="<?= $backUrl ?>">Zpět</a>
                </div>
            </form>
        </section>
    </div>
</div>
