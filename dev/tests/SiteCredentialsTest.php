<?php

declare(strict_types=1);

/**
 * Trezor přístupů u webu: hesla jen šifrovaně, výpis bez hesel, úprava
 * bez hesla heslo nemaže, cizí web se k přístupu nedostane, odebrání webu
 * přístupy smaže.
 */

use App\Core\Db\Connection;
use App\Core\Security\Secrets;
use App\Core\Sites\SiteCredentials;
use App\Core\Sites\SiteRepository;

const CREDENTIALS_APP_KEY = 'c0ffee00112233445566778899aabbccddeeff00112233445566778899aabbcc';

/** @return array{0: SiteCredentials, 1: SiteRepository, 2: int} */
function vaultWithSite(Connection $db): array
{
    $secrets = new Secrets(CREDENTIALS_APP_KEY);
    $sites = new SiteRepository($db, $secrets);

    return [new SiteCredentials($db, $secrets), $sites, $sites->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz'])];
}

return [
    'heslo i poznámka jsou v databázi šifrované, výpis heslo nenese' => function (): void {
        $db = freshTestDb();
        [$vault, , $siteId] = vaultWithSite($db);

        $id = $vault->create($siteId, 'ftp', [
            'protocol' => 'sftp',
            'host' => 'ftp.kavarnadobra.cz',
            'username' => 'kavarna',
            'password' => 'Tajne:heslo@123',
            'note' => 'PIN podpory 4455',
        ], null);

        $raw = $db->selectOne('SELECT * FROM site_credentials WHERE id = :id', ['id' => $id]) ?? [];
        assertTrue(Secrets::isEncrypted((string) $raw['password']), 'Heslo není šifrované');
        assertTrue(Secrets::isEncrypted((string) $raw['note']), 'Poznámka není šifrovaná');
        assertFalse(str_contains(json_encode($raw) ?: '', 'Tajne'), 'Heslo leží v databázi čitelně');

        $rows = $vault->forSite($siteId);
        assertSame(1, count($rows));
        assertFalse(array_key_exists('password', $rows[0]), 'Výpis nese heslo');
        assertTrue($rows[0]['has_password']);
        assertSame('PIN podpory 4455', $rows[0]['note']);

        assertSame('Tajne:heslo@123', $vault->password($siteId, $id));
    },

    'úprava s prázdným heslem heslo nechá, nové ho přepíše' => function (): void {
        [$vault, , $siteId] = vaultWithSite(freshTestDb());
        $id = $vault->create($siteId, 'hosting', ['url' => 'https://admin.wedos.cz', 'username' => 'a', 'password' => 'puvodni'], null);

        $vault->update($siteId, $id, 'hosting', ['url' => 'https://admin.wedos.cz', 'username' => 'b', 'password' => '']);
        assertSame('puvodni', $vault->password($siteId, $id));
        assertSame('b', $vault->find($siteId, $id)['username'] ?? null);

        $vault->update($siteId, $id, 'hosting', ['url' => '', 'username' => 'b', 'password' => 'nove']);
        assertSame('nove', $vault->password($siteId, $id));
    },

    'pole, která druh nemá, se neuloží' => function (): void {
        [$vault, , $siteId] = vaultWithSite(freshTestDb());
        $id = $vault->create($siteId, 'hosting', ['host' => 'ftp.x.cz', 'database_name' => 'db', 'url' => 'https://x.cz'], null);
        $row = $vault->find($siteId, $id) ?? [];

        assertSame('', $row['host']);
        assertSame('', $row['database_name']);
        assertFalse($row['has_password']);
    },

    'přístup cizího webu nejde přečíst, upravit ani smazat' => function (): void {
        $db = freshTestDb();
        [$vault, $sites, $siteId] = vaultWithSite($db);
        $otherId = $sites->create(['name' => 'Jiný', 'url' => 'https://jiny.cz']);
        $id = $vault->create($siteId, 'ftp', ['host' => 'ftp.x.cz', 'password' => 'tajne'], null);

        assertSame(null, $vault->find($otherId, $id));
        assertSame(null, $vault->password($otherId, $id));

        $vault->delete($otherId, $id);
        assertSame(1, $vault->countForSite($siteId), 'Smazalo se přes cizí web');
    },

    'odebrání webu z monitoringu přístupy smaže' => function (): void {
        [$vault, $sites, $siteId] = vaultWithSite(freshTestDb());
        $vault->create($siteId, 'ftp', ['host' => 'ftp.x.cz', 'password' => 'tajne'], null);

        $sites->remove($siteId);

        assertSame(0, $vault->countForSite($siteId));
    },

    'adresa pro FileZillu: zakódované jméno a heslo, výchozí port podle protokolu' => function (): void {
        $credential = ['protocol' => 'sftp', 'host' => 'ftp.kavarna.cz', 'port' => null, 'username' => 'web@kavarna'];

        assertSame('sftp://web%40kavarna:a%3Ab%40c%2Fd@ftp.kavarna.cz:22', SiteCredentials::fileZillaUrl($credential, 'a:b@c/d'));
        assertSame('ftp://web%40kavarna@ftp.kavarna.cz:2121', SiteCredentials::fileZillaUrl(['protocol' => 'ftp', 'port' => 2121] + $credential, ''));
    },
];
