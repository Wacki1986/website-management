<?php

declare(strict_types=1);

namespace App\Core\Reports;

use App\Core\Settings\Settings;

/**
 * Upravitelné texty klientského reportu (Nastavení → Reporty).
 *
 * E-mail skládá `ReportRenderer`; pevné texty (úvod, nadpisy sekcí, výzva,
 * patička, předmět) si bere odsud. Uložené jsou jen změněné texty — prázdné
 * pole znamená výchozí znění, takže vylepšení výchozích textů v nové verzi
 * aplikace se projeví všude, kde je nikdo nepřepsal.
 *
 * Zástupné značky se při vykreslení nahradí údaji konkrétního reportu.
 */
final class ReportTemplate
{
    /** Značka => co znamená (nápověda ve formuláři). */
    public const PLACEHOLDERS = [
        '{web}' => 'adresa webu (kavarnadobra.cz)',
        '{obdobi}' => 'období reportu (září 2026)',
        '{kdy}' => 'období ve větě (celý září, ve 3. čtvrtletí 2026)',
        '{studio}' => 'jméno odesílatele z Odchozí pošty',
    ];

    /**
     * Pole šablony v pořadí, jak jdou v e-mailu.
     *
     * @var array<string, array{label: string, default: string, rows: int, max: int, hint?: string}>
     */
    public const FIELDS = [
        'subject_ok' => ['label' => 'Předmět — když bylo vše v pořádku', 'default' => 'Váš web {kdy}: vše v pořádku', 'rows' => 1, 'max' => 150],
        'subject' => ['label' => 'Předmět — ostatní případy', 'default' => 'Zpráva o vašem webu — {obdobi}', 'rows' => 1, 'max' => 150, 'hint' => 'Když měl web výpadek nebo čeká na nějaký zásah.'],
        'intro' => ['label' => 'Úvodní odstavec', 'default' => 'Dobrý den, tady je krátký přehled o webu {web} za období {obdobi}. Stará se o něj studio {studio} — všechno níže jsme udělali za vás, nic nemusíte řešit.', 'rows' => 3, 'max' => 600],
        'note_label' => ['label' => 'Nadpis poznámky od studia', 'default' => 'Poznámka od studia', 'rows' => 1, 'max' => 60],
        'heading_updates' => ['label' => 'Nadpis sekce aktualizací', 'default' => 'Co jsme pro vás udělali', 'rows' => 1, 'max' => 80],
        'heading_services' => ['label' => 'Nadpis sekce servisu', 'default' => 'Servis v tomto období', 'rows' => 1, 'max' => 80],
        'heading_content' => ['label' => 'Nadpis sekce obsahu', 'default' => 'Obsah webu', 'rows' => 1, 'max' => 80],
        'content_button' => ['label' => 'Tlačítko u pozvánky k obsahu', 'default' => 'Ozvěte se nám', 'rows' => 1, 'max' => 40],
        'heading_recommendations' => ['label' => 'Nadpis doporučení', 'default' => 'Na co bychom se rádi domluvili', 'rows' => 1, 'max' => 80],
        'cta_text' => ['label' => 'Výzva ke kontaktu', 'default' => 'Máte k webu jakýkoli dotaz?', 'rows' => 2, 'max' => 300],
        'cta_button' => ['label' => 'Tlačítko výzvy', 'default' => 'Napište nám', 'rows' => 1, 'max' => 40],
        'signature' => ['label' => 'Podpis v patičce', 'default' => '{studio} · správa a údržba webů', 'rows' => 1, 'max' => 150],
        'footer' => ['label' => 'Drobný text v patičce', 'default' => 'Tuto zprávu dostáváte, protože se staráme o váš web. Frekvenci zpráv změníme na požádání.', 'rows' => 2, 'max' => 300],
    ];

    /**
     * Posouvatelné sekce e-mailu v základním pořadí. Hlavička, úvod
     * s metrikami a patička stojí vždy na svém místě.
     */
    public const SECTION_ORDER = ['note', 'updates', 'services', 'content', 'recommendations', 'uptime_chart', 'technical', 'cta'];

    /** Popisky sekcí v editoru (poznámka není přepínatelná sekce reportu). */
    public const SECTION_LABELS = ['note' => 'Poznámka od studia'];

    private const SETTING = 'report_template';

