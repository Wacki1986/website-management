<?php

declare(strict_types=1);

namespace App\Core\Monitor;

/**
 * DNS pošty domény: kam pošta chodí (MX) a jestli doména říká, kdo za ni
 * smí odesílat (SPF, DKIM, DMARC). Bez SPF a DMARC končí e-maily klienta
 * ve spamu a Gmail s Yahoo hromadnou poštu bez DMARC odmítají.
 *
 * DKIM klíč leží pod selektorem, který zvenku nejde vyčíst — zkoušejí se
 * obvyklé (`KNOWN_SELECTORS`). Nenalezený DKIM proto není chyba, jen
 * „nezjištěno" (třeba pošta na WEDOS svůj selektor neprozradí).
 *
 * Výsledek je obyčejné pole (ukládá se jako JSON u domény), stavy pro
 * obrazovky počítá `summary()` bez sítě.
 */
final class MailDnsCheck
{
    /**
     * Obvyklé DKIM selektory, v pořadí podle četnosti u českých klientů:
     * Google, Microsoft 365 (i Active24), Seznam, Forpsi, Mailchimp/Mandrill,
     * cPanel a další hostingy. Hledá se do prvního nálezu.
     */
    public const KNOWN_SELECTORS = ['google', 'selector1', 'selector2', 'szn1', 'szn2', 'key1', 'k1', 'k2', 'default', 'mail', 'dkim', 's1', 's2', 'zoho', 'fm1'];

    /** @var array<string, array{tone: string, short: string, text: string}> problém => tón, krátký popisek, vysvětlení */
    public const ISSUES = [
        'no_mx' => ['tone' => 'warning', 'short' => 'chybí MX', 'text' => 'Doména nemá MX záznam — pošta na ni nedojde. V pořádku jen u domény bez e-mailu.'],
        'multi_spf' => ['tone' => 'error', 'short' => 'dva SPF záznamy', 'text' => 'Doména má víc SPF záznamů a podle normy pak neplatí žádný. Sloučte je do jednoho.'],
        'spf_all' => ['tone' => 'error', 'short' => 'SPF povoluje všechny', 'text' => 'SPF končí „+all" — za doménu tak smí odesílat kdokoli.'],
        'no_spf' => ['tone' => 'warning', 'short' => 'chybí SPF', 'text' => 'Bez SPF záznamu končí odeslané e-maily častěji ve spamu.'],
        'no_dmarc' => ['tone' => 'warning', 'short' => 'chybí DMARC', 'text' => 'Bez DMARC záznamu Gmail a Yahoo hromadné e-maily odmítají a doménu jde snáz zneužít k podvodům.'],
    ];

    /** @param callable|null $resolver pro testy: fn(string $host, string $type): ?array — MX cíle / TXT texty, null = DNS neodpověděl */
    public function __construct(private $resolver = null)
    {
    }

