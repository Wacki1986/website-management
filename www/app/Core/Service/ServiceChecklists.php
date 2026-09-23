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
     * @return array<int, array{label: string, done: bool}>
     */
    public static function build(array $labels, array $done): array
    {
        $done = array_map('intval', array_filter($done, 'is_numeric'));
        $items = [];

        foreach (array_values($labels) as $i => $label) {
            $items[] = ['label' => $label, 'done' => in_array($i, $done, true)];
        }

        return $items;
    }

    /** @return array<int, array{label: string, done: bool}>|null null = zápis bez checklistu */
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
                $items[] = ['label' => (string) $item['label'], 'done' => !empty($item['done'])];
            }
        }

        return $items;
    }

    /** @param array<int, array{label: string, done: bool}> $items */
    public static function encode(array $items): ?string
    {
        return $items === [] ? null : (string) json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
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
