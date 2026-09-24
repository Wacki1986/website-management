<?php

declare(strict_types=1);

namespace App\Core\Reports;

use App\Core\Sites\ContentFreshness;

/**
 * HTML klientského reportu podle návrhu `nahled-reportu.html`.
 *
 * Jediné místo v aplikaci s inline styly a natvrdo psanými barvami:
 * e-mail se renderuje v cizím poštovním klientovi, kde tokeny ani třídy
 * neplatí. Rozvržení je tabulkové (Outlook), ikony jsou textové znaky —
 * CSS masky z návrhu v poště nefungují.
 *
 * `body()` vrací vnitřek listu (pro náhled uvnitř `.email-paper__sheet`),
 * `document()` celý HTML dokument pro odeslání.
 */
final class ReportRenderer
{
    private const BRAND = '#3858e9';
    private const TEXT = '#14162b';
    private const MUTED = '#5f6379';
    private const BODY = '#41455f';
    private const BORDER = '#e7e9f2';
    private const OK = '#0f9d6b';

    /** Barva tečky stavu (`ContentFreshness::tone()`) — v poště nejsou tokeny. */
    private const TONES = ['ok' => self::OK, 'warning' => '#e2900a', 'error' => '#dc2626', 'muted' => self::MUTED];

    /**
     * @param array<string, mixed> $summary  z `ReportBuilder::build()`
     * @param array{note?: string, sections?: array<int, string>, pixelUrl?: string, logoUrl?: string,
     *     studio?: array{name: string, email: string, phone: string}, contactUrl?: string, mobile?: bool, preview?: bool} $options
     *     `preview` = vykreslit i vypnuté sekce (skryté, s `data-report-section`) pro přepínání v náhledu
     */
    public function body(array $summary, array $options = []): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $sections = $options['sections'] ?? ReportRepository::defaultSections();
        $on = static fn (string $key): bool => in_array($key, $sections, true);
        // Náhled před odesláním vykreslí i vypnuté sekce, jen skryté — přepínač
        // sekce je pak ukáže bez načtení stránky (report-preview.js). Do e-mailu
        // klientovi jdou jen zapnuté.
        $preview = (bool) ($options['preview'] ?? false);
        $show = static fn (string $key): bool => $preview || $on($key);
        $row = static fn (string $key): string => $preview ? '<tr data-report-section="' . $key . '"' . ($on($key) ? '' : ' hidden') . '>' : '<tr>';
        $studio = $options['studio'] ?? ['name' => 'MEDIAGRAFIK', 'email' => '', 'phone' => ''];
        $logoUrl = $options['logoUrl'] ?? '';
        $mobile = (bool) ($options['mobile'] ?? false);
        $note = trim((string) ($options['note'] ?? ''));
        $period = $summary['period'];
        $uptime = $summary['uptime'];
        $pad = $mobile ? '20px' : '30px';

        $h = '';

        // Hlavička s logem.
        $h .= '<tr><td style="padding:26px ' . $pad . ' 22px;border-bottom:3px solid ' . self::BRAND . ';">'
            . ($logoUrl !== ''
                ? '<img src="' . $e($logoUrl) . '" alt="' . $e($studio['name']) . '" width="170" style="width:170px;height:auto;display:block;border:0;">'
                : '<span style="font-size:20px;font-weight:700;letter-spacing:.04em;color:' . self::TEXT . ';">' . $e($studio['name']) . '</span>')
            . '</td></tr>';

        // Úvod.
        $h .= '<tr><td style="padding:28px ' . $pad . ' 8px;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;color:' . self::MUTED . ';">' . $e(mb_strtoupper('Zpráva o vašem webu · ' . $period['label'])) . '</div>'
            . '<h2 style="margin:10px 0 0;font-size:24px;font-weight:600;letter-spacing:-.02em;line-height:1.2;color:' . self::TEXT . ';">' . $e($summary['headline']) . '</h2>'
            . '<p style="margin:10px 0 0;font-size:15px;line-height:1.6;color:' . self::BODY . ';">Dobrý den, tady je krátký přehled o webu <b style="font-weight:600;color:' . self::TEXT . ';">' . $e($summary['site']['host']) . '</b> za období ' . $e(mb_strtolower($period['label'])) . '. Stará se o něj studio ' . $e($studio['name']) . ' — všechno níže jsme udělali za vás, nic nemusíte řešit.</p>'
            . '</td></tr>';