    /**
     * @return array{ok: bool, error: ?string, mx: array<int, string>, spf: ?string, dmarc: ?string, dmarc_policy: ?string,
     *               dkim: ?string, issues: array<int, string>}
     */
    public function check(string $domain): array
    {
        $result = ['ok' => false, 'error' => null, 'mx' => [], 'spf' => null, 'dmarc' => null, 'dmarc_policy' => null,
            'dkim' => null, 'issues' => []];
        $mx = $this->lookup($domain, 'MX');

        if ($mx === null) {
            return ['error' => 'DNS neodpověděl.'] + $result;
        }

        $spf = array_values(array_filter($this->lookup($domain, 'TXT') ?? [], static fn (string $txt): bool => stripos(ltrim($txt), 'v=spf1') === 0));
        $dmarc = null;

        foreach ($this->lookup('_dmarc.' . $domain, 'TXT') ?? [] as $txt) {
            if (stripos(ltrim($txt), 'v=DMARC1') === 0) {
                $dmarc = $txt;
                break;
            }
        }

        $dkim = null;

        foreach (self::KNOWN_SELECTORS as $candidate) {
            foreach ($this->lookup($candidate . '._domainkey.' . $domain, 'TXT') ?? [] as $txt) {
                if (stripos($txt, 'v=DKIM1') !== false || preg_match('/(^|;)\s*p=\S/', $txt) === 1) {
                    $dkim = $candidate;
                    break 2;
                }
            }
        }

        $issues = [];

        // Doména bez pošty: SPF/DMARC by byly jen šum, stačí upozornit na MX.
        if ($mx === []) {
            $issues[] = 'no_mx';
        } else {
            if (count($spf) > 1) {
                $issues[] = 'multi_spf';
            } elseif ($spf !== [] && preg_match('/\+all\b/i', $spf[0]) === 1) {
                $issues[] = 'spf_all';
            } elseif ($spf === []) {
                $issues[] = 'no_spf';
            }

            if ($dmarc === null) {
                $issues[] = 'no_dmarc';
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'mx' => $mx,
            'spf' => $spf[0] ?? null,
            'dmarc' => $dmarc,
            'dmarc_policy' => $dmarc !== null && preg_match('/\bp=(none|quarantine|reject)\b/i', $dmarc, $m) === 1 ? strtolower($m[1]) : null,
            'dkim' => $dkim,
            'issues' => $issues,
        ];
    }

    /**
     * Stav do řádku domény: tečka + text. Nejzávažnější problém vyhrává.
     *
     * @param array<string, mixed>|null $result uložený výsledek (null = zatím nekontrolováno)
     * @return array{tone: string, label: string}
     */
    public static function summary(?array $result): array
    {
        if ($result === null) {
            return ['tone' => 'muted', 'label' => 'pošta: zatím nekontrolováno'];
        }

        if (!($result['ok'] ?? false)) {
            return ['tone' => 'muted', 'label' => 'pošta: DNS neodpověděl'];
        }

        $issues = array_values(array_filter((array) ($result['issues'] ?? []), static fn ($key): bool => isset(self::ISSUES[$key])));

        if ($issues === []) {
            return ['tone' => 'ok', 'label' => 'pošta v pořádku'];
        }

        usort($issues, static fn (string $a, string $b): int => (self::ISSUES[$a]['tone'] === 'error' ? 0 : 1) <=> (self::ISSUES[$b]['tone'] === 'error' ? 0 : 1));
        $first = self::ISSUES[$issues[0]];

        return ['tone' => $first['tone'], 'label' => 'pošta: ' . $first['short'] . (count($issues) > 1 ? ' +' . (count($issues) - 1) : '')];
    }

    /**
     * Otisk toho, co se smí měnit jen vědomě — MX, SPF, DMARC a problémy.
     * Jiný otisk než minule = upozornění (přesun pošty, přepsaný SPF…).
     *
     * @param array<string, mixed> $result
     */
    public static function fingerprint(array $result): string
    {
        $mx = (array) ($result['mx'] ?? []);
        sort($mx);

        return md5(json_encode([$mx, $result['spf'] ?? null, $result['dmarc'] ?? null, (array) ($result['issues'] ?? [])]) ?: '');
    }

    /** @return array<int, string>|null MX cíle podle priority / TXT texty; null = DNS neodpověděl */
    private function lookup(string $host, string $type): ?array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host, $type);
        }

        $records = @dns_get_record($host, $type === 'MX' ? DNS_MX : DNS_TXT);

        if ($records === false) {
            return null;
        }

        if ($type === 'MX') {
            usort($records, static fn (array $a, array $b): int => (int) ($a['pri'] ?? 0) <=> (int) ($b['pri'] ?? 0));

            return array_values(array_map(static fn (array $r): string => strtolower(rtrim((string) ($r['target'] ?? ''), '.')), $records));
        }

        // Dlouhý TXT (DKIM klíč) přijde rozdělený v `entries` — složit.
        return array_values(array_map(static fn (array $r): string => isset($r['entries']) ? implode('', (array) $r['entries']) : (string) ($r['txt'] ?? ''), $records));
    }
}