    /** Klíč pořadí sekcí v uloženém JSON (vedle přepsaných textů). */
    private const ORDER_KEY = '_order';

    public function __construct(private readonly Settings $settings)
    {
    }

    /** Výchozí texty. @return array<string, string> */
    public static function defaults(): array
    {
        return array_map(static fn (array $field): string => $field['default'], self::FIELDS);
    }

    /** Jen přepsané texty (klíč => text). @return array<string, string> */
    public function custom(): array
    {
        $stored = json_decode($this->settings->get(self::SETTING), true);

        return is_array($stored) ? array_intersect_key(array_map('strval', array_filter($stored, 'is_scalar')), self::FIELDS) : [];
    }

    /** Texty, se kterými se report vykreslí: přepsané, jinak výchozí. @return array<string, string> */
    public function texts(): array
    {
        return array_merge(self::defaults(), $this->custom());
    }

    /** Pořadí sekcí ze šablony (uložené, jinak základní). @return array<int, string> */
    public function order(): array
    {
        $stored = json_decode($this->settings->get(self::SETTING), true);

        return self::normalizeOrder(is_array($stored) ? (array) ($stored[self::ORDER_KEY] ?? []) : []);
    }

    public function hasCustomOrder(): bool
    {
        return $this->order() !== self::SECTION_ORDER;
    }

    /**
     * Známé sekce v zadaném pořadí, chybějící na konec v základním pořadí.
     *
     * @param array<int, mixed> $order
     * @return array<int, string>
     */
    public static function normalizeOrder(array $order): array
    {
        $order = array_values(array_intersect(array_map('strval', $order), self::SECTION_ORDER));

        return array_values(array_unique(array_merge($order, self::SECTION_ORDER)));
    }

    /**
     * Uložit z formuláře. Prázdné pole nebo text shodný s výchozím se
     * neukládá (= výchozí znění); pořadí sekcí (`section_order`, klíče
     * oddělené čárkou) jen, když se liší od základního.
     *
     * @param array<string, mixed> $input
     * @return int kolik textů se liší od výchozích (+1 za změněné pořadí)
     */
    public function save(array $input): int
    {
        $custom = [];

        foreach (self::FIELDS as $key => $field) {
            $value = trim(str_replace("\r\n", "\n", (string) ($input[$key] ?? '')));
            $value = mb_substr($field['rows'] === 1 ? preg_replace('/\s+/u', ' ', $value) : $value, 0, $field['max']);

            if ($value !== '' && $value !== $field['default']) {
                $custom[$key] = $value;
            }
        }

        $changed = count($custom);
        $order = self::normalizeOrder(explode(',', (string) ($input['section_order'] ?? '')));

        if ($order !== self::SECTION_ORDER) {
            $custom[self::ORDER_KEY] = $order;
            $changed++;
        }

        $this->settings->set(self::SETTING, $custom !== [] ? (string) json_encode($custom, JSON_UNESCAPED_UNICODE) : '');

        return $changed;
    }

    /**
     * Hodnoty zástupných značek pro konkrétní report.
     *
     * @param array<string, mixed> $summary z `ReportBuilder::build()`
     * @return array<string, string>
     */
    public static function values(array $summary, string $studio): array
    {
        return [
            '{web}' => (string) ($summary['site']['host'] ?? ''),
            '{obdobi}' => mb_strtolower((string) ($summary['period']['label'] ?? '')),
            '{kdy}' => (string) ($summary['period']['phrase'] ?? ''),
            '{studio}' => $studio,
        ];
    }

    /** @param array<string, string> $values z `values()` */
    public static function fill(string $text, array $values): string
    {
        return strtr($text, $values);
    }