        // Tři metriky.
        $metric = static function (string $label, string $value, string $note, bool $good) use ($e): string {
            return '<td width="33%" valign="top" style="padding:0 6px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border:1px solid ' . self::BORDER . ';border-radius:12px;padding:14px 16px;background:' . ($good ? 'rgba(15,157,107,.08)' : '#ffffff') . ';">'
                . '<div style="font-size:12px;color:' . self::MUTED . ';font-weight:500;">' . $e($label) . '</div>'
                . '<div style="font-size:24px;font-weight:600;letter-spacing:-.03em;margin-top:6px;line-height:1;color:' . ($good ? '#0a6248' : self::TEXT) . ';">' . $e($value) . '</div>'
                . '<div style="font-size:12px;color:' . self::MUTED . ';margin-top:6px;">' . $e($note) . '</div>'
                . '</td></tr></table></td>';
        };

        $metrics = [
            $metric('Dostupnost webu', $uptime['percentLabel'], $uptime['downtimeLabel'], $uptime['percent'] !== null && $uptime['percent'] >= 99),
            $metric('Provedené aktualizace', (string) $summary['updates']['total'], $summary['updates']['noteLabel'], false),
            $metric('Kontrol webu', $uptime['checksLabel'], $uptime['intervalLabel'], false),
        ];

        $h .= '<tr><td style="padding:22px ' . ($mobile ? '14px' : '24px') . ' 0;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . ($mobile ? '<tr>' . $metrics[0] . '</tr><tr><td style="height:12px;"></td></tr><tr>' . $metrics[1] . '</tr><tr><td style="height:12px;"></td></tr><tr>' . $metrics[2] . '</tr>' : '<tr>' . implode('', $metrics) . '</tr>')
            . '</table></td></tr>';

        // Poznámka od studia.
        if ($note !== '') {
            $h .= '<tr><td style="padding:20px ' . $pad . ' 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:16px 18px;border-radius:12px;background:#eef1fd;border:1px solid rgba(56,88,233,.22);">'
                . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;color:' . self::BRAND . ';">POZNÁMKA OD STUDIA</div>'
                . '<div style="font-size:14px;line-height:1.55;color:' . self::TEXT . ';margin-top:6px;">' . nl2br($e($note)) . '</div>'
                . '</td></tr></table></td></tr>';
        }

        // Co jsme pro vás udělali.
        if ($show('updates') && $summary['done'] !== []) {
            $items = '';

            foreach ($summary['done'] as $item) {
                $items .= '<tr><td width="18" valign="top" style="padding:0 11px 9px 0;">'
                    . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="18" height="18" align="center" bgcolor="' . self::OK . '" style="width:18px;height:18px;border-radius:999px;background:' . self::OK . ';color:#ffffff;font-size:12px;font-weight:700;line-height:18px;">&#10003;</td></tr></table></td>'
                    . '<td valign="top" style="padding:0 0 9px;font-size:14px;line-height:1.5;color:' . self::BODY . ';"><b style="font-weight:600;color:' . self::TEXT . ';">' . $e($item['strong']) . '</b> ' . $e($item['text']) . '</td></tr>';
            }

            $h .= $row('updates') . '<td style="padding:26px ' . $pad . ' 0;">'
                . '<h3 style="margin:0 0 12px;font-size:17px;font-weight:600;letter-spacing:-.01em;color:' . self::TEXT . ';">Co jsme pro vás udělali</h3>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $items . '</table></td></tr>';
        }

