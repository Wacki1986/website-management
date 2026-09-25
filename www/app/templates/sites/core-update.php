<?php
/**
 * Potvrzení aktualizace WordPressu na webu (návrh ho nemá; skládá se
 * z karet jako potvrzení odebrání webu).
 *
 * Verze jde ve skrytém poli: potvrzuje se konkrétní skok, a kdyby web
 * mezitím nabízel jinou verzi, akce se odmítne.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var string              $from       současná verze
 * @var string              $to         verze, kterou web nabízí
 * @var bool                $major      hlavní verze (6.8 → 6.9), ne jen opravná
 * @var string              $backupNote kdy byla poslední záloha
 * @var bool                $backupOld  záloha chybí nebo je starší dvou dnů
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Aktualizace WordPressu']);

$base = 'weby/' . (int) $site['id'];
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split split--settings">
        <section class="card card--padded">
            <form method="post" action="<?= get_url($base . '/wordpress') ?>" class="form" data-pending data-pending-overlay="Aktualizuji WordPress…" data-pending-note="Web mezitím ukazuje návštěvníkům stránku údržby. Obvykle to trvá do minuty — stránku nezavírejte.">
                <?php render_csrf($csrfToken) ?>
                <input type="hidden" name="version" value="<?= $this->e($to) ?>">
                <div>
                    <div class="card__title">Aktualizovat WordPress <?= $this->e($from) ?> → <?= $this->e($to) ?></div>
                    <div class="card__note"><?= $major
                        ? 'Hlavní verze — může změnit chování editoru a šablony. Po aktualizaci web projděte.'
                        : 'Opravná verze — bezpečnostní a drobné opravy, chování webu se nemění.' ?></div>
                </div>

                <?php render_notice($this, $backupOld ? 'warning' : 'info', message: $backupNote . ($backupOld ? ' Před aktualizací se vyplatí web zazálohovat.' : '')) ?>

                <div class="card__note">Web aktualizuje stejně jako tlačítko ve wp-admin: po dobu aktualizace (obvykle do minuty) ukazuje návštěvníkům stránku údržby, databázi převede sám. Výsledek se zapíše do historie webu.</div>

                <div class="row">
                    <button type="submit" class="btn btn--primary" data-pending-label="Aktualizuji…"><?= get_btn_icon('refresh') ?>Aktualizovat WordPress</button>
                    <a class="btn btn--ghost" href="<?= get_url($base) ?>">Zrušit</a>
                </div>
            </form>
        </section>
    </div>
</div>
