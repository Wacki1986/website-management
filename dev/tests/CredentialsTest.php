<?php

declare(strict_types=1);

/**
 * Trezor přístupů projektu: hesla jen šifrovaně, výpis bez hesel, úprava
 * bez hesla heslo nemaže, cizí projekt se k přístupu nedostane, odebrání
 * webu přístupy nechá, smazání projektu je smaže. Převod ze staré tabulky
 * u webu a obě cesty k trezoru (web, projekt).
 */

use App\Core\Db\Connection;
use App\Core\Projects\Credentials;
use App\Core\Projects\ProjectRepository;
use App\Core\Security\Secrets;
use App\Core\Sites\SiteRepository;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

const CREDENTIALS_APP_KEY = 'c0ffee00112233445566778899aabbccddeeff00112233445566778899aabbcc';

/** @return array{0: Credentials, 1: SiteRepository, 2: int, 3: int, 4: ProjectRepository} trezor, weby, id projektu, id webu, projekty */
function vaultWithProject(Connection $db): array
{
    $secrets = new Secrets(CREDENTIALS_APP_KEY);
    $sites = new SiteRepository($db, $secrets);
    $projects = new ProjectRepository($db);
    $siteId = $sites->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);

    return [new Credentials($db, $secrets), $sites, $projects->createForSite($siteId, 'kavarnadobra.cz', null), $siteId, $projects];
}

