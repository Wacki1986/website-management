<?php
/**
 * Servisní protokol — PDF k jednomu servisu (vyrábí `PdfRenderer`).
 *
 * Samostatná stránka bez layoutu aplikace. Dompdf neumí flexbox ani grid,
 * rozvržení je proto z tabulek; barvy a písmo odpovídají designu aplikace.
 * Tmavé záhlaví je absolutně umístěné přes okraj stránky (jen na první
 * straně), obsah pod ním odsazuje `.after-band`. Fajfka a šipka jsou
 * v písmu DejaVu Sans (přibalené s Dompdf) — Instrument Sans je nemá.
 *
 * @var \App\Core\View\View $this
 * @var string              $title       titulek dokumentu (vlastnosti PDF)
 * @var string              $number      číslo protokolu = id zápisu servisu
 * @var string              $issued      datum vystavení
 * @var string              $kindLabel   „Střední servis"
 * @var string              $siteHost    odkolek.cz
 * @var string              $subline     název webu · klient (prázdné části vynechané)
 * @var string              $date        datum servisu
 * @var array{tone: string, label: string} $status provedeno / přeskočeno
 * @var array<int, array{label: string, value: string, note: string, tone: string}> $tiles velká čísla nahoře
 * @var array<int, array<int, string>> $taskRows hotové úkoly po dvou; '' = prázdná buňka lichého řádku
 * @var array<int, array{name: string, type: string, from: string, to: string}> $updateRows WordPress, PHP, pluginy
 * @var string              $updateCount počet řádků aktualizací
 * @var array<int, string>  $otherChanges nové a odebrané pluginy (věty)
 * @var string              $updatesNote odkud jsou aktualizace
 * @var string              $note        poznámka ze zápisu servisu
 * @var string              $logoPath    absolutní cesta k logu (bílá varianta pro tmavé záhlaví)
 * @var string              $studioName
 * @var string              $studioContact e-mail · telefon (může být prázdné)
 */
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title><?= $this->e($title) ?></title>
<style>
    @page { margin: 16mm 18mm 24mm; }
    body { font-family: "Instrument Sans", sans-serif; font-size: 10pt; line-height: 1.45; color: #41455f; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; }
    .symbol { font-family: "DejaVu Sans", sans-serif; }

    /* Záhlaví přes celou šířku stránky (okraj 16 / 18 mm se „vrací" zápornou pozicí). */
    .band { position: absolute; top: -16mm; left: -18mm; width: 210mm; height: 78mm; background: #1b1e38; }
    .band__inner { margin: 12mm 18mm 0; width: 174mm; }
    .band__logo { height: 10mm; }
    .band__meta { text-align: right; vertical-align: middle; }
    .band__label { font-size: 7.5pt; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #9fb0ff; }
    .band__number { font-size: 13pt; font-weight: 600; color: #ffffff; }
    .band__issued { font-size: 8pt; color: #a9aecb; }
    .band__kicker { margin-top: 9mm; font-size: 8pt; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #9fb0ff; }
    .band__title { margin-top: 1.5mm; font-size: 27pt; font-weight: 600; line-height: 1.1; color: #ffffff; }
    .band__sub { margin-top: 2mm; font-size: 10pt; color: #c9cce3; }
    .band__accent { position: absolute; left: 0; bottom: 0; width: 210mm; height: 1.4mm; background: #3858e9; }

    .pill { display: inline-block; margin-left: 2.5mm; padding: 0.6mm 2.6mm; border-radius: 10px; font-size: 7.5pt; font-weight: 600; letter-spacing: .02em; text-transform: none; }
    .pill--ok { background: #e3f5ee; color: #0a6248; }
    .pill--warning { background: #fef4da; color: #9e5300; }
    .dot { display: inline-block; width: 6px; height: 6px; border-radius: 3px; margin-right: 4px; }
    .dot--ok { background: #0f9d6b; }
    .dot--warning { background: #e2900a; }

    .after-band { height: 70mm; }

    .tiles td.tile { width: 32%; padding: 4.5mm 5mm; border-radius: 8px; }
    .tiles td.gap { width: 2%; }
    .tile--ok { background: #e9f7f1; }
    .tile--brand { background: #eef1fd; }
    .tile--neutral { background: #f3f4f9; }
    .tile__label { font-size: 7.5pt; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #5f6379; }
    .tile__value { margin-top: 1.5mm; font-size: 22pt; font-weight: 600; line-height: 1.1; color: #14162b; }
    .tile--ok .tile__value { color: #0a6248; }
    .tile--brand .tile__value { color: #2a46c9; }
    .tile__note { margin-top: 1mm; font-size: 8.5pt; color: #5f6379; }

    h2 { margin: 9mm 0 3.5mm; font-size: 12.5pt; font-weight: 600; color: #14162b; }
    h2 .count { font-weight: 500; color: #6e7288; }

    .tasks td.task { width: 50%; padding: 2.4mm 3mm 2.4mm 0; border-bottom: 1px solid #eceef5; }
    .tasks td.task__badge { width: 5mm; height: 5mm; padding: 0; border-radius: 2.5mm; background: #e3f5ee; color: #0f9d6b; font-size: 7.5pt; font-weight: bold; text-align: center; vertical-align: middle; }
    .tasks td.task__gap { width: 2.5mm; }
    .tasks td.task__label { font-size: 10pt; line-height: 5mm; color: #14162b; vertical-align: middle; }

    .updates th { padding: 0 0 2mm; font-size: 7.5pt; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #6e7288; text-align: left; border-bottom: 1px solid #e7e9f2; }
    .updates td { padding: 1.7mm 0; border-bottom: 1px solid #eceef5; vertical-align: middle; }
    .updates tr.is-core td { background: #f6f8fe; }
    .updates td.name { padding-left: 2mm; }
    .updates__name { font-size: 10pt; font-weight: 600; color: #14162b; }
    .updates__type { font-size: 8pt; color: #6e7288; }
    .version { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; }
    .version--from { color: #6e7288; }
    .version--to { color: #0a6248; font-weight: bold; }
    .arrow { color: #a9aecb; font-size: 9pt; }
    .updates__other { margin-top: 3mm; font-size: 9pt; color: #41455f; }
    .updates__source { margin-top: 2.5mm; font-size: 8pt; color: #6e7288; }

    .note { margin-top: 9mm; padding: 4.5mm 5.5mm; background: #eef1fd; border-left: 1.2mm solid #3858e9; border-radius: 4px; }
    .note__label { font-size: 7.5pt; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #3858e9; }
    .note__text { margin-top: 1.5mm; font-size: 10pt; line-height: 1.3; color: #14162b; }

    .empty { font-size: 10pt; color: #5f6379; }

    .foot { position: fixed; bottom: -15mm; left: 0; right: 0; padding-top: 3mm; border-top: 1px solid #e7e9f2; font-size: 8pt; color: #6e7288; }
    .foot__name { font-weight: 600; color: #41455f; }
    .foot__page { text-align: right; }
    .foot__page:after { content: "strana " counter(page); }
</style>
</head>
<body>

<table class="foot">
    <tr>
        <td><span class="foot__name"><?= $this->e($studioName) ?></span><?php if ($studioContact !== ''): ?> · <?= $this->e($studioContact) ?><?php endif; ?></td>
        <td class="foot__page">Servisní protokol č. <?= $this->e($number) ?> · </td>
    </tr>
</table>

<div class="band">
    <table class="band__inner">
        <tr>
            <td><img class="band__logo" src="<?= $this->e($logoPath) ?>" alt="<?= $this->e($studioName) ?>"></td>
            <td class="band__meta">
                <div class="band__label">Servisní protokol</div>
                <div class="band__number">č. <?= $this->e($number) ?></div>
                <div class="band__issued">vystaveno <?= $this->e($issued) ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="band__kicker"><?= $this->e($kindLabel) ?> · <?= $this->e($date) ?><span class="pill pill--<?= $this->e($status['tone']) ?>"><span class="dot dot--<?= $this->e($status['tone']) ?>"></span><?= $this->e($status['label']) ?></span></div>
                <div class="band__title"><?= $this->e($siteHost) ?></div>
                <div class="band__sub">
                    <?= $this->e($subline) ?>
                </div>
            </td>
        </tr>
    </table>
    <div class="band__accent"></div>
</div>
<div class="after-band"></div>

<table class="tiles">
    <tr>
        <?php foreach ($tiles as $i => $tile): ?>
            <?php if ($i > 0): ?><td class="gap"></td><?php endif; ?>
            <td class="tile tile--<?= $this->e($tile['tone']) ?>">
                <div class="tile__label"><?= $this->e($tile['label']) ?></div>
                <div class="tile__value"><?= $this->e($tile['value']) ?></div>
                <div class="tile__note"><?= $this->e($tile['note']) ?></div>
            </td>
        <?php endforeach; ?>
    </tr>
</table>

<h2>Co jsme udělali</h2>
<?php if ($taskRows !== []): ?>
    <table class="tasks">
        <?php foreach ($taskRows as $row): ?>
            <tr>
                <?php foreach ($row as $task): ?>
                    <td class="task">
                        <?php if ($task !== ''): ?>
                            <table><tr>
                                <td class="task__badge symbol">✓</td>
                                <td class="task__gap"></td>
                                <td class="task__label"><?= $this->e($task) ?></td>
                            </tr></table>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>
<?php elseif ($note === ''): ?>
    <div class="empty">K servisu nejsou zapsané podrobnosti.</div>
<?php endif; ?>

<?php if ($note !== ''): ?>
    <div class="note">
        <div class="note__label">Poznámka k servisu</div>
        <div class="note__text"><?= nl2br($this->e($note)) ?></div>
    </div>
<?php endif; ?>

<?php if ($updateRows !== [] || $otherChanges !== []): ?>
    <h2>Aktualizace na webu <span class="count">· <?= $this->e($updateCount) ?></span></h2>
    <?php if ($updateRows !== []): ?>
        <table class="updates">
            <thead><tr>
                <th style="padding-left:2mm">Co</th>
                <th style="width:30mm">Původní verze</th>
                <th style="width:8mm"></th>
                <th style="width:30mm">Nová verze</th>
            </tr></thead>
            <tbody>
            <?php foreach ($updateRows as $row): ?>
                <tr<?= $row['type'] !== 'plugin' ? ' class="is-core"' : '' ?>>
                    <td class="name">
                        <div class="updates__name"><?= $this->e($row['name']) ?></div>
                        <?php if ($row['type'] !== 'plugin'): ?><div class="updates__type"><?= $this->e($row['type']) ?></div><?php endif; ?>
                    </td>
                    <td><span class="version version--from"><?= $this->e($row['from']) ?></span></td>
                    <td><span class="arrow symbol">→</span></td>
                    <td><span class="version version--to"><?= $this->e($row['to']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php foreach ($otherChanges as $change): ?>
        <div class="updates__other"><?= $this->e($change) ?></div>
    <?php endforeach; ?>
    <div class="updates__source"><?= $this->e($updatesNote) ?></div>
<?php endif; ?>

</body>
</html>
