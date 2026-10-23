<?php

declare(strict_types=1);

namespace App\Core\Sites;

use App\Core\Db\Connection;
use InvalidArgumentException;

/**
 * Záložka Služby v detailu webu: které služby na webu běží a co je hotové
 * z kroků po spuštění webu (tabulky `site_services`, `site_launch_tasks`).
 *
 * Nepleť si se Servisem (`Service\…`, pravidelná údržba) ani se službami
 * projektu (`Projects\ProjectServices`, placené domény a hosting).
 *
 * Seznam služeb a úkolů je zatím pevný číselník tady v kódu, ne nastavení
 * per web. Klíče se ukládají do databáze — přejmenovat jde jen popisek,
 * klíč ne. Nová položka se přidá prostě do pole: u všech webů bude
 * nezaškrtnutá.
 */
final class SiteServices
{
    /**
     * Služby, které na webu můžou běžet — skupina => popisek a položky.
     * Podle nich se můžou řídit jiné záložky (data ze Search Console jen
     * u webu s `gsc`), viz `isEnabled()`.
     */
    public const SERVICES = [
        'obecne' => ['label' => 'Obecná nastavení', 'items' => [
            'gtm' => 'Google Tag Manager',
            'gsc' => 'Google Search Console',
            'smtp' => 'Vlastní SMTP',
        ]],
        'statistiky' => ['label' => 'Statistiky', 'items' => [
            'ga' => 'Google Analytics',
            'matomo' => 'Matomo',
            'analyticswp' => 'Analytics WP',
        ]],
        'seo' => ['label' => 'SEO', 'items' => [
            'colabim' => 'Colabim',
            'ranktracker' => 'Rank Tracker',
        ]],
    ];

    /** Jednorázové kroky po spuštění webu — skupina => popisek a úkoly. */
    public const TASKS = [
        'seo' => ['label' => 'SEO základ', 'items' => [
            'rankmath' => 'Rank Math nasazen a nastaven',
            'sitemap' => 'Sitemap odeslána do Search Console',
            'redirects' => 'Přesměrování ze staré verze webu',
            'meta' => 'Titulky a meta popisky stránek',
            'indexing' => 'Kontrola zapnutí indexace webu',
            'forms' => 'Kontrola funkčnosti formulářů',
        ]],
        'pravni' => ['label' => 'Právní minimum', 'items' => [
            'cookies' => 'Cookie lišta nasazena',
            'privacy' => 'Stránka Zásady ochrany osobních údajů',
            'privacy_links' => 'Odkaz na zásady v patičce a u formulářů',
        ]],
        'optimalizace' => ['label' => 'Optimalizace', 'items' => [
            'optimization_plugin' => 'Optimalizační plugin',
            'images' => 'Optimalizace obrázků',
        ]],
        'technika' => ['label' => 'Technika', 'items' => [
            'website_auditor' => 'Website Auditor – kontroly',
        ]],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, true> klíče služeb, které na webu běží */
    public function enabled(int $siteId): array
    {
        $keys = array_column($this->db->select('SELECT service_key FROM site_services WHERE site_id = :id', ['id' => $siteId]), 'service_key');

        return array_fill_keys(array_intersect($keys, self::keys(self::SERVICES)), true);
    }

    /** Běží na webu služba? Pro záložky, které se podle toho řídí. */
    public function isEnabled(int $siteId, string $key): bool
    {
        return isset($this->enabled($siteId)[$key]);
    }

    public function setService(int $siteId, string $key, bool $on): void
    {
        self::assertKey(self::SERVICES, $key);

        if (!$on) {
            $this->db->delete('site_services', ['site_id' => $siteId, 'service_key' => $key]);

            return;
        }

        // Dvojklik ani druhá otevřená karta nesmí skončit chybou duplicity.
        $this->db->execute(
            'INSERT IGNORE INTO site_services (site_id, service_key, created_at) VALUES (:site, :key, :now)',
            ['site' => $siteId, 'key' => $key, 'now' => date('Y-m-d H:i:s')],
        );
    }

    /**
     * Hotové úkoly s podpisem — kdo a kdy je odškrtl.
     *
     * @return array<string, array{done_at: string, user: array{name: string, username: string}|null}>
     */
    public function done(int $siteId): array
    {
        $rows = $this->db->select(
            'SELECT t.task_key, t.done_at, u.name, u.username FROM site_launch_tasks t
             LEFT JOIN users u ON u.id = t.done_by
             WHERE t.site_id = :id',
            ['id' => $siteId],
        );
        $known = array_flip(self::keys(self::TASKS));
        $done = [];

        foreach ($rows as $row) {
            if (isset($known[$row['task_key']])) {
                $done[(string) $row['task_key']] = [
                    'done_at' => (string) $row['done_at'],
                    'user' => $row['username'] !== null ? ['name' => (string) $row['name'], 'username' => (string) $row['username']] : null,
                ];
            }
        }

        return $done;
    }

    /** Odškrtnutí podepíše přihlášený účet a dnešek, zrušení podpis smaže. */
    public function setTask(int $siteId, string $key, bool $done, ?int $userId): void
    {
        self::assertKey(self::TASKS, $key);

        if (!$done) {
            $this->db->delete('site_launch_tasks', ['site_id' => $siteId, 'task_key' => $key]);

            return;
        }

        // Už hotový úkol si nechá původní podpis (druhá karta, dvojklik).
        $this->db->execute(
            'INSERT IGNORE INTO site_launch_tasks (site_id, task_key, done_by, done_at) VALUES (:site, :key, :user, :now)',
            ['site' => $siteId, 'key' => $key, 'user' => $userId, 'now' => date('Y-m-d H:i:s')],
        );
    }

    /**
     * @param array<string, array{label: string, items: array<string, string>}> $groups
     * @return array<int, string>
     */
    public static function keys(array $groups): array
    {
        return array_merge(...array_values(array_map(static fn (array $group): array => array_keys($group['items']), $groups)));
    }

    /** @param array<string, array{label: string, items: array<string, string>}> $groups */
    private static function assertKey(array $groups, string $key): void
    {
        if (!in_array($key, self::keys($groups), true)) {
            throw new InvalidArgumentException('Neznámá položka: ' . $key);
        }
    }
}
