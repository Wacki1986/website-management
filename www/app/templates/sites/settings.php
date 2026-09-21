<?php
/**
 * Detail webu — Nastavení (návrh `detail-webu-nastaveni.html`).
 * Karta klientských reportů je partial `report-settings-card`.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var string|null         $freshKey       čerstvě vygenerovaný klíč — ukáže se jen jednou
 * @var string              $keyMasked      mg_live_••••4f7a
 * @var array<int, string>  $clients
 * @var array<int, string>  $intervals
 * @var string|null         $pluginVersion  verze pluginu k distribuci
 * @var string              $pluginInfoUrl
 * @var string              $csrfToken
 */
$this->extend('layout/shell', ['title' => $site['name'] . ' — Nastavení']);

$form = $this->form();
$base = 'weby/' . (int) $site['id'];
?>
<?= $this->partial('partials/site-header', get_defined_vars()) ?>

<div class="app__content">
    <div class="split split--settings">
        <div class="stack">

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Připojení</div>
                        <div class="card__note">Plugin MEDIAGRAFIK Monitor na straně webu ověřuje požadavky tímto klíčem.</div>
                    </div>

                    <?php if ($freshKey !== null): ?>
                        <?php render_notice($this, 'warning', 'Nový klíč se ukazuje jen teď', 'Zkopírujte ho do pluginu na webu (Nastavení → MEDIAGRAFIK Monitor). Po obnovení stránky už uvidíte jen poslední čtyři znaky.') ?>
                    <?php endif; ?>

                    <div class="form__field">
                        <span class="form__label form__label--caps">API klíč</span>
                        <div class="row" style="flex-wrap:wrap">
                            <div class="form__control form__control--mono" style="flex:1;min-width:220px"><?= $this->e($freshKey ?? ($keyMasked !== '' ? $keyMasked : 'zatím žádný')) ?></div>
                            <?php if ($freshKey !== null): ?>
                                <button class="btn btn--secondary" type="button" data-copy="<?= $this->e($freshKey) ?>">Zkopírovat</button>
                            <?php endif; ?>
                            <form method="post" action="<?= get_url($base . '/nastaveni/klic') ?>">
                                <?php render_csrf($csrfToken) ?>
                                <button class="btn btn--ghost" type="submit">Vygenerovat nový</button>
                            </form>
                        </div>
                        <span class="form__hint">Nový klíč zneplatní starý okamžitě — plugin na webu přestane odpovídat, dokud do něj nevložíte nový.</span>
                    </div>

                    <form method="post" action="<?= get_url($base . '/nastaveni') ?>" class="form">
                        <?php render_csrf($csrfToken) ?>
                        <div class="form__row">
                            <?= $form->text('name', 'Název webu', (string) $site['name'], class: 'form__field--caps') ?>
                            <?= $form->select('client_id', 'Přiřazený klient', $clients, $site['client_id'], placeholder: '— bez klienta —', class: 'form__field--caps') ?>
                            <?= $form->select('check_interval_min', 'Frekvence kontrol', $intervals, (int) $site['check_interval_min'], class: 'form__field--caps') ?>
                            <?= $form->text('admin_url', 'Adresa administrace', (string) $site['admin_url'], type: 'url', class: 'form__field--caps',
                                attributes: ['placeholder' => rtrim((string) $site['url'], '/') . '/wp-admin/', 'class' => 'form__control--mono'], hint: 'Prázdné = /wp-admin/. Vyplňte, když je přihlášení na jiné adrese.') ?>
                            <?= $form->text('hosting_note', 'Hosting', (string) $site['hosting_note'], class: 'form__field--caps', attributes: ['placeholder' => 'Wedos · NoLimit']) ?>
                            <?= $form->text('backup_note', 'Zálohy', (string) $site['backup_note'], class: 'form__field--caps', attributes: ['placeholder' => 'denně · 03:00'], hint: 'Jen poznámka do přehledu, dokud plugin zálohy neumí zjistit.') ?>
                        </div>
                        <div class="row"><button class="btn btn--primary" type="submit">Uložit změny</button><a class="btn btn--ghost" href="<?= get_url($base) ?>">Zrušit</a></div>
                    </form>
                </div>
            </section>

            <?= $this->partial('partials/report-settings-card', get_defined_vars()) ?>

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Plugin na webu</div>
                        <div class="card__note">Co nainstalovat a kam vložit klíč.</div>
                    </div>
                    <div class="summary-list">
                        <div class="summary-list__row"><span class="summary-list__label">Plugin</span><span class="summary-list__value">MEDIAGRAFIK Monitor<?= $pluginVersion !== null ? ' ' . $this->e($pluginVersion) : '' ?></span></div>
                        <div class="summary-list__row"><span class="summary-list__label">Stažení ZIP</span><span class="summary-list__value"><?= $pluginVersion !== null ? '<a href="' . get_url('plugin/mediagrafik-monitor/mediagrafik-monitor-' . $this->e($pluginVersion) . '.zip') . '">mediagrafik-monitor-' . $this->e($pluginVersion) . '.zip</a>' : '<span class="text-faint">zatím nepřipraveno (dev/tools/build-plugin.ps1)</span>' ?></span></div>
                        <div class="summary-list__row"><span class="summary-list__label">Stav pluginu</span><span class="summary-list__value"><?= $this->e(match ((string) $site['api_status']) { 'ok' => 'odpovídá', 'bad_key' => 'špatný klíč', 'no_plugin' => 'není nainstalovaný', 'error' => 'chyba: ' . (string) $site['snapshot_error'], default => 'zatím neověřeno' }) ?></span></div>
                    </div>
                    <div class="text-subtle" style="font-size:var(--font-size-label);line-height:var(--line-height-relaxed)">Ve WordPressu: Pluginy → Nahrát plugin → ZIP → Aktivovat → Nastavení → MEDIAGRAFIK Monitor → vložit API klíč. Aktualizace pluginu pak chodí automaticky z téhle aplikace.</div>
                </div>
            </section>
        </div>

        <aside class="split__aside">
            <form method="post" action="<?= get_url($base . '/nastaveni/hlidani') ?>" class="card card--small-shadow">
                <?php render_csrf($csrfToken) ?>
                <div class="summary-list">
                    <div class="summary-list__row" style="align-items:center">
                        <div>
                            <div style="font-weight:var(--font-weight-semibold);font-size:var(--font-size-body)">Hlídat dostupnost</div>
                            <div class="text-caption">alert po 3 neúspěšných kontrolách</div>
                        </div>
                        <?= get_toggle('watch_uptime', (int) $site['watch_uptime'] === 1, 'Hlídat dostupnost') ?>
                    </div>
                    <div class="summary-list__row" style="align-items:center">
                        <div>
                            <div style="font-weight:var(--font-weight-semibold);font-size:var(--font-size-body)">Hlídat aktualizace</div>
                            <div class="text-caption">denní souhrn v 07:00</div>
                        </div>
                        <?= get_toggle('watch_updates', (int) $site['watch_updates'] === 1, 'Hlídat aktualizace') ?>
                    </div>
                    <div class="summary-list__row" style="align-items:center">
                        <div>
                            <div style="font-weight:var(--font-weight-semibold);font-size:var(--font-size-body)">Hlídat platnost SSL</div>
                            <div class="text-caption">upozornit 30 dní předem</div>
                        </div>
                        <?= get_toggle('watch_ssl', (int) $site['watch_ssl'] === 1, 'Hlídat platnost SSL') ?>
                    </div>
                </div>
                <div class="card__footer"><button type="submit" class="btn btn--secondary btn--sm btn--block">Uložit hlídání</button></div>
            </form>

            <div class="card card--danger">
                <div style="font-weight:var(--font-weight-semibold);color:var(--color-status-error-text)">Odebrat web z monitoringu</div>
                <div class="text-caption" style="margin-top:4px">Historie kontrol a reportů zůstane 12 měsíců v archivu.</div>
                <div style="margin-top:12px"><a class="btn btn--danger btn--sm" href="<?= get_url($base . '/odebrat') ?>"><?= get_btn_icon('trash') ?>Odebrat web</a></div>
            </div>
        </aside>
    </div>
</div>
