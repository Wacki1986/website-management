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

    private const SETTING = 'report_template';

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

    /**
     * Uložit z formuláře. Prázdné pole nebo text shodný s výchozím se
     * neukládá (= výchozí znění).
     *
     * @param array<string, mixed> $input
     * @return int kolik textů se liší od výchozích
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

        $this->settings->set(self::SETTING, $custom !== [] ? (string) json_encode($custom, JSON_UNESCAPED_UNICODE) : '');

        return count($custom);
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