    /**
     * Vzorový report pro editor šablony: všechny sekce vyplněné, ať jde
     * upravit každý nadpis (skutečný poslední report nemusí mít servis,
     * doporučení ani pozvánku k obsahu). Data jsou smyšlená, jen na ukázku.
     *
     * @return array<string, mixed> stejná struktura jako `ReportBuilder::build()`
     */
    public static function sampleSummary(?int $now = null): array
    {
        $now ??= time();
        $from = date('Y-m-01', strtotime('first day of last month', $now));
        $to = date('Y-m-t', strtotime($from));
        $month = (int) date('n', strtotime($from));
        $label = mb_convert_case(get_czech_month($month), MB_CASE_TITLE) . ' ' . date('Y', strtotime($from));
        $days = [];

        for ($day = strtotime($from); $day <= strtotime($to); $day += 86400) {
            $days[] = ['day' => date('Y-m-d', $day), 'tone' => date('j', $day) === '12' ? 'warning' : 'ok'];
        }

        return [
            'period' => ['from' => $from, 'to' => $to, 'label' => $label, 'phrase' => 'celý ' . get_czech_month($month), 'days' => count($days)],
            'site' => ['name' => 'Kavárna Dobrá', 'host' => 'kavarnadobra.cz', 'url' => 'https://kavarnadobra.cz', 'client' => 'Kavárna Dobrá s.r.o.'],
            'headline' => 'Váš web běžel celý ' . get_czech_month($month) . ' bez vážného problému',
            'subject' => '',
            'allGood' => true,
            'uptime' => [
                'percent' => 99.9, 'percentLabel' => '99,9 %', 'downtime_min' => 15, 'downtimeLabel' => '15 min mimo provoz',
                'checks' => 2880, 'checksLabel' => '2 880', 'intervalLabel' => 'každých 15 minut', 'outages' => [], 'days' => $days,
                'note' => 'Web byl nedostupný celkem 15 min, z toho nejdéle 12. ' . $month . '. (15 min). Ve zbytku období běžel bez problému.',
            ],
            'updates' => ['total' => 6, 'core' => ['6.9'], 'plugins' => [], 'installed' => [], 'noteLabel' => 'včetně WordPressu'],
            'done' => [
                ['strong' => 'WordPress', 'text' => 'jsme aktualizovali na verzi 6.9.'],
                ['strong' => '5 doplňků', 'text' => 'dostalo novou verzi — mimo jiné kontaktní formulář a zálohování.'],
                ['strong' => 'Zálohy', 'text' => 'běžely každý den, poslední je z konce měsíce.'],
            ],
            'content' => [
                'types' => [
                    ['label' => 'Příspěvky', 'published' => 24, 'latestTitle' => 'Nové menu na podzim', 'days' => 75, 'tone' => 'warning', 'ageLabel' => 'naposledy před 2 měsíci'],
                    ['label' => 'Stránky', 'published' => 8, 'latestTitle' => 'Kontakt', 'days' => 200, 'tone' => 'error', 'ageLabel' => 'naposledy před půl rokem'],
                ],
                'freshestDays' => 75,
                'tone' => 'warning',
                'invite' => ['title' => 'Web by si zasloužil něco nového', 'text' => 'Poslední obsah na webu přibyl před 75 dny. Ozvěte se — rádi s vámi projdeme nápady, texty nebo fotky.'],
            ],
            'services' => [[
                'date' => date('Y-m-20', strtotime($from)), 'kind' => 'small', 'kindLabel' => 'Malý servis',
                'description' => '', 'tasks' => ['Aktualizace WordPressu', 'Aktualizace pluginů a šablony', 'Kontrola zálohy'],
                'note' => 'Doporučujeme obnovit fotografie v galerii.', 'minutes' => 45,
            ]],
            'nextService' => ['date' => date('Y-m-20', strtotime('+1 month', strtotime($from))), 'kindLabel' => 'Malý servis'],
            'recommendations' => [['title' => 'Novější verze PHP', 'text' => 'Hosting běží na PHP 8.2, kterému brzy končí podpora — přechod zařídíme s hostingem.']],
            'technical' => [
                'wp' => '6.9', 'php' => '8.2.28', 'theme' => 'Kavárna', 'plugins_total' => 18, 'plugins_active' => 17,
                'plugins_updates' => 0, 'ssl_valid_to' => date('Y-m-d', strtotime('+70 days', $now)), 'hosting' => '',
            ],
            'summaryLine' => '',
        ];
    }

    /**
     * Předmět e-mailu podle šablony.
     *
     * @param array<string, mixed>  $summary
     * @param array<string, string> $texts z `texts()`
     */
    public static function subject(array $summary, array $texts, string $studio): string
    {
        $key = !empty($summary['allGood']) ? 'subject_ok' : 'subject';

        return self::fill($texts[$key] ?? self::FIELDS[$key]['default'], self::values($summary, $studio));
    }
}