        // Servis.
        if ($show('services') && ($summary['services'] !== [] || $summary['nextService'] !== null)) {
            $rows = '';
            $count = count($summary['services']);

            foreach ($summary['services'] as $i => $service) {
                $rows .= '<tr><td style="padding:14px 16px;' . ($i < $count - 1 ? 'border-bottom:1px solid ' . self::BORDER . ';' : '') . '">'
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                    . '<td valign="top" style="font-size:14px;color:' . self::BODY . ';line-height:1.5;">'
                    . '<div style="font-weight:600;color:' . self::TEXT . ';">' . $e($service['kindLabel']) . ' <span style="font-weight:400;color:' . self::MUTED . ';">· ' . $e(get_czech_date($service['date'])) . '</span></div>'
                    . self::serviceBody($service, $e) . '</td>'
                    . ($service['minutes'] !== null ? '<td valign="top" align="right" width="70" style="font-size:12px;color:' . self::MUTED . ';white-space:nowrap;padding-left:12px;">' . $e(ReportBuilder::minutes((int) $service['minutes'])) . '</td>' : '')
                    . '</tr></table></td></tr>';
            }

            $h .= $row('services') . '<td style="padding:26px ' . $pad . ' 0;">'
                . '<h3 style="margin:0 0 12px;font-size:17px;font-weight:600;letter-spacing:-.01em;color:' . self::TEXT . ';">Servis v tomto období</h3>'
                . ($rows !== ''
                    ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . self::BORDER . ';border-radius:12px;border-collapse:separate;">' . $rows . '</table>'
                    : '<div style="font-size:14px;color:' . self::BODY . ';line-height:1.55;">V tomto období nebyl plánovaný žádný servis — web jsme jen průběžně hlídali.</div>')
                . ($summary['nextService'] !== null
                    ? '<div style="font-size:13px;color:' . self::MUTED . ';line-height:1.55;margin-top:10px;">Příští ' . $e(mb_strtolower($summary['nextService']['kindLabel'])) . ' máme naplánovaný na ' . $e(ReportBuilder::longDate($summary['nextService']['date'])) . '. Nemusíte nic dělat, ozveme se, jen kdyby bylo potřeba web na chvíli odstavit.</div>'
                    : '')
                . '</td></tr>';
        }

        // Obsah webu (reporty před verzí 0.4.0 tuhle část souhrnu nemají).
        $content = $summary['content'] ?? null;
        $contactUrl = $options['contactUrl'] ?? ($studio['email'] !== '' ? 'mailto:' . $studio['email'] : '');

        if ($show('content') && is_array($content) && $content['types'] !== []) {
            $rows = '';
            $count = count($content['types']);

            foreach ($content['types'] as $i => $type) {
                $dot = self::TONES[$type['tone']] ?? self::MUTED;
                $rows .= '<tr><td style="padding:12px 16px;' . ($i < $count - 1 ? 'border-bottom:1px solid ' . self::BORDER . ';' : '') . '">'
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                    . '<td valign="top" style="font-size:14px;line-height:1.5;color:' . self::BODY . ';">'
                    . '<div style="font-weight:600;color:' . self::TEXT . ';">' . $e($type['label']) . ' <span style="font-weight:400;color:' . self::MUTED . ';">· ' . $e(get_count((int) $type['published'], 'publikovaný', 'publikované', 'publikovaných')) . '</span></div>'
                    . ($type['latestTitle'] !== '' ? '<div style="margin-top:2px;font-size:13px;color:' . self::MUTED . ';">Poslední: „' . $e(ContentFreshness::title((string) $type['latestTitle'])) . '“</div>' : '')
                    . '</td>'
                    . '<td valign="top" align="right" style="font-size:13px;color:' . self::TEXT . ';white-space:nowrap;padding-left:12px;">'
                    . '<span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:' . $dot . ';margin-right:6px;"></span>' . $e($type['ageLabel'])
                    . '</td></tr></table></td></tr>';
            }

            $invite = '';

            if (($content['invite'] ?? null) !== null) {
                [$background, $border, $title] = $content['tone'] === 'error'
                    ? ['#fdecec', 'rgba(220,38,38,.3)', '#a61b1b']
                    : ['#fef4da', 'rgba(226,144,10,.35)', '#9e5300'];
                $invite = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;"><tr><td style="padding:16px 18px;border-radius:12px;background:' . $background . ';border:1px solid ' . $border . ';">'
                    . '<div style="font-size:14px;font-weight:600;color:' . $title . ';">' . $e($content['invite']['title']) . '</div>'
                    . '<div style="font-size:14px;line-height:1.55;color:' . self::BODY . ';margin-top:5px;">' . $e($content['invite']['text']) . '</div>'
                    . ($contactUrl !== ''
                        ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px;"><tr><td bgcolor="' . self::BRAND . '" style="border-radius:10px;background:' . self::BRAND . ';">'
                            . '<a href="' . $e($contactUrl) . '" style="display:inline-block;color:#ffffff;padding:10px 18px;font-size:14px;font-weight:600;text-decoration:none;">Ozvěte se nám</a>'
                            . '</td></tr></table>'
                        : '')
                    . '</td></tr></table>';
            }

            $h .= $row('content') . '<td style="padding:26px ' . $pad . ' 0;">'
                . '<h3 style="margin:0 0 12px;font-size:17px;font-weight:600;letter-spacing:-.01em;color:' . self::TEXT . ';">Obsah webu</h3>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . self::BORDER . ';border-radius:12px;border-collapse:separate;">' . $rows . '</table>'
                . $invite
                . '</td></tr>';
        }

