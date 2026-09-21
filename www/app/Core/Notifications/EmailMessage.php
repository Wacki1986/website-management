<?php

declare(strict_types=1);

namespace App\Core\Notifications;

/**
 * Stavebnice e-mailu podle návrhu „E-maily v2".
 *
 * Doslovná kopie z jádra klientské aplikace (`Core\Notifications\EmailMessage`)
 * — obě strany posílají stejné e-maily; případné úpravy dělat v obou.
 *
 * E-mail se skládá z typovaných částí (nadpis, odstavce, tlačítko, info box,
 * citace zprávy, drobný text) a z **téže struktury** se vykreslí obě podoby:
 * HTML karta design systému i prostý text pro klienty bez HTML a pro `.eml`
 * logy. Odesílatel tak popisuje, CO e-mail říká, a o vzhled se nestará —
 * a obě varianty se nemůžou rozejít.
 *
 * Vzhled (návrh „E-maily v2.dc.html"): bílá karta na pískovém podkladu,
 * hlavička s logem a volitelnou stavovou pilulkou, žluté tlačítko přes celou
 * šířku se záložním odkazem pod ním, patka „automatická zpráva". Barvy jsou
 * natvrdo světlé tokeny design systému — e-mail nemá odkud vzít CSS proměnné.
 */
final class EmailMessage
{
    /** tóny pilulky v hlavičce => [pozadí, text] (světlé tokeny) */
    private const PILL_TONES = [
        'success' => ['#e3f3e7', '#1f7a35'],
        'warning' => ['#fcecd8', '#a35a00'],
        'danger' => ['#fbe7e7', '#c22f2f'],
    ];

    /** @var array<int, array<string, mixed>> části v pořadí vykreslení */
    private array $parts = [];

    /** @var array{0: string, 1: string}|null [text, tón] */
    private ?array $pill = null;

    private string $footerReason = '';

    private function __construct(private readonly string $heading)
    {
    }

    public static function make(string $heading): self
    {
        return new self($heading);
    }

    /** Běžný odstavec (14 px, sekundární barva). */
    public function paragraph(string $text): self
    {
        $this->parts[] = ['type' => 'paragraph', 'text' => $text];

        return $this;
    }

    /**
     * Žluté tlačítko přes celou šířku + záložní odkaz pod ním — e-mailoví
     * klienti tlačítka občas rozbijí (návrh). V textové podobě „Popisek: URL".
     */
    public function button(string $label, string $url): self
    {
        $this->parts[] = ['type' => 'button', 'label' => $label, 'url' => $url];

        return $this;
    }

    /**
     * Box s parametry (Tarif · Vypršelo · …): popisek verzálkami, hodnota
     * tučně. V textu řádky „Popisek: hodnota".
     *
     * @param array<string, string> $rows popisek => hodnota
     */
    public function infoBox(array $rows): self
    {
        $this->parts[] = ['type' => 'infobox', 'rows' => $rows];

        return $this;
    }

    /**
     * Citovaná zpráva (odpověď podpory): hlavička s iniciálami, jménem a rolí,
     * čas vpravo, pod tím text — příjemce si přečte odpověď rovnou v e-mailu.
     */
    public function quote(string $author, string $role, string $when, string $text): self
    {
        $this->parts[] = ['type' => 'quote', 'author' => $author, 'role' => $role, 'when' => $when, 'text' => $text];

        return $this;
    }

    /** Drobný text (platnost odkazu, „pokud jste nežádali…"). */
    public function smallprint(string $text): self
    {
        $this->parts[] = ['type' => 'smallprint', 'text' => $text];

        return $this;
    }

    /** Stavová pilulka v hlavičce vedle loga. @param 'success'|'warning'|'danger' $tone */
    public function pill(string $text, string $tone): self
    {
        $this->pill = [$text, isset(self::PILL_TONES[$tone]) ? $tone : 'warning'];

        return $this;
    }

    /** První věta patky — proč e-mail přišel („Posíláme správcům instance."). */
    public function footerReason(string $text): self
    {
        $this->footerReason = $text;

        return $this;
    }

    // --- prostý text --------------------------------------------------------

