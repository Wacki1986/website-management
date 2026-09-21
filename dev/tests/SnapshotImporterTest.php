<?php

declare(strict_types=1);

/**
 * Import odpovědi pluginu: snapshot, seznam pluginů a události z rozdílů
 * proti minule — bez sítě, z hotových polí.
 */

use App\Core\Events\EventLog;
use App\Core\Monitor\SnapshotImporter;
use App\Core\Security\Secrets;
use App\Core\Sites\SiteRepository;

function importerFixture(): array
{
    $db = freshTestDb();
    $sites = new SiteRepository($db, new Secrets(str_repeat('ab', 32)));
    $events = new EventLog($db);
    $importer = new SnapshotImporter($db, $sites, $events);
    $id = $sites->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);

    return [$importer, $sites, $events, $id];
}

/** @return array{ok: bool, code: string, status: int, data: ?array, error: ?string, plugin_version: string} */
function importerResult(array $data): array
{
    return ['ok' => true, 'code' => 'ok', 'status' => 200, 'data' => $data, 'error' => null, 'plugin_version' => '1.0.0'];
}

function importerPayload(string $wooVersion = '9.2.1', bool $cf7Active = true, string $wp = '6.8.2'): array
{
    return [
        'wordpress' => ['version' => $wp, 'has_update' => false, 'new_version' => null],
        'server' => ['php_version' => '8.2.20', 'db_type' => 'MariaDB', 'db_version' => '10.6.18', 'db_size_mb' => 312],
        'theme' => ['name' => 'Astra', 'version' => '4.8.2', 'is_child' => true],
        'plugins' => ['total' => 2, 'active' => $cf7Active ? 2 : 1, 'updates' => 0, 'items' => [
            ['file' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'author' => 'Automattic', 'version' => $wooVersion, 'is_active' => true, 'has_update' => false, 'new_version' => null],
            ['file' => 'contact-form-7/wp-contact-form-7.php', 'name' => 'Contact Form 7', 'author' => 'Takayuki Miyoshi', 'version' => '5.9.3', 'is_active' => $cf7Active, 'has_update' => false, 'new_version' => null],
        ]],
        'content' => ['post_types' => []],
        'backup' => ['last_backup_at' => '2026-09-17 03:00:00', 'source' => 'UpdraftPlus'],
    ];
}

return [
    'první import uloží snapshot, pluginy a jednu událost „poprvé odpověděl"' => function (): void {
        [$importer, $sites, $events, $id] = importerFixture();

        $result = $importer->import($sites->find($id), importerResult(importerPayload()));

        assertTrue($result['imported']);
        $snapshot = $importer->snapshot($id);
        assertSame('6.8.2', $snapshot['wp_version']);
        assertSame('8.2.20', $snapshot['php_version']);
        assertSame(312, (int) $snapshot['db_size_mb']);
        assertSame(2, (int) $snapshot['plugins_total']);
        assertSame('2026-09-17 03:00:00', $snapshot['last_backup_at']);
        assertSame(2, count($importer->plugins($id)));
        // Žádná záplava „nainstalován" při prvním načtení — jen jedna událost.
        assertSame(1, $events->count($id));
        assertSame('ok', $sites->find($id)['api_status']);
    },

    'druhý import zapíše aktualizaci, deaktivaci a odstranění jako události' => function (): void {
        [$importer, $sites, $events, $id] = importerFixture();
        $importer->import($sites->find($id), importerResult(importerPayload()));

        // WooCommerce povýšen, CF7 vypnutý, WordPress povýšen.
        $result = $importer->import($sites->find($id), importerResult(importerPayload('9.3.0', false, '6.9')));

        assertContainsString('WooCommerce 9.2.1 → 9.3.0', implode('; ', $result['changes']));
        assertContainsString('Contact Form 7 deaktivován', implode('; ', $result['changes']));
        assertContainsString('WordPress 6.8.2 → 6.9', implode('; ', $result['changes']));

        $messages = array_map(static fn (array $e): string => (string) $e['message'], $events->latest($id, 10));
        assertTrue(in_array('WooCommerce aktualizován 9.2.1 → 9.3.0', $messages, true), implode(' | ', $messages));
        assertTrue(in_array('WordPress aktualizován 6.8.2 → 6.9', $messages, true));

        // Detail události nese from/to — z něj se skládá klientský report.
        $updated = array_values(array_filter($events->between($id, date('Y-m-d'), date('Y-m-d'), [EventLog::KIND_PLUGIN]),
            static fn (array $e): bool => str_contains((string) $e['message'], 'aktualizován')));
        assertSame(1, count($updated));
        assertSame('9.3.0', json_decode((string) $updated[0]['detail'], true)['to']);

        // Odstranění: plugin z odpovědi zmizel.
        $payload = importerPayload('9.3.0', false, '6.9');
        $payload['plugins']['items'] = [$payload['plugins']['items'][0]];
        $result = $importer->import($sites->find($id), importerResult($payload));
        assertContainsString('Contact Form 7 odstraněn', implode('; ', $result['changes']));
        assertSame(1, count($importer->plugins($id)));
    },

    'neúspěch mění stav pluginu u webu, snapshot zůstává; třetí selhání je událost' => function (): void {
        [$importer, $sites, $events, $id] = importerFixture();
        $importer->import($sites->find($id), importerResult(importerPayload()));
        $before = $events->count($id);

        $failure = ['ok' => false, 'code' => 'bad_key', 'status' => 401, 'data' => null, 'error' => 'Neplatný API klíč.', 'plugin_version' => ''];

        $importer->import($sites->find($id), $failure);
        $importer->import($sites->find($id), $failure);
        assertSame($before, $events->count($id), 'Dvě selhání ještě nejsou událost');

        $importer->import($sites->find($id), $failure);
        $site = $sites->find($id);
        assertSame('bad_key', $site['api_status']);
        assertSame(3, (int) $site['api_failures']);
        assertSame($before + 1, $events->count($id));
        assertTrue($importer->snapshot($id) !== null, 'Stará data zůstávají — v UI jako zastaralá');

        // Nedostupný web stav pluginu nepřepisuje (to hlásí uptime).
        $importer->import($sites->find($id), ['ok' => false, 'code' => 'unreachable', 'status' => 0, 'data' => null, 'error' => 'timeout', 'plugin_version' => '']);
        assertSame('bad_key', $sites->find($id)['api_status']);

        // Úspěch všechno vynuluje.
        $importer->import($sites->find($id), importerResult(importerPayload()));
        assertSame(0, (int) $sites->find($id)['api_failures']);
        assertSame('ok', $sites->find($id)['api_status']);
    },

    'chybějící klíče v odpovědi (starší plugin) nejsou chyba' => function (): void {
        [$importer, $sites, $events, $id] = importerFixture();

        $result = $importer->import($sites->find($id), importerResult(['wordpress' => ['version' => '6.5']]));

        assertTrue($result['imported']);
        assertSame('6.5', $importer->snapshot($id)['wp_version']);
        assertSame(0, (int) $importer->snapshot($id)['plugins_total']);
    },
];