        // Doporučení.
        if ($show('recommendations') && $summary['recommendations'] !== []) {
            $items = '';

            foreach ($summary['recommendations'] as $rec) {
                $items .= '<div style="font-size:14px;line-height:1.55;color:' . self::BODY . ';margin-top:5px;"><b style="font-weight:600;color:#9e5300;">' . $e($rec['title']) . '.</b> ' . $e($rec['text']) . '</div>';
            }

            $h .= $row('recommendations') . '<td style="padding:24px ' . $pad . ' 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:16px 18px;border-radius:12px;background:#fef4da;border:1px solid rgba(226,144,10,.35);">'
                . '<div style="font-size:14px;font-weight:600;color:#9e5300;">Na co bychom se rádi domluvili</div>' . $items
                . '</td></tr></table></td></tr>';
        }

        // Graf dostupnosti.
        if ($show('uptime_chart') && $uptime['days'] !== []) {
            $cells = '';
            $dayCount = count($uptime['days']);

            foreach ($uptime['days'] as $day) {
                [$color, $height] = match ($day['tone']) {
                    'error' => ['#dc2626', 38],
                    'warning' => ['#e2900a', 66],
                    'none' => ['#e7e9f2', 100],
                    default => [self::OK, 100],
                };
                $cells .= '<td valign="bottom" style="padding:0 1px;height:34px;"><div style="background:' . $color . ';border-radius:3px;height:' . (int) round(34 * $height / 100) . 'px;font-size:0;line-height:0;">&nbsp;</div></td>';
            }

            $first = $uptime['days'][0]['day'];
            $middle = $uptime['days'][intdiv($dayCount, 2)]['day'];
            $last = $uptime['days'][$dayCount - 1]['day'];

            $h .= $row('uptime_chart') . '<td style="padding:26px ' . $pad . ' 0;">'
                . '<h3 style="margin:0 0 12px;font-size:17px;font-weight:600;letter-spacing:-.01em;color:' . self::TEXT . ';">Dostupnost ' . $e(str_starts_with($period['phrase'], 'celý ') ? 'v ' . get_czech_month((int) date('n', strtotime($period['from'])), 'locative') : 'v období') . '</h3>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;"><tr>' . $cells . '</tr></table>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:7px;"><tr>'
                . '<td align="left" style="font-size:11px;color:' . self::MUTED . ';">' . $e(date('j. n.', strtotime($first))) . '</td>'
                . '<td align="center" style="font-size:11px;color:' . self::MUTED . ';">' . $e(date('j. n.', strtotime($middle))) . '</td>'
                . '<td align="right" style="font-size:11px;color:' . self::MUTED . ';">' . $e(date('j. n.', strtotime($last))) . '</td></tr></table>'
                . '<div style="font-size:13px;color:' . self::BODY . ';line-height:1.55;margin-top:10px;">' . $e($uptime['note']) . '</div>'
                . '</td></tr>';
        }