    /** Textová varianta — primární obsah pro klienty bez HTML i pro logy. */
    public function toText(): string
    {
        $lines = [$this->heading];

        if ($this->pill !== null) {
            $lines[0] .= ' [' . $this->pill[0] . ']';
        }

        foreach ($this->parts as $part) {
            $lines[] = '';

            switch ($part['type']) {
                case 'paragraph':
                case 'smallprint':
                    $lines[] = (string) $part['text'];
                    break;

                case 'button':
                    $lines[] = $part['label'] . ': ' . $part['url'];
                    break;

                case 'infobox':
                    foreach ($part['rows'] as $label => $value) {
                        $lines[] = $label . ': ' . $value;
                    }
                    break;

                case 'quote':
                    $lines[] = $part['author']
                        . ($part['role'] !== '' ? ' (' . $part['role'] . ')' : '')
                        . ($part['when'] !== '' ? ' · ' . $part['when'] : '') . ':';
                    $lines[] = $part['text'];
                    break;
            }
        }

        if ($this->footerReason !== '') {
            $lines[] = '';
            $lines[] = $this->footerReason;
        }

        return implode("\n", $lines);
    }

    // --- HTML ---------------------------------------------------------------

    /**
     * HTML karta podle návrhu.
     *
     * @param string $brand   jméno odesílatele (patka; hlavička bez loga)
     * @param string $logoUrl absolutní adresa loga; prázdná = jméno textem
     */
    public function toHtml(string $brand, string $logoUrl = ''): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $safeBrand = $e($brand);

        $logo = $logoUrl !== ''
            ? '<img src="' . $e($logoUrl) . '" alt="' . $safeBrand . '" height="28"'
                . ' style="display:block;height:28px;width:auto;border:0;">'
            : '<span style="font-size:18px;font-weight:bold;letter-spacing:.03em;color:#1b1b1c;">' . $safeBrand . '</span>';

        $pill = '';
        if ($this->pill !== null) {
            [$bg, $fg] = self::PILL_TONES[$this->pill[1]];
            // Výplň a podklad na <td> — padding na <span> Outlook zahazuje.
            $pill = '<td align="right" style="padding:20px 28px 20px 0;border-bottom:1px solid #e3e3de;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" align="right"><tr>'
                . '<td bgcolor="' . $bg . '" style="border-radius:999px;padding:3px 10px;font-size:12px;'
                . 'font-weight:bold;color:' . $fg . ';font-family:Arial,Helvetica,sans-serif;">'
                . $e($this->pill[0]) . '</td></tr></table></td>';
        }

        $blocks = '';
        foreach ($this->parts as $part) {
            $blocks .= match ($part['type']) {
                'paragraph' => '<p style="margin:0 0 14px;color:#61616a;font-size:14px;line-height:1.55;">'
                    . $e((string) $part['text']) . '</p>',

                'smallprint' => '<p style="margin:0 0 14px;color:#9a9aa0;font-size:12.5px;line-height:1.5;">'
                    . $e((string) $part['text']) . '</p>',

                /**
                 * „Bulletproof" tlačítko: podklad a výplň nese <td>
                 * (bgcolor + padding), ne <a> — Outlook na Windows kreslí
                 * e-maily Wordem a padding i display na odkazu zahazuje,
                 * takže z CSS tlačítka zbyl jen žlutě podbarvený text
                 * (nález z provozu). Zaoblení Word neumí — hranaté rohy
                 * jsou přijatelná degradace.
                 */
                'button' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 12px;"><tr>'
                    . '<td align="center" bgcolor="#fdc300" style="border-radius:10px;padding:13px 16px;">'
                    . '<a href="' . $e((string) $part['url']) . '" style="display:block;color:#1b1b1c;'
                    . 'text-decoration:none;font-weight:bold;font-size:15px;">' . $e((string) $part['label']) . '</a>'
                    . '</td></tr></table>'
                    . '<p style="margin:0 0 14px;color:#9a9aa0;font-size:12.5px;line-height:1.5;">'
                    . 'Pokud tlačítko nefunguje, zkopírujte do prohlížeče tento odkaz:<br>'
                    . '<span style="font-family:Consolas,\'Courier New\',monospace;font-size:11.5px;color:#61616a;'
                    . 'word-break:break-all;">' . $e((string) $part['url']) . '</span></p>',

                'infobox' => $this->renderInfoBox($part['rows'], $e),

                'quote' => $this->renderQuote($part, $e),

                default => '',
            };
        }

