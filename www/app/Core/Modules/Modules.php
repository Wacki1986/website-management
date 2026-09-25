<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Db\Connection;
use App\Core\Settings\Settings;

/**
 * Volitelná měření webů — moduly.
 *
 * Modul se zapíná na dvou místech: globálně (Nastavení → Moduly) a u webu
 * (Nastavení webu → Hlídání). Platí jen obojí najednou — globální vypínač
 * schová modul všude, aniž by se ztratilo, u kterých webů byl zapnutý.
 *
 * Nejde o systém zásuvných modulů: modul je řádek v `REGISTRY` a jeho kód
 * žije tam, kde by žil tak jako tak (sběr v pluginu, import, záložka,
 * report). Tahle třída jen odpovídá na otázku „má se to u webu dělat?".
 */
final class Modules
{
    public const SEO = 'seo';

    /**
     * `plugin_since` — od které verze MEDIAGRAFIK Monitoru plugin data
     * modulu posílá (starší parametr `modules` ignoruje).
     */
    public const REGISTRY = [
        self::SEO => [
            'label' => 'SEO',
            'title' => 'SEO z pluginu na webu',
            'text' => 'Skóre stránek z Rank Math nebo Yoast SEO, stránky bez klíčového slova a meta popisu, viditelnost pro vyhledávače a mapa webu.',
            'plugin_since' => '1.6.0',
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Settings $settings,
    ) {
    }

    public static function exists(string $key): bool
    {
        return isset(self::REGISTRY[$key]);
    }

    /** Je modul zapnutý globálně (Nastavení → Moduly)? */
    public function isOn(string $key): bool
    {
        return self::exists($key) && $this->settings->get('module_' . $key . '_on') === '1';
    }

    /** Zapnout modul u nově přidaných webů automaticky? */
    public function isOnForNewSites(string $key): bool
    {
        return self::exists($key) && $this->settings->get('module_' . $key . '_new_sites') === '1';
    }

    public function save(string $key, bool $on, bool $newSites): void
    {
        if (!self::exists($key)) {
            return;
        }

        $this->settings->set('module_' . $key . '_on', $on ? '1' : '0');
        $this->settings->set('module_' . $key . '_new_sites', $newSites ? '1' : '0');
    }

    /** Globálně zapnuté moduly. @return array<string, array{label: string, title: string, text: string, plugin_since: string}> */
    public function active(): array
    {
        return array_filter(self::REGISTRY, fn (string $key): bool => $this->isOn($key), ARRAY_FILTER_USE_KEY);
    }

    /** Má se modul u webu dělat — zapnutý globálně i u webu? */
    public function forSite(int $siteId, string $key): bool
    {
        if (!$this->isOn($key)) {
            return false;
        }

        return (int) $this->db->scalar(
            'SELECT is_on FROM site_modules WHERE site_id = :site AND module = :module',
            ['site' => $siteId, 'module' => $key],
        ) === 1;
    }

    /**
     * Zapnuté moduly pro víc webů jedním dotazem (seznam webů, cron).
     * Weby bez zapnutého modulu ve výsledku chybí.
     *
     * @param array<int, int> $siteIds
     * @return array<int, array<int, string>> id webu => klíče modulů
     */
    public function forSites(array $siteIds): array
    {
        $active = array_keys($this->active());

        if ($siteIds === [] || $active === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $siteIds));
        $result = [];

        foreach ($this->db->select('SELECT site_id, module FROM site_modules WHERE is_on = 1 AND site_id IN (' . $ids . ')') as $row) {
            if (in_array((string) $row['module'], $active, true)) {
                $result[(int) $row['site_id']][] = (string) $row['module'];
            }
        }

        return $result;
    }

    /**
     * Globálně zapnuté moduly s volbou u webu — řádky karty Hlídání.
     *
     * @return array<string, array{label: string, title: string, text: string, on: bool}>
     */
    public function siteChoices(int $siteId): array
    {
        $on = $this->forSites([$siteId])[$siteId] ?? [];
        $choices = [];

        foreach ($this->active() as $key => $module) {
            $choices[$key] = ['label' => $module['label'], 'title' => $module['title'], 'text' => $module['text'], 'on' => in_array($key, $on, true)];
        }

        return $choices;
    }

    public function setForSite(int $siteId, string $key, bool $on): void
    {
        if (!self::exists($key)) {
            return;
        }

        $this->db->execute(
            'INSERT INTO site_modules (site_id, module, is_on, updated_at) VALUES (:site, :module, :on, :now)
             ON DUPLICATE KEY UPDATE is_on = VALUES(is_on), updated_at = VALUES(updated_at)',
            ['site' => $siteId, 'module' => $key, 'on' => $on ? 1 : 0, 'now' => date('Y-m-d H:i:s')],
        );
    }

    /** Zapnout modul u všech webů v monitoringu. @return int počet webů */
    public function enableForAll(string $key): int
    {
        $count = 0;

        foreach ($this->db->select('SELECT id FROM sites WHERE removed_at IS NULL') as $row) {
            $this->setForSite((int) $row['id'], $key, true);
            $count++;
        }

        return $count;
    }

    /** Počet webů, u kterých je modul zapnutý (Nastavení → Moduly). */
    public function siteCount(string $key): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM site_modules m JOIN sites s ON s.id = m.site_id
             WHERE m.module = :module AND m.is_on = 1 AND s.removed_at IS NULL',
            ['module' => $key],
        );
    }

    /** Nový web dostane moduly s volbou „zapnout u nových webů". */
    public function onNewSite(int $siteId): void
    {
        foreach (array_keys(self::REGISTRY) as $key) {
            if ($this->isOnForNewSites($key)) {
                $this->setForSite($siteId, $key, true);
            }
        }
    }
}
