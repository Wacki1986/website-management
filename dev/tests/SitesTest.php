<?php

declare(strict_types=1);

/**
 * Evidence webů: normalizace adres, API klíč (šifrování), soft delete
 * a souhrnný stav pro seznamy (`SiteStatus`).
 */

use App\Core\Security\Secrets;
use App\Core\Sites\ApiKey;
use App\Core\Sites\SiteRepository;
use App\Core\Sites\SiteStatus;

const SITES_APP_KEY = 'aa112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

function sitesRepo(): SiteRepository
{
    return new SiteRepository(freshTestDb(), new Secrets(SITES_APP_KEY));
}

return [
    'adresa se normalizuje: doplní https, srazí velikost, uřízne lomítko a dotaz' => function (): void {
        assertSame('https://kavarnadobra.cz', SiteRepository::normalizeUrl('KavarnaDobra.cz/'));
        assertSame('https://kavarnadobra.cz', SiteRepository::normalizeUrl('https://kavarnadobra.cz/?utm=1'));
        assertSame('http://127.0.0.1:8181', SiteRepository::normalizeUrl('http://127.0.0.1:8181/'));
        assertSame('https://firma.cz/blog', SiteRepository::normalizeUrl('firma.cz/blog/'));
        assertSame('', SiteRepository::normalizeUrl('nesmysl bez domény'));
        assertSame('kavarnadobra.cz', SiteRepository::host('https://kavarnadobra.cz/blog'));
    },

    'API klíč má předponu a 32 znaků, je pokaždé jiný' => function (): void {
        $a = ApiKey::generate();
        $b = ApiKey::generate();

        assertTrue(ApiKey::isValid($a), $a);
        assertTrue($a !== $b, 'Dva klíče po sobě nesmí být stejné');
        assertFalse(ApiKey::isValid('mg_live_kratky'));
        assertSame(substr($a, -4), ApiKey::hint($a));
        assertContainsString('••••', ApiKey::masked('4f7a'));
    },

    'klíč se ukládá šifrovaně a čte zpátky; v řádku je jen hint' => function (): void {
        $repo = sitesRepo();
        $id = $repo->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $key = ApiKey::generate();
        $repo->setApiKey($id, $key);

        $site = $repo->find($id);
        assertTrue($site !== null);
        assertSame(ApiKey::hint($key), $site['api_key_hint']);
        assertFalse(str_contains((string) $site['api_key'], $key), 'Klíč nesmí ležet v databázi v otevřené podobě');
        assertSame($key, $repo->apiKey($site));
        assertSame('unknown', $site['api_status']);
    },

    'odebraný web zmizí ze seznamu, ale zůstane v databázi' => function (): void {
        $repo = sitesRepo();
        $id = $repo->create(['name' => 'A', 'url' => 'https://a.cz']);
        $repo->create(['name' => 'B', 'url' => 'https://b.cz']);

        assertSame(2, count($repo->all()));
        $repo->remove($id);
        assertSame(1, count($repo->all()));
        assertSame(1, $repo->countActive());
        assertTrue($repo->find($id) !== null, 'Řádek musí přežít — 12 měsíců archiv');
        assertTrue($repo->find($id)['removed_at'] !== null);
    },

    'seznam nese klienta a snapshot, hledá podle názvu, domény i klienta' => function (): void {
        $db = freshTestDb();
        $repo = new SiteRepository($db, new Secrets(SITES_APP_KEY));
        $clientId = $db->insert('clients', ['name' => 'Zlatý Lev a.s.', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $repo->create(['name' => 'Hotel', 'url' => 'https://hotelzlatylev.cz', 'client_id' => $clientId]);
        $repo->create(['name' => 'Pekařství', 'url' => 'https://pekarstvinovak.cz']);

        assertSame(1, count($repo->all(['q' => 'lev'])));
        assertSame(1, count($repo->all(['q' => 'novak'])));
        assertSame(1, count($repo->all(['client' => $clientId])));
        assertSame('Zlatý Lev a.s.', $repo->all(['client' => $clientId])[0]['client_name']);
        assertSame(1, count($repo->unassigned()));
        assertTrue(array_key_exists('snap_wp_version', $repo->all()[0]));
    },

    'souhrnný stav: pořadí závažnosti od výpadku po v pořádku' => function (): void {
        $now = strtotime('2026-09-18 12:00:00');
        $base = ['status' => 'ok', 'api_status' => 'ok', 'api_failures' => 0, 'ssl_valid_to' => '2026-12-01',
            'snap_php_version' => '8.3.1', 'snap_wp_update_version' => null, 'snap_plugins_total' => 5, 'snap_plugins_active' => 5,
            'last_check_at' => '2026-09-18 11:50:00', 'snap_fetched_at' => '2026-09-18 11:00:00'];

        assertSame('V pořádku', SiteStatus::of($base, $now)['label']);
        assertSame('ok', SiteStatus::of($base, $now)['level']);
        assertSame('Nedostupný', SiteStatus::of(['status' => 'down'] + $base, $now)['label']);
        assertSame('problem', SiteStatus::of(['status' => 'down'] + $base, $now)['level']);
        assertSame('SSL vypršel', SiteStatus::of(['ssl_valid_to' => '2026-09-10'] + $base, $now)['label']);
        assertSame('Plugin neodpovídá', SiteStatus::of(['api_status' => 'bad_key', 'api_failures' => 3] + $base, $now)['label']);
        assertSame('PHP 7.4 EOL', SiteStatus::of(['snap_php_version' => '7.4.33'] + $base, $now)['label']);
        assertSame('Zastaralé WP', SiteStatus::of(['snap_wp_update_version' => '6.9'] + $base, $now)['label']);
        assertSame('SSL brzy vyprší', SiteStatus::of(['ssl_valid_to' => '2026-10-01'] + $base, $now)['label']);
        assertSame('Neaktivní pluginy', SiteStatus::of(['snap_plugins_active' => 3] + $base, $now)['label']);
        assertSame('Zatím nekontrolováno', SiteStatus::of(['status' => 'unknown', 'last_check_at' => null, 'snap_fetched_at' => null] + $base, $now)['label']);
        // Dva pokusy o plugin ještě nejsou důvod k pozornosti.
        assertSame('V pořádku', SiteStatus::of(['api_status' => 'error', 'api_failures' => 2] + $base, $now)['label']);
    },
];