        $footer = ($this->footerReason !== '' ? $e($this->footerReason) . '<br>' : '')
            . $safeBrand . ' · automatická zpráva, neodpovídejte na ni';

        return <<<HTML
        <!doctype html>
        <html lang="cs">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
        <body style="margin:0;padding:0;background:#f2f2ef;font-family:Arial,Helvetica,sans-serif;">
        <!-- bgcolor jako atribut: Outlook (Word engine) CSS pozadí na body i tabulce zahazuje -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#f2f2ef" style="background:#f2f2ef;"><tr><td align="center" style="padding:28px 12px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;border-collapse:separate;">
        <tr><td bgcolor="#ffffff" style="background:#ffffff;border:1px solid #e3e3de;border-radius:14px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr><td style="padding:20px 28px;border-bottom:1px solid #e3e3de;">{$logo}</td>{$pill}</tr>
        <tr><td colspan="2" style="padding:26px 28px 14px;">
        <h1 style="margin:0 0 14px;color:#1b1b1c;font-size:19px;font-weight:bold;line-height:1.3;">{$this->escapedHeading()}</h1>
        {$blocks}
        </td></tr>
        <tr><td colspan="2" style="padding:14px 28px;border-top:1px solid #e3e3de;color:#9a9aa0;font-size:12px;line-height:1.5;">{$footer}</td></tr>
        </table>
        </td></tr>
        </table>
        </td></tr></table>
        </body>
        </html>
        HTML;
    }

    private function escapedHeading(): string
    {
        return htmlspecialchars($this->heading, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, string> $rows
     * @param callable(string): string $e
     */
    private function renderInfoBox(array $rows, callable $e): string
    {
        $cells = '';
        $index = 0;
        $count = count($rows);

        foreach ($rows as $label => $value) {
            // Dvě buňky na řádek; lichá poslední jde přes celou šířku.
            if ($index % 2 === 0) {
                $cells .= '<tr>';
            }

            $span = ($index === $count - 1 && $index % 2 === 0) ? ' colspan="2"' : '';
            $cells .= '<td' . $span . ' width="50%" style="padding:7px 8px;vertical-align:top;">'
                . '<div style="font-size:11px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#9a9aa0;">'
                . $e((string) $label) . '</div>'
                . '<div style="font-size:13px;font-weight:bold;color:#1b1b1c;margin-top:1px;">' . $e((string) $value) . '</div></td>';

            if ($index % 2 === 1 || $index === $count - 1) {
                $cells .= '</tr>';
            }

            $index++;
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' style="border:1px solid #e3e3de;border-radius:12px;border-collapse:separate;margin:0 0 14px;padding:7px 8px;">'
            . $cells . '</table>';
    }

    /**
     * @param array<string, mixed> $part
     * @param callable(string): string $e
     */
    private function renderQuote(array $part, callable $e): string
    {
        $initials = '';
        foreach (array_slice(preg_split('/\s+/', trim((string) $part['author'])) ?: [], 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' style="border:1px solid #e3e3de;border-radius:12px;border-collapse:separate;margin:0 0 14px;">'
            . '<tr><td style="padding:11px 16px;border-bottom:1px solid #e3e3de;background:#f6f6f3;border-radius:12px 12px 0 0;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td width="34" style="vertical-align:middle;"><span style="display:inline-block;width:26px;height:26px;'
            . 'border-radius:999px;background:#fdf3d0;color:#1b1b1c;font-size:11.5px;font-weight:bold;text-align:center;'
            . 'line-height:26px;">' . $e($initials !== '' ? $initials : '?') . '</span></td>'
            . '<td style="vertical-align:middle;"><span style="font-weight:bold;font-size:13px;color:#1b1b1c;">'
            . $e((string) $part['author']) . '</span>'
            . ((string) $part['role'] !== ''
                ? '<br><span style="font-size:11.5px;color:#9a9aa0;">' . $e((string) $part['role']) . '</span>'
                : '')
            . '</td>'
            . '<td align="right" style="vertical-align:middle;font-family:Consolas,\'Courier New\',monospace;'
            . 'font-size:11.5px;color:#9a9aa0;">' . $e((string) $part['when']) . '</td>'
            . '</tr></table></td></tr>'
            . '<tr><td style="padding:14px 16px;font-size:13.5px;line-height:1.6;color:#61616a;">'
            . nl2br($e((string) $part['text'])) . '</td></tr>'
            . '</table>';
    }
}
