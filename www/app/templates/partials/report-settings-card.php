<?php
/**
 * Karta „Klientské reporty" v Nastavení webu (návrh
 * `detail-webu-nastaveni.html`): plán odesílání, schvalování a adresáti.
 * Adresáti jsou samostatné formuláře (přidat / odebrat), aby fungovaly
 * bez JavaScriptu.
 *
 * @var \App\Core\View\View $this
 * @var array               $site
 * @var array{is_active: bool, frequency: string, send_day: int, send_hour: int, requires_approval: bool} $report
 * @var string              $reportNote
 * @var string              $reportNext
 * @var array<int, array<string, mixed>> $recipients
 * @var array<int, string>  $sendDays
 * @var array<int, string>  $hours
 * @var array<string, array{label: string, note: string}> $frequencies
 * @var string              $clientEmailHint
 * @var string              $csrfToken
 */
$base = 'weby/' . (int) $site['id'];
?>
<section class="card card--padded" id="reporty">
    <form method="post" action="<?= get_url($base . '/reporty/nastaveni') ?>" class="form">
        <?php render_csrf($csrfToken) ?>
        <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div>
                <div class="card__title">Klientské reporty</div>
                <div class="card__note"><?= $this->e($reportNote) ?></div>
            </div>
            <?= get_toggle('is_active', $report['is_active'], 'Reporty zapnuté') ?>
        </div>

        <div class="form__field">
            <span class="form__label">Jak často</span>
            <div class="segmented">
                <?php foreach ($frequencies as $code => $frequency): ?>
                    <label class="segmented__item<?= $report['frequency'] === $code ? ' segmented__item--active' : '' ?>"><input type="radio" name="frequency" value="<?= $this->e($code) ?>"<?= $report['frequency'] === $code ? ' checked' : '' ?> class="visually-hidden"><?= $this->e($frequency['label']) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="form__hint"><?= $this->e($frequencies[$report['frequency']]['note'] ?? '') ?><?= $report['frequency'] !== 'manual' ? ' Odchází automaticky ' . $this->e(mb_strtolower(\App\Core\Reports\ReportSchedule::describe($report['frequency'], $report['send_day'], $report['send_hour']))) . '.' : '' ?></div>
        </div>

        <div class="form__row">
            <div class="form__field">
                <label class="form__label" for="send_day">Den odeslání</label>
                <select class="form__control" id="send_day" name="send_day">
                    <?php foreach ($sendDays as $value => $label): ?>
                        <option value="<?= (int) $value ?>"<?= $report['send_day'] === (int) $value ? ' selected' : '' ?>><?= $this->e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form__hint">U týdenního reportu den v týdnu, jinak den v měsíci (1–28).</div>
            </div>
            <div class="form__field">
                <label class="form__label" for="send_hour">Čas odeslání</label>
                <select class="form__control" id="send_hour" name="send_hour">
                    <?php foreach ($hours as $value => $label): ?>
                        <option value="<?= (int) $value ?>"<?= $report['send_hour'] === (int) $value ? ' selected' : '' ?>><?= $this->e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label class="form__check">
            <input type="checkbox" name="requires_approval" value="1"<?= $report['requires_approval'] ? ' checked' : '' ?>>
            <span><b>Vyžaduje schválení</b> — report vznikne den před termínem a odejde až po kontrole v aplikaci (jinak odchází sám).</span>
        </label>

        <div class="card__footer" style="padding:14px 0 0">
            <div class="text-subtle" style="font-size:var(--font-size-label)"><?= $this->e($reportNext) ?></div>
            <div class="row u-ml-auto">
                <a class="btn btn--secondary btn--sm" href="<?= get_url($base . '/reporty/nahled') ?>">Náhled reportu</a>
                <button class="btn btn--primary btn--sm" type="submit"><?= get_btn_icon('check') ?>Uložit reporty</button>
            </div>
        </div>
    </form>

    <div class="form__field" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--color-border-subtle)">
        <span class="form__label">Komu chodí</span>
        <div class="pill-group">
            <?php foreach ($recipients as $recipient): ?>
                <form method="post" action="<?= get_url($base . '/reporty/adresat/smazat') ?>" class="pill pill--muted" style="gap:6px">
                    <?php render_csrf($csrfToken) ?>
                    <input type="hidden" name="id" value="<?= (int) $recipient['id'] ?>">
                    <?= get_icon('mail', 'icon--sm icon--subtle') ?><?= $this->e((string) $recipient['email']) ?><span class="pill__note"><?= $this->e((string) $recipient['label']) ?></span>
                    <button class="pill__remove" type="submit" aria-label="Odebrat <?= $this->e((string) $recipient['email']) ?>" title="Odebrat">×</button>
                </form>
            <?php endforeach; ?>
            <?php if ($recipients === []): ?><span class="text-faint" style="font-size:var(--font-size-label)">zatím žádná adresa</span><?php endif; ?>
        </div>
        <form method="post" action="<?= get_url($base . '/reporty/adresat') ?>" class="row" style="margin-top:10px;gap:8px;flex-wrap:wrap">
            <?php render_csrf($csrfToken) ?>
            <input class="form__control" type="email" name="email" placeholder="jmeno@klient.cz" required style="flex:1;min-width:200px" aria-label="E-mail adresáta">
            <select class="form__control" name="label" aria-label="Role adresáta" style="width:auto">
                <option value="klient">klient</option>
                <option value="kopie">kopie</option>
            </select>
            <button class="btn btn--secondary btn--sm" type="submit"><?= get_btn_icon('plus') ?>Přidat adresu</button>
        </form>
        <?php if ($clientEmailHint !== ''): ?><div class="form__hint"><?= $this->e($clientEmailHint) ?></div><?php endif; ?>
    </div>
</section>
