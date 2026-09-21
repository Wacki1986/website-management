<?php
/**
 * Nastavení → Oznámení — upozornění na telefon (web push).
 *
 * Tři karty, protože jsou to tři různé otázky: „chci to na tomhle
 * zařízení", „co má pípat" a „funguje to vůbec". Přepínač obsluhuje
 * `assets/js/modules/push.js`. Bez JavaScriptu se ukáže jen seznam
 * zařízení s tlačítky Odebrat — obyčejné formuláře, fungují dál.
 *
 * @var \App\Core\View\View $this
 * @var string              $title
 * @var string              $activeTab
 * @var string              $csrfToken
 * @var bool                $pushReady      jsou klíče připravené?
 * @var bool                $pushUsable     jde jimi opravdu podepisovat?
 * @var string              $pushPublicKey  veřejný VAPID klíč pro prohlížeč
 * @var string              $pushKeyHint    poslední 4 znaky soukromého klíče
 * @var int                 $pushDeviceCount kolik zařízení má celá instalace
 * @var array<int, array{id: int, hash: string, name: string, note: string}> $pushDevices
 * @var array<int, array{key: string, label: string, hint: string, on: bool}> $pushEvents
 * @var bool                $ipRestricted   je vyplněné `allowed_ips`?
 */
$this->extend('layout/shell', ['title' => $title]);

$form = $this->form();
?>
<?= $this->partial('partials/settings-tabs', ['activeTab' => $activeTab]) ?>