return [
    'heslo i poznámka jsou v databázi šifrované, výpis heslo nenese' => function (): void {
        $db = freshTestDb();
        [$vault, , $projectId, $siteId] = vaultWithProject($db);

        $id = $vault->create($projectId, $siteId, 'ftp', [
            'protocol' => 'sftp',
            'host' => 'ftp.kavarnadobra.cz',
            'username' => 'kavarna',
            'password' => 'Tajne:heslo@123',
            'note' => 'PIN podpory 4455',
        ], null);

        $raw = $db->selectOne('SELECT * FROM credentials WHERE id = :id', ['id' => $id]) ?? [];
        assertTrue(Secrets::isEncrypted((string) $raw['password']), 'Heslo není šifrované');
        assertTrue(Secrets::isEncrypted((string) $raw['note']), 'Poznámka není šifrovaná');
        assertFalse(str_contains(json_encode($raw) ?: '', 'Tajne'), 'Heslo leží v databázi čitelně');
        assertSame($siteId, (int) $raw['site_id']);

        $rows = $vault->forProject($projectId);
        assertSame(1, count($rows));
        assertFalse(array_key_exists('password', $rows[0]), 'Výpis nese heslo');
        assertTrue($rows[0]['has_password']);
        assertSame('PIN podpory 4455', $rows[0]['note']);

        assertSame('Tajne:heslo@123', $vault->password($projectId, $id));
    },

    'úprava s prázdným heslem heslo nechá, nové ho přepíše' => function (): void {
        [$vault, , $projectId] = vaultWithProject(freshTestDb());
        $id = $vault->create($projectId, null, 'hosting', ['url' => 'https://admin.wedos.cz', 'username' => 'a', 'password' => 'puvodni'], null);

        $vault->update($projectId, $id, 'hosting', ['url' => 'https://admin.wedos.cz', 'username' => 'b', 'password' => '']);
        assertSame('puvodni', $vault->password($projectId, $id));
        assertSame('b', $vault->find($projectId, $id)['username'] ?? null);

        $vault->update($projectId, $id, 'hosting', ['url' => '', 'username' => 'b', 'password' => 'nove']);
        assertSame('nove', $vault->password($projectId, $id));
    },

    'pole, která druh nemá, se neuloží' => function (): void {
        [$vault, , $projectId] = vaultWithProject(freshTestDb());
        $id = $vault->create($projectId, null, 'hosting', ['host' => 'ftp.x.cz', 'database_name' => 'db', 'url' => 'https://x.cz'], null);
        $row = $vault->find($projectId, $id) ?? [];

        assertSame('', $row['host']);
        assertSame('', $row['database_name']);
        assertFalse($row['has_password']);
    },

    'přístup cizího projektu nejde přečíst, upravit ani smazat' => function (): void {
        $db = freshTestDb();
        [$vault, , $projectId, , $projects] = vaultWithProject($db);
        $otherId = $projects->create(['name' => 'jiny.cz']);
        $id = $vault->create($projectId, null, 'ftp', ['host' => 'ftp.x.cz', 'password' => 'tajne'], null);

        assertSame(null, $vault->find($otherId, $id));
        assertSame(null, $vault->password($otherId, $id));

        $vault->delete($otherId, $id);
        assertSame(1, $vault->countForProject($projectId), 'Smazalo se přes cizí projekt');
    },

    'odebrání webu přístupy nechá u projektu, smazání projektu je smaže' => function (): void {
        [$vault, $sites, $projectId, $siteId, $projects] = vaultWithProject(freshTestDb());
        $vault->create($projectId, $siteId, 'ftp', ['host' => 'ftp.x.cz', 'password' => 'tajne'], null);
        $vault->create($projectId, null, 'email', ['username' => 'info@kavarnadobra.cz', 'label' => 'Wedos', 'password' => 'posta'], null);

        $sites->remove($siteId);
        assertSame(2, $vault->countForProject($projectId), 'Hosting i e-maily žijí dál');
        $counts = $vault->countsByKind($projectId);
        ksort($counts);
        assertSame(['email' => 1, 'ftp' => 1], $counts);

        assertTrue($projects->delete($projectId), 'Projekt bez webu v monitoringu jde smazat');
        assertSame(0, $vault->countForProject($projectId));
    },

    'adresa pro FileZillu: zakódované jméno a heslo, výchozí port podle protokolu' => function (): void {
        $credential = ['protocol' => 'sftp', 'host' => 'ftp.kavarna.cz', 'port' => null, 'username' => 'web@kavarna'];

        assertSame('sftp://web%40kavarna:a%3Ab%40c%2Fd@ftp.kavarna.cz:22', Credentials::fileZillaUrl($credential, 'a:b@c/d'));
        assertSame('ftp://web%40kavarna@ftp.kavarna.cz:2121', Credentials::fileZillaUrl(['protocol' => 'ftp', 'port' => 2121] + $credential, ''));
    },

    'migrace převede přístupy webů do trezoru projektu, jednou a s heslem' => function (): void {
        [$kernel] = loggedInKernel();
        $db = $kernel->db();
        $siteId = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $projectId = $kernel->projects()->createForSite($siteId, 'kavarnadobra.cz', null);
        $encrypted = $kernel->secrets()->encrypt('stare-heslo');
        $db->insert('site_credentials', ['site_id' => $siteId, 'kind' => 'ftp', 'host' => 'ftp.kavarnadobra.cz', 'password' => $encrypted, 'created_at' => '2026-09-24 10:00:00', 'updated_at' => '2026-09-24 10:00:00']);

        $migration = require WWW_ROOT . '/database/migrations/2026_10_05_000003_credentials.php';
        $migration($db->pdo());
        $migration($db->pdo());

        $rows = $kernel->credentials()->forProject($projectId);
        assertSame(1, count($rows), 'Převod jen jednou');
        assertSame('stare-heslo', $kernel->credentials()->password($projectId, (int) $rows[0]['id']));

        Urls::reset();
    },

    'trezor u webu i u projektu ukazuje totéž; e-mail potřebuje platnou adresu' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $a = $kernel->sites()->create(['name' => 'Hotel', 'url' => 'https://hotelzlatylev.cz']);
        $b = $kernel->sites()->create(['name' => 'Rezervace', 'url' => 'https://rezervace.hotelzlatylev.cz']);
        $projectId = $kernel->projects()->createForSite($a, 'hotelzlatylev.cz', null);
        $kernel->projects()->attachSite($b, $projectId);

        assertSame(422, kernelRequest($kernel, 'POST', '/projekty/' . $projectId . '/pristupy/pridat/email', ['_token' => $token, 'username' => 'nesmysl'])->status());
        kernelRequest($kernel, 'POST', '/projekty/' . $projectId . '/pristupy/pridat/email', ['_token' => $token, 'username' => 'recepce@hotelzlatylev.cz', 'label' => 'Google Workspace', 'password' => 'heslo123']);
        kernelRequest($kernel, 'POST', '/weby/' . $a . '/pristupy/pridat/ftp', ['_token' => $token, 'host' => 'ftp.hotelzlatylev.cz', 'protocol' => 'sftp', 'password' => 'ftp-heslo']);

        $fromSite = kernelRequest($kernel, 'GET', '/weby/' . $b . '/pristupy')->body();
        assertContainsString('recepce@hotelzlatylev.cz', $fromSite, 'Druhý web projektu vidí e-mail z projektu');
        assertContainsString('ftp.hotelzlatylev.cz', $fromSite, '…i FTP založené u prvního webu');
        assertContainsString('Přístupy patří projektu hotelzlatylev.cz', $fromSite);

        $fromProject = kernelRequest($kernel, 'GET', '/projekty/' . $projectId . '/pristupy')->body();
        assertContainsString('Přístupy · hotelzlatylev.cz', $fromProject);
        assertContainsString('/projekty/' . $projectId . '/pristupy/', $fromProject, 'Akce vedou na adresy projektu');
        assertFalse(str_contains($fromProject, 'heslo123'), 'Heslo ve stránce není');

        $emailId = (int) $kernel->db()->scalar("SELECT id FROM credentials WHERE kind = 'email'");
        $json = json_decode(kernelRequest($kernel, 'POST', '/projekty/' . $projectId . '/pristupy/' . $emailId . '/heslo', ['_token' => $token])->body(), true);
        assertSame('heslo123', $json['value'] ?? null);

        // Přes adresu jiného projektu se k přístupu nedostane.
        $other = $kernel->projects()->create(['name' => 'jiny.cz']);
        assertSame(404, kernelRequest($kernel, 'POST', '/projekty/' . $other . '/pristupy/' . $emailId . '/heslo', ['_token' => $token])->status());

        assertContainsString('Otevřít trezor', kernelRequest($kernel, 'GET', '/projekty/' . $projectId)->body());

        Urls::reset();
    },
];
