<?php
/**
 * Servisní protokol — PDF k jednomu servisu (vyrábí `PdfRenderer`).
 *
 * Samostatná stránka bez layoutu aplikace. Dompdf umí jen CSS 2.1 —
 * žádný flexbox ani grid, rozvržení je proto z tabulek; barvy a písmo
 * odpovídají designu aplikace. Tmavé záhlaví je absolutně umístěné přes
 * okraj stránky (jen na první straně), obsah pod ním odsazuje `.after-band`.
 *
 * Co Dompdf kreslí nespolehlivě, tu záměrně není: znak vycentrovaný
 * v kolečku, inline-block „štítky" přes víc řádků. Fajfka, plus/minus
 * a šipka jsou proto obyčejné znaky v písmu DejaVu Sans (přibalené
 * s Dompdf) — Instrument Sans je nemá.
 *
 * @var \App\Core\View\View $this
 * @var string              $title       titulek dokumentu (vlastnosti PDF)
 * @var string              $number      číslo protokolu = id zápisu servisu
 * @var string              $issued      datum vystavení
 * @var string              $kindLabel   „Střední servis"
 * @var string              $siteHost    odkolek.cz
 * @var string              $subline     název webu · klient (prázdné části vynechané)
 * @var array{age: string, since: string}|null $launched stáří webu do rámečku vpravo (null = datum spuštění chybí)
 * @var string              $date        datum servisu
 * @var array{tone: string, label: string} $status provedeno / přeskočeno
 * @var array<int, array{label: string, value: string, note: string, tone: string}> $tiles velká čísla nahoře
 * @var array<int, array<int, string>> $taskRows hotové úkoly po dvou; '' = prázdná buňka lichého řádku
 * @var array<int, array{name: string, type: string, from: string, to: string}> $updateRows WordPress, PHP, pluginy
 * @var string              $updateCount počet řádků aktualizací ('' = žádné)
 * @var array<int, array{tone: string, symbol: string, label: string, count: string, plugins: array<int, string>}> $otherChanges nové a odebrané pluginy
 * @var string              $updatesNote odkud jsou aktualizace
 * @var array{kind: string, date: string, rhythm: string}|null $nextService příští servis podle plánu
 * @var string              $note        poznámka ze zápisu servisu (text se značkami editoru, `get_rich_text`)
 * @var string              $logoPath    absolutní cesta k logu (bílá varianta pro tmavé záhlaví), '' = jméno studia textem
 * @var string              $studioName  popisek loga
 */
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title><?= $this->e($title) ?></title>
<style>
    @page { margin: 16mm 18mm; }
    body { font-family: "Instrument Sans", sans-serif; font-size: 9pt; line-height: 1.4; color: #41455f; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; }
    .symbol { font-family: "DejaVu Sans", sans-serif; }

    /* Drobné popisky velkými písmeny — jeden styl pro celý dokument. */
    .caps { font-size: 6.5pt; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; }

    /* Záhlaví přes celou šířku stránky (okraj 16 / 18 mm se „vrací" zápornou pozicí). */
    .band { position: absolute; top: -16mm; left: -18mm; width: 210mm; height: 68mm; background: #1b1e38; }
    .band__inner { margin: 11mm 18mm 0; width: 174mm; }
    .band__logo { height: 8.5mm; }
    .band__studio { font-size: 14pt; font-weight: bold; letter-spacing: 0.04em; color: #ffffff; vertical-align: middle; }
    .band__meta { text-align: right; vertical-align: middle; }
    .band__meta .caps { color: #9fb0ff; }
    .band__number { margin-top: 0.5mm; font-size: 11pt; font-weight: 600; color: #ffffff; }
    .band__issued { font-size: 7.5pt; color: #a9aecb; }
    .band__main { padding-top: 9mm; vertical-align: bottom; }
    .band__kicker { color: #9fb0ff; }
    .band__title { margin-top: 1.5mm; font-size: 21pt; font-weight: 600; line-height: 1.1; color: #ffffff; }
    .band__sub { margin-top: 1.5mm; font-size: 9pt; color: #c9cce3; }
    .band__accent { position: absolute; left: 0; bottom: 0; width: 210mm; height: 1.2mm; background: #3858e9; }

    /* Rámeček se stářím webu vpravo vedle názvu. */
    .band__side { width: 42mm; padding-top: 9mm; vertical-align: bottom; }
    .age { padding: 2.8mm 4mm; background: #272b52; border: 1px solid #3b4178; border-radius: 6px; }
    .age .caps { color: #9fb0ff; }
    .age__value { margin-top: 0.8mm; font-size: 15pt; font-weight: 600; line-height: 1.1; color: #ffffff; }
    .age__since { margin-top: 0.8mm; font-size: 7.5pt; color: #a9aecb; }

    .pill { padding: 0.4mm 2.2mm; margin-left: 2mm; border-radius: 8px; letter-spacing: .02em; text-transform: none; }
    .pill--ok { background: #e3f5ee; color: #0a6248; }
    .pill--warning { background: #fef4da; color: #9e5300; }

    .after-band { height: 60mm; }

    .tiles td.tile { width: 32%; padding: 3.5mm 4mm; border-radius: 6px; }
    .tiles td.gap { width: 2%; }
    .tile--ok { background: #e9f7f1; }
    .tile--brand { background: #eef1fd; }
    .tile--neutral { background: #f3f4f9; }
    .tile .caps { color: #5f6379; }
    .tile__value { margin-top: 1mm; font-size: 17pt; font-weight: 600; line-height: 1.1; color: #14162b; }
    .tile--ok .tile__value { color: #0a6248; }
    .tile--brand .tile__value { color: #2a46c9; }
    .tile__note { margin-top: 0.8mm; font-size: 7.5pt; color: #5f6379; }

    h2 { margin: 6mm 0 1.5mm; font-size: 10.5pt; font-weight: 600; color: #14162b; }
    h2 .count { font-weight: 500; color: #6e7288; }

    .tasks td { width: 50%; padding: 1.3mm 4mm 1.3mm 0; border-bottom: 1px solid #eceef5; color: #14162b; }
    .check { margin-right: 1.5mm; font-size: 8pt; font-weight: bold; color: #0f9d6b; }

    .note { margin-top: 6mm; padding: 3.5mm 4.5mm; background: #eef1fd; border-left: 1mm solid #3858e9; }
    .note .caps { color: #3858e9; }
    .note__text { margin-top: 1mm; color: #14162b; }
    .note__text p, .note__text ul, .note__text ol { margin: 0 0 1mm; }
    .note__text ul, .note__text ol { padding-left: 4.5mm; }
    .note__text a { color: #3858e9; }

    .updates th { padding: 0 0 1.5mm; color: #6e7288; text-align: left; border-bottom: 1px solid #e1e3ee; }
    .updates td { padding: 1.4mm 0; border-bottom: 1px solid #eceef5; vertical-align: middle; }
    .updates tr.is-core td { background: #f6f8fe; }
    .updates td.name, .updates th.name { padding-left: 2mm; }
    .updates__name { font-weight: 600; color: #14162b; }
    .updates__type { font-size: 7pt; color: #6e7288; }
    .version { font-family: "DejaVu Sans Mono", monospace; font-size: 7.5pt; }
    .version--from { color: #6e7288; }
    .version--to { color: #0a6248; font-weight: bold; }
    .arrow { color: #a9aecb; font-size: 8pt; }
    /* Odkud jsou aktualizace — hned pod nadpisem, ať neosiří na další straně. */
    h2.h2--with-source { margin-bottom: 0.5mm; }
    .updates__source { margin-bottom: 2.5mm; font-size: 7pt; color: #6e7288; }

    /* Nové a odebrané pluginy — barevný pruh, ať nezapadnou mezi aktualizacemi. */
    .change { margin-top: 2.5mm; page-break-inside: avoid; }
    .change td { padding: 2.5mm 4mm; }
    .change__mark { font-size: 8.5pt; font-weight: bold; letter-spacing: 0; }
    .change__count { font-weight: 500; letter-spacing: 0; text-transform: none; }
    .change__plugin { margin-top: 0.8mm; font-weight: 600; color: #14162b; }
    .change--ok td { background: #e9f7f1; border-left: 1mm solid #0f9d6b; }
    .change--ok .caps { color: #0a6248; }
    .change--warning td { background: #fef4da; border-left: 1mm solid #e2900a; }
    .change--warning .caps { color: #9e5300; }

    .next { margin-top: 6mm; page-break-inside: avoid; }
    .next td { padding: 3.5mm 4.5mm; background: #f3f4f9; vertical-align: middle; }
    .next td.next__what { border-left: 1mm solid #3858e9; }
    .next td.next__when { width: 50mm; text-align: right; }
    .next .caps { color: #5f6379; }
    .next__kind { margin-top: 0.8mm; font-size: 11pt; font-weight: 600; color: #14162b; }
    .next__date { margin-top: 0.8mm; font-size: 13pt; font-weight: 600; line-height: 1.1; color: #2a46c9; }
    .next__rhythm { margin-top: 0.8mm; font-size: 7.5pt; color: #5f6379; }

    .empty { color: #5f6379; }
</style>
</head>
<body>

<div class="band">
    <table class="band__inner">
        <tr>
            <?php if ($logoPath !== ''): ?>
                <td><img class="band__logo" src="<?= $this->e($logoPath) ?>" alt="<?= $this->e($studioName) ?>"></td>
            <?php else: ?>
                <td class="band__studio"><?= $this->e($studioName) ?></td>
            <?php endif; ?>
            <td class="band__meta">
                <div class="caps">Servisní protokol</div>
                <div class="band__number">č. <?= $this->e($number) ?></div>
                <div class="band__issued">vystaveno <?= $this->e($issued) ?></div>
            </td>
        </tr>
        <tr>
            <td class="band__main"<?= $launched === null ? ' colspan="2"' : '' ?>>
                <div class="band__kicker caps"><?= $this->e($kindLabel) ?> · <?= $this->e($date) ?><span class="pill pill--<?= $this->e($status['tone']) ?>"><?= $this->e($status['label']) ?></span></div>
                <div class="band__title"><?= $this->e($siteHost) ?></div>
                <?php if ($subline !== ''): ?><div class="band__sub"><?= $this->e($subline) ?></div><?php endif; ?>
            </td>
            <?php if ($launched !== null): ?>
                <td class="band__side">
                    <div class="age">
                        <div class="caps">Stáří webu</div>
                        <div class="age__value"><?= $this->e($launched['age']) ?></div>
                        <div class="age__since"><?= $this->e($launched['since']) ?></div>
                    </div>
                </td>
            <?php endif; ?>
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
                <div class="caps"><?= $this->e($tile['label']) ?></div>
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
                    <td><?php if ($task !== ''): ?><span class="check symbol">✓</span><?= $this->e($task) ?><?php endif; ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>
<?php elseif ($note === ''): ?>
    <div class="empty">K servisu nejsou zapsané podrobnosti.</div>
<?php endif; ?>

<?php if ($note !== ''): ?>
    <div class="note">
        <div class="caps">Poznámka k servisu</div>
        <div class="note__text"><?= get_rich_text($note) ?></div>
    </div>
<?php endif; ?>

<?php if ($updateRows !== [] || $otherChanges !== []): ?>
    <h2 class="h2--with-source">Aktualizace na webu<?php if ($updateCount !== ''): ?> <span class="count">· <?= $this->e($updateCount) ?></span><?php endif; ?></h2>
    <div class="updates__source"><?= $this->e($updatesNote) ?></div>
    <?php if ($updateRows !== []): ?>
        <table class="updates">
            <thead><tr>
                <th class="name caps">Co</th>
                <th class="caps" style="width:28mm">Původní verze</th>
                <th style="width:7mm"></th>
                <th class="caps" style="width:28mm">Nová verze</th>
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
        <table class="change change--<?= $this->e($change['tone']) ?>"><tr><td>
            <div class="caps"><span class="change__mark symbol"><?= $this->e($change['symbol']) ?></span> <?= $this->e($change['label']) ?> <span class="change__count">· <?= $this->e($change['count']) ?></span></div>
            <?php foreach ($change['plugins'] as $plugin): ?>
                <div class="change__plugin"><?= $this->e($plugin) ?></div>
            <?php endforeach; ?>
        </td></tr></table>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($nextService !== null): ?>
    <table class="next"><tr>
        <td class="next__what">
            <div class="caps">Příští servis</div>
            <div class="next__kind"><?= $this->e($nextService['kind']) ?></div>
            <div class="next__rhythm"><?= $this->e($nextService['rhythm']) ?></div>
        </td>
        <td class="next__when">
            <div class="caps">Naplánováno na</div>
            <div class="next__date"><?= $this->e($nextService['date']) ?></div>
        </td>
    </tr></table>
<?php endif; ?>

</body>
</html>