<div class="app__content">
    <div class="split split--settings">
        <div class="stack">

            <section class="card card--padded">
                <div class="form">
                    <div>
                        <div class="card__title">Toto zařízení</div>
                        <div class="card__note">Upozornění se zapíná za prohlížeč, ne za účet — povolení dává telefon. Na každém zařízení, kde je chcete, je proto potřeba zapnout znovu.</div>
                    </div>

                    <div class="panel pwa-hint">
                        <div class="caps">Nejdřív na plochu</div>
                        <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed)">
                            Aplikace se pak otevírá bez lišty prohlížeče a na iPhonu je to podmínka upozornění.
                            <b>Android (Chrome):</b> nabídka ⋮ → Přidat na plochu.
                            <b>iPhone (Safari):</b> Sdílet → Přidat na plochu.
                        </div>
                    </div>

                    <?php if (!$pushReady): ?>
                        <?php render_notice($this, 'info', message:
                            'Upozornění ještě nejsou připravená. Aplikace si k nim musí jednou vyrobit podpisový klíč — trvá to okamžik a dělá se to jen jednou.') ?>
                        <form method="post" action="<?= get_url('nastaveni/oznameni/pripravit') ?>">
                            <?php render_csrf($csrfToken) ?>
                            <button type="submit" class="btn btn--primary">Připravit upozornění</button>
                        </form>
                    <?php else: ?>
                        <div class="row" style="justify-content:space-between" data-push
                             data-push-key="<?= $this->e($pushPublicKey) ?>"
                             data-push-subscribe="<?= get_url('nastaveni/oznameni/prihlasit') ?>"
                             data-push-unsubscribe="<?= get_url('nastaveni/oznameni/odhlasit') ?>"
                             hidden>
                            <div>
                                <div style="font-weight:var(--font-weight-semibold)">Upozornění v tomhle prohlížeči</div>
                                <div class="text-caption" data-push-status>Zjišťuji stav…</div>
                            </div>
                            <button type="button" class="btn btn--primary" data-push-on><?= get_btn_icon('alert') ?>Zapnout upozornění</button>
                            <button type="button" class="btn btn--secondary" data-push-off hidden><?= get_btn_icon('close') ?>Vypnout na tomto zařízení</button>
                        </div>
                        <div data-push-unsupported hidden>
                            <?php render_notice($this, 'warning', message: 'Tenhle prohlížeč upozornění na pozadí neumí. Na telefonu otevřete aplikaci z plochy; na počítači zkuste Chrome, Edge nebo Firefox.') ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($pushDevices !== []): ?>
                        <div class="form__field">
                            <span class="form__label form__label--caps">Přihlášená zařízení</span>
                            <div class="summary-list">
                                <?php foreach ($pushDevices as $device): ?>
                                    <div class="summary-list__row" data-push-device="<?= $this->e($device['hash']) ?>" style="align-items:center">
                                        <div>
                                            <div style="font-weight:var(--font-weight-medium)"><?= $this->e($device['name']) ?><span class="push-devices__this text-caption" hidden> · toto zařízení</span></div>
                                            <div class="text-caption"><?= $this->e($device['note']) ?></div>
                                        </div>
                                        <form method="post" action="<?= get_url('nastaveni/oznameni/' . $device['id'] . '/smazat') ?>" class="push-devices__remove">
                                            <?php render_csrf($csrfToken) ?>
                                            <button type="submit" class="btn btn--ghost btn--sm"><?= get_btn_icon('trash') ?>Odebrat</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card card--padded">
                <form method="post" action="<?= get_url('nastaveni/oznameni') ?>" class="form">
                    <?php render_csrf($csrfToken) ?>
                    <div>
                        <div class="card__title">Co má pípat</div>
                        <div class="card__note">Platí pro celou aplikaci, ne pro jedno zařízení. E-maily o alertech chodí zvlášť podle Nastavení → Alerty a prahy.</div>
                    </div>

                    <?php foreach ($pushEvents as $event): ?>
                        <?= $form->checkbox('push_on_' . $event['key'], $event['label'], $event['on'], hint: $event['hint']) ?>
                    <?php endforeach; ?>

                    <div class="row"><button type="submit" class="btn btn--primary">Uložit</button></div>
                </form>
            </section>

            <?php if ($pushReady): ?>
                <section class="card card--padded">
                    <div class="form">
                        <div>
                            <div class="card__title">Stav a zkouška</div>
                            <div class="card__note">Podpisový klíč je vyrobený (••••<?= $this->e($pushKeyHint) ?>) a nemění se — jeho výměna by odhlásila všechny telefony. Upozornění dostává <?= $pushDeviceCount ?> zařízení.</div>
                        </div>

                        <?php if (!$pushUsable): ?>
                            <?php render_notice($this, 'error', message:
                                'Podpisový klíč nejde přečíst — nejspíš se změnil app_key v config/env.php. Upozornění tím pádem neodejdou. Obnovte původní app_key, nebo klíč vyrobte znovu (všechna zařízení se pak musí přihlásit znovu).') ?>
                        <?php endif; ?>

                        <?php if ($pushDeviceCount === 0): ?>
                            <?php render_notice($this, 'info', message:
                                'Zatím není přihlášené žádné zařízení. Zkouška nemá kam odejít — nejdřív zapněte upozornění v kartě „Toto zařízení".') ?>
                        <?php endif; ?>

                        <?php if ($ipRestricted): ?>
                            <?php render_notice($this, 'warning', message:
                                'Přístup je omezený na povolené adresy (allowed_ips). Upozornění dorazí, ale klepnutí na ně z mobilní sítě skončí chybou 403 — mobilní operátor adresu mění.') ?>
                        <?php endif; ?>

                        <form method="post" action="<?= get_url('nastaveni/oznameni/zkouska') ?>">
                            <?php render_csrf($csrfToken) ?>
                            <button type="submit" class="btn btn--secondary"><?= get_btn_icon('alert') ?>Poslat zkušební upozornění</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <aside class="split__aside">
            <div class="card card--small-shadow card--padded">
                <div style="font-weight:var(--font-weight-semibold)">Kdy pípne telefon</div>
                <div class="text-subtle" style="font-size:var(--font-size-label);margin-top:6px;line-height:var(--line-height-relaxed);text-wrap:pretty">Push je pro věci, které nepočkají do rána: výpadek webu a prošlý certifikát. Souhrn aktualizací chodí jednou denně. Každá skupina má strop za hodinu, aby salva alertů telefon nezahltila.</div>
            </div>
        </aside>
    </div>
</div>