        // Technická příloha.
        if ($show('technical')) {
            $tech = $summary['technical'];
            $rows = array_filter([
                'WordPress' => $tech['wp'],
                'PHP' => $tech['php'],
                'Šablona' => $tech['theme'],
                'Doplňky' => $tech['plugins_total'] > 0 ? $tech['plugins_active'] . ' aktivních z ' . $tech['plugins_total'] . ($tech['plugins_updates'] > 0 ? ', ' . $tech['plugins_updates'] . ' čeká na aktualizaci' : '') : '',
                'Certifikát platí do' => $tech['ssl_valid_to'] !== null ? get_czech_date((string) $tech['ssl_valid_to']) : '',
                'Hosting' => $tech['hosting'],
            ], static fn (string $v): bool => $v !== '');

            if ($rows !== []) {
                $items = '';

                foreach ($rows as $label => $value) {
                    $items .= '<tr><td style="padding:6px 0;font-size:13px;color:' . self::MUTED . ';border-bottom:1px solid ' . self::BORDER . ';">' . $e($label) . '</td><td align="right" style="padding:6px 0;font-size:13px;color:' . self::TEXT . ';border-bottom:1px solid ' . self::BORDER . ';font-family:Consolas,\'Courier New\',monospace;">' . $e($value) . '</td></tr>';
                }

                $h .= $row('technical') . '<td style="padding:26px ' . $pad . ' 0;">'
                    . '<h3 style="margin:0 0 8px;font-size:15px;font-weight:600;color:' . self::TEXT . ';">Technická příloha</h3>'
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $items . '</table></td></tr>';
            }
        }

        // Výzva ke kontaktu.
        if ($show('cta')) {
            $h .= $row('cta') . '<td style="padding:26px ' . $pad . ' 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:18px;border-radius:12px;background:#f3f5fc;">'
                . '<div style="font-size:14px;color:' . self::BODY . ';line-height:1.55;">Máte k webu jakýkoli dotaz?</div>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin-top:10px;"><tr><td align="center" bgcolor="' . self::BRAND . '" style="border-radius:10px;background:' . self::BRAND . ';">'
                . '<a href="' . $e($contactUrl !== '' ? $contactUrl : '#') . '" style="display:inline-block;color:#ffffff;padding:11px 20px;font-size:14px;font-weight:600;text-decoration:none;">Napište nám</a>'
                . '</td></tr></table></td></tr></table></td></tr>';
        }

        // Patka.
        $contact = implode(' · ', array_filter([$studio['email'], $studio['phone']]));
        $h .= '<tr><td style="padding:20px ' . $pad . ' 26px;border-top:1px solid ' . self::BORDER . ';background:#fafbfe;">'
            . '<div style="font-size:12px;color:' . self::MUTED . ';line-height:1.6;">' . $e($studio['name']) . ' · správa a údržba webů' . ($contact !== '' ? '<br>' . $e($contact) : '') . '</div>'
            . '<div style="font-size:11px;color:' . self::MUTED . ';margin-top:10px;">Tuto zprávu dostáváte, protože se staráme o váš web. Frekvenci zpráv změníme na požádání.</div>'
            . (($options['pixelUrl'] ?? '') !== '' ? '<img src="' . $e($options['pixelUrl']) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">' : '')
            . '</td></tr>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:\'Instrument Sans\',Arial,Helvetica,sans-serif;color:' . self::TEXT . ';">' . $h . '</table>';
    }

