<?php

declare(strict_types=1);

namespace App\Core\Service;

use App\Core\Settings\Settings;

/**
 * Seznam úkolů ke každému druhu servisu (Nastavení → Servis) a checklist
 * u konkrétního zápisu servisu.
 *
 * Seznam se ukládá v `settings` jako text „úkol na řádek" — přesně tak,
 * jak se v nastavení píše. Zápis servisu si při uložení vezme **kopii**
 * seznamu i s odškrtnutím (`service_logs.checklist`), takže pozdější
 * úprava seznamu starý zápis nezmění.
 *
 * K seznamu jde u zápisu přidat vlastní úkoly („Oprava formuláře
 * poptávky“) — v checklistu mají `extra: true`, ve formuláři se dají
 * přepsat i odebrat; úkoly ze seznamu jen odškrtnout.
 */
final class ServiceChecklists
{
    /** Výchozí seznamy — odpovídají popisům druhů v `ServiceSchedule::KINDS`. */
    public const DEFAULTS = [
        'small' => [
            'Aktualizace WordPressu',
            'Aktualizace pluginů a šablony',
            'Kontrola zálohy',
            'Kontrola kontaktních formulářů',
        ],
        'medium' => [
            'Aktualizace WordPressu',
            'Aktualizace pluginů a šablony',
            'Kontrola zálohy',
            'Kontrola kontaktních formulářů',
            'Kontrola zabezpečení',
            'Čištění databáze',
            'Čištění knihovny médií',
        ],
        'large' => [
            'Aktualizace WordPressu',
            'Aktualizace pluginů a šablony',
            'Kontrola zálohy',
            'Kontrola kontaktních formulářů',
            'Kontrola zabezpečení',
            'Čištění databáze',
            'Čištění knihovny médií',
            'Optimalizace rychlosti',
            'Revize obsahu',
            'Kontrola SEO',
        ],
    ];

    public const MAX_ITEMS = 30;
    public const MAX_LENGTH = 150;
    public const MAX_EXTRA = 20;

    private const KEY = 'service_checklist_';

    /** Hodnota, kterou `Settings::get()` vrátí, když seznam nikdo neuložil (prázdný seznam je platná volba). */
    private const UNSET = "\0";

    public function __construct(private readonly Settings $settings)
    {
    }

    /** Seznam úkolů druhu servisu. @return array<int, string> */
    public function template(string $kind): array
    {
        $stored = $this->settings->get(self::KEY . $kind, self::UNSET);

        return $stored === self::UNSET ? (self::DEFAULTS[$kind] ?? []) : self::parse($stored);
    }

    /** @return array<string, array<int, string>> druh => seznam, pro všechny druhy */
    public function all(): array
    {
        $all = [];

        foreach (array_keys(ServiceSchedule::KINDS) as $kind) {
            $all[$kind] = $this->template($kind);
        }

        return $all;
    }

    /** Uloží seznam z textu „úkol na řádek". @return array<int, string> uložený seznam */
    public function save(string $kind, string $text): array
    {
        $items = self::parse($text);
        $this->settings->set(self::KEY . $kind, implode("\n", $items));

        return $items;
    }

    /**
     * Text z nastavení → seznam: bez prázdných řádků a opakování, zkrácené
     * položky, nejvýš `MAX_ITEMS`.
     *
     * @return array<int, string>
     */
    public static function parse(string $text): array
    {
        $items = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = mb_substr(trim($line), 0, self::MAX_LENGTH);

            if ($line !== '' && !in_array($line, $items, true)) {
                $items[] = $line;
            }
        }

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    // -----------------------------------------------------------------
    // Checklist konkrétního zápisu
    // -----------------------------------------------------------------

    /**
     * @param array<int, string> $labels úkoly
     * @param array<int, mixed>  $done   indexy odškrtnutých (z formuláře)
     * @return array<int, array{label: string, done: bool, extra: bool}>
     */
    public static function build(array $labels, array $done): array
    {
        $done = array_map('intval', array_filter($done, 'is_numeric'));
        $items = [];

        foreach (array_values($labels) as $i => $label) {
            $items[] = ['label' => $label, 'done' => in_array($i, $done, true), 'extra' => false];
        }

        return $items;
    }

    /**
     * Vlastní úkoly z formuláře: `extra[][label]`, `extra[][done]`.
     * Prázdný text = odebraný úkol (tak se odebírá i bez skriptu).
     *
     * @param array<int|string, mixed> $posted
     * @return array<int, array{label: string, done: bool, extra: bool}>
     */
    public static function extras(array $posted): array
    {
        $items = [];

        foreach ($posted as $row) {
            $label = is_array($row) ? mb_substr(trim((string) ($row['label'] ?? '')), 0, self::MAX_LENGTH) : '';

            if ($label !== '') {
                $items[] = ['label' => $label, 'done' => !empty($row['done']), 'extra' => true];
            }
        }

        return array_slice($items, 0, self::MAX_EXTRA);
    }

    /** Hotové úkoly — do reportu pod sebe. @param array<int, array{label: string, done: bool}>|null $items @return array<int, string> */
    public static function doneLabels(?array $items): array
    {
        $done = [];

        foreach ($items ?? [] as $item) {
            if ($item['done']) {
                $done[] = $item['label'];
            }
        }

        return $done;
    }

    /** @return array<int, array{label: string, done: bool, extra: bool}>|null null = zápis bez checklistu */
    public static function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return null;
        }

        $items = [];

        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['label'])) {
                $items[] = ['label' => (string) $item['label'], 'done' => !empty($item['done']), 'extra' => !empty($item['extra'])];
            }
        }

        return $items;
    }

    /** @param array<int, array{label: string, done: bool}> $items */
    public static function encode(array $items): ?string
    {
        return $items === [] ? null : (string) json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Co se v servisu udělalo, jednou větou (historie servisů) — poznámka, a když chybí,
     * odškrtnuté úkoly („Aktualizace WordPressu, kontrola zálohy"). Popis
     * je nepovinný, protože checklist už řekne totéž.
     *
     * @param array<int, array{label: string, done: bool}>|null $items
     */
    public static function text(string $description, ?array $items): string
    {
        $description = trim($description);

        if ($description !== '') {
            return $description;
        }

        $done = [];

        foreach (self::doneLabels($items) as $label) {
            $done[] = $done === [] ? $label : mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1);
        }

        return implode(', ', $done);
    }

    /** „3 z 4 úkolů" — prázdný řetězec, když zápis checklist nemá. @param array<int, array{label: string, done: bool}>|null $items */
    public static function progress(?array $items): string
    {
        if ($items === null || $items === []) {
            return '';
        }

        $done = count(array_filter($items, static fn (array $item): bool => $item['done']));

        return $done . ' z ' . count($items) . ' úkolů';
    }
}