    /**
     * Celý dokument k odeslání (bílý list na světlém podkladu).
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $options viz `body()`
     */
    public function document(array $summary, array $options = []): string
    {
        $title = htmlspecialchars((string) $summary['subject'], ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f5fc;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#f3f5fc" style="background:#f3f5fc;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;border-collapse:separate;"><tr><td bgcolor="#ffffff" style="background:#ffffff;border-radius:14px;overflow:hidden;">'
            . $this->body($summary, $options)
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /**
     * Textová varianta pro klienty bez HTML.
     *
     * @param array<string, mixed> $summary
     * @param array{note?: string, sections?: array<int, string>, studio?: array{name: string, email: string, phone: string}} $options
     */
    public function text(array $summary, array $options = []): string
    {
        $sections = $options['sections'] ?? ReportRepository::defaultSections();
        $studio = $options['studio'] ?? ['name' => 'MEDIAGRAFIK', 'email' => '', 'phone' => ''];
        $lines = [
            mb_strtoupper('Zpráva o vašem webu · ' . $summary['period']['label']),
            $summary['headline'],
            '',
            'Dobrý den, tady je krátký přehled o webu ' . $summary['site']['host'] . ' za období ' . mb_strtolower($summary['period']['label']) . '.',
            '',
            'Dostupnost webu: ' . $summary['uptime']['percentLabel'] . ' (' . $summary['uptime']['downtimeLabel'] . ')',
            'Provedené aktualizace: ' . $summary['updates']['total'],
            'Kontrol webu: ' . $summary['uptime']['checksLabel'] . ' (' . $summary['uptime']['intervalLabel'] . ')',
        ];

        $note = trim((string) ($options['note'] ?? ''));

        if ($note !== '') {
            array_push($lines, '', 'Poznámka od studia: ' . $note);
        }

        if (in_array('updates', $sections, true) && $summary['done'] !== []) {
            array_push($lines, '', 'Co jsme pro vás udělali:');

            foreach ($summary['done'] as $item) {
                $lines[] = '- ' . $item['strong'] . ' ' . $item['text'];
            }
        }

        if (in_array('services', $sections, true) && $summary['services'] !== []) {
            array_push($lines, '', 'Servis v tomto období:');

            foreach ($summary['services'] as $service) {
                if (($service['tasks'] ?? []) === []) {
                    $lines[] = '- ' . $service['kindLabel'] . ' · ' . get_czech_date($service['date']) . ': ' . $service['description'];
                    continue;
                }

                $lines[] = '- ' . $service['kindLabel'] . ' · ' . get_czech_date($service['date']) . ':';

                foreach ($service['tasks'] as $task) {
                    $lines[] = '  ✓ ' . $task;
                }

                if ($service['note'] !== '') {
                    $lines[] = '  ' . $service['note'];
                }
            }
        }

        $content = $summary['content'] ?? null;

        if (in_array('content', $sections, true) && is_array($content) && $content['types'] !== []) {
            array_push($lines, '', 'Obsah webu:');

            foreach ($content['types'] as $type) {
                $lines[] = '- ' . $type['label'] . ' (' . get_count((int) $type['published'], 'publikovaný', 'publikované', 'publikovaných') . '): ' . $type['ageLabel'];
            }

            if (($content['invite'] ?? null) !== null) {
                $lines[] = $content['invite']['title'] . '. ' . $content['invite']['text'];
            }
        }

        if (in_array('recommendations', $sections, true) && $summary['recommendations'] !== []) {
            array_push($lines, '', 'Na co bychom se rádi domluvili:');

            foreach ($summary['recommendations'] as $rec) {
                $lines[] = '- ' . $rec['title'] . '. ' . $rec['text'];
            }
        }

        array_push($lines, '', $summary['uptime']['note'], '', $studio['name'] . ' · správa a údržba webů', implode(' · ', array_filter([$studio['email'], $studio['phone']])));

        return implode("\n", $lines);
    }

    /**
     * Tělo řádku servisu v e-mailu: hotové úkoly pod sebou s fajfkou,
     * pod nimi poznámka. Uložené reporty z doby před úkoly mají jen
     * `description` — ta se vypíše jako dřív.
     *
     * @param array<string, mixed> $service
     * @param callable(string): string $e
     */
    private static function serviceBody(array $service, callable $e): string
    {
        $tasks = (array) ($service['tasks'] ?? []);

        if ($tasks === []) {
            return '<div style="margin-top:3px;">' . $e((string) $service['description']) . '</div>';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:6px;">';

        foreach ($tasks as $task) {
            $html .= '<tr><td valign="top" width="20" style="color:' . self::OK . ';font-weight:600;line-height:1.6;">✓</td>'
                . '<td style="line-height:1.6;">' . $e((string) $task) . '</td></tr>';
        }

        $html .= '</table>';

        if ((string) ($service['note'] ?? '') !== '') {
            $html .= '<div style="margin-top:8px;color:' . self::MUTED . ';">' . nl2br($e((string) $service['note'])) . '</div>';
        }

        return $html;
    }
}
