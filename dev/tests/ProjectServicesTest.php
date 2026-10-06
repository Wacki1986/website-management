<?php

declare(strict_types=1);

/**
 * Domény a hosting projektu: stavy obnovy a fakturace (čistá logika),
 * Vyfakturováno / Obnoveno, upozornění jednou na obnovu, RDAP, převod
 * starých údajů webu migrací a obrazovky.
 */

use App\Core\Monitor\DomainChecker;
use App\Core\Projects\ProjectRepository;
use App\Core\Projects\ProjectServices;
use App\Core\Projects\Renewals;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/monitor-fixture.php';
require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'datumy, ceny a doména: přetečení měsíce, haléře, normalizace' => function (): void {
        assertSame('2027-02-28', ProjectServices::addMonths('2027-01-31', 1));
        assertSame('2028-02-29', ProjectServices::addMonths('2028-01-31', 1), 'Přestupný rok');
        assertSame('2029-03-15', ProjectServices::addMonths('2026-03-15', 36));
        assertSame("1\u{00A0}290\u{00A0}Kč", ProjectServices::money(1290.0, 'CZK'));
        assertSame("12,50\u{00A0}€", ProjectServices::money(12.5, 'EUR'));
        assertSame("390\u{00A0}Kč / rok", ProjectServices::priceLabel(['sale_price' => '390.00', 'currency' => 'CZK', 'period_months' => 12]));
        assertSame('', ProjectServices::priceLabel(['sale_price' => null, 'currency' => 'CZK', 'period_months' => 12]));
        assertSame('pekarnanovak.cz', ProjectServices::normalizeDomain(' https://www.PekarnaNovak.cz/kontakt '));
        assertSame('', ProjectServices::normalizeDomain('nesmysl'));
        assertTrue(ProjectServices::isRegistrable('pekarnanovak.cz'));
        assertFalse(ProjectServices::isRegistrable('eshop.pekarnanovak.cz'));
    },

    'stav obnovy a fakturace: platí klient, bez ceny, k vyfakturování, vyfakturováno' => function (): void {
        $today = '2026-10-05';
        $base = ['paid_by' => 'us', 'sale_price' => '390.00', 'currency' => 'CZK', 'period_months' => 12, 'renews_on' => '2026-10-20', 'invoiced_until' => null];

        assertSame('warning', ProjectServices::renewalState($base, $today, 30)['tone']);
        assertSame('error', ProjectServices::renewalState(['renews_on' => '2026-10-01'] + $base, $today, 30)['tone']);
        assertSame('ok', ProjectServices::renewalState(['renews_on' => '2027-03-01'] + $base, $today, 30)['tone']);
        assertSame('muted', ProjectServices::renewalState(['renews_on' => null] + $base, $today, 30)['tone']);

        assertTrue(ProjectServices::billingState($base, $today, 30)['due'], 'Obnova za 15 dní, nic nevyfakturováno');
        assertFalse(ProjectServices::billingState(['paid_by' => 'client'] + $base, $today, 30)['due']);
        assertFalse(ProjectServices::billingState(['sale_price' => null] + $base, $today, 30)['due']);
        assertFalse(ProjectServices::billingState(['renews_on' => '2027-03-01'] + $base, $today, 30)['due'], 'Do obnovy daleko');
        // Vyfakturováno jen aktuální období → příští ještě čeká.
        assertTrue(ProjectServices::billingState(['invoiced_until' => '2026-10-20'] + $base, $today, 30)['due']);
        $covered = ProjectServices::billingState(['invoiced_until' => '2027-10-20'] + $base, $today, 30);
        assertFalse($covered['due']);
        assertSame('vyfakturováno do 20. 10. 2027', $covered['label']);
    },

    'Vyfakturováno a Obnoveno posunou data; upozornění jednou na obnovu; RDAP doplní expiraci' => function (): void {
        $f = monitorFixture();
        $projects = new ProjectRepository($f['db']);
        $services = new ProjectServices($f['db']);
        $projectId = $projects->create(['name' => 'pekarnanovak.cz']);
        $hosting = $services->create($projectId, 'hosting', ['provider' => 'Wedos', 'plan' => 'NoLimit', 'renews_on' => '2026-10-20', 'sale_price' => 1290, 'period_months' => 12]);
        $domain = $services->create($projectId, 'domain', ['name' => 'pekarnanovak.cz']);

        $services->markInvoiced($hosting);
        assertSame('2027-10-20', $services->find($hosting)['invoiced_until']);
        $services->markRenewed($hosting);
        assertSame('2027-10-20', $services->find($hosting)['renews_on']);

        $services->update($hosting, ['renews_on' => '2026-10-20']);
        $rdap = json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => '2026-10-25T10:00:00Z']], 'entities' => [['handle' => 'REG-WEDOS', 'roles' => ['registrar']]]]);
        $renewals = new Renewals($services, new DomainChecker(fetcher: static fn (string $url): array => ['status' => str_contains($url, 'pekarnanovak.cz') ? 200 : 404, 'body' => $rdap]), $f['notifier'], $f['monitorSettings']);
        $now = (int) strtotime('2026-10-05 09:00:00');

        $renewals->step($now, microtime(true) + 60);
        assertSame('2026-10-25', $services->find($domain)['renews_on'], 'Expirace z registru');
        assertSame('WEDOS', $services->find($domain)['provider'], 'Registrátor z registru');
        assertSame('2026-10-20', $services->find($hosting)['notified_for']);
        assertSame('2026-10-25', $services->find($domain)['notified_for']);
        $mails = glob($f['logDir'] . '/*.eml') ?: [];
        assertSame(1, count($mails), 'Jeden souhrn za obě služby');
        assertContainsString('pekarnanovak.cz', (string) file_get_contents($mails[0]));

        $renewals->step($now + 3600, microtime(true) + 60);
        assertSame(1, count(glob($f['logDir'] . '/*.eml') ?: []), 'Podruhé se o téže obnově nepíše');

        $services->markRenewed($hosting);
        assertSame(null, $services->find($hosting)['notified_for'], 'Nové datum obnovy = nové upozornění');
    },

    'registrátor z RDAPu: jméno z vCard, zkratka .cz, ruční oprava se nepřepisuje' => function (): void {
        $gtld = json_encode(['entities' => [['roles' => ['registrar'], 'handle' => '292', 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'MarkMonitor Inc.']]]]]]);
        assertSame('MarkMonitor Inc.', DomainChecker::registrarFrom((string) $gtld));
        assertSame('Forpsi', DomainChecker::registrarFrom((string) json_encode(['entities' => [['roles' => ['registrar'], 'handle' => 'REG-INTERNET-CZ']]])));
        assertSame('EXONHOST', DomainChecker::registrarFrom((string) json_encode(['entities' => [['roles' => ['registrar'], 'handle' => 'REG-EXONHOST']]])));
        assertSame(null, DomainChecker::registrarFrom((string) json_encode(['entities' => [['roles' => ['registrant'], 'handle' => 'CID-X']]])));

        $f = monitorFixture();
        $services = new ProjectServices($f['db']);
        $projectId = (new ProjectRepository($f['db']))->create(['name' => 'pekarnanovak.cz']);
        $id = $services->create($projectId, 'domain', ['name' => 'pekarnanovak.cz']);
        $result = static fn (string $registrar): array => ['ok' => true, 'expires_on' => '2027-01-01', 'registrar' => $registrar, 'error' => null];

        $services->saveExpiry($id, $result('WEDOS'), '2026-10-05 10:00:00');
        assertSame('WEDOS', $services->find($id)['provider']);

        // Převod k jinému registrátorovi se propíše, dokud pole nikdo nepřepsal.
        $services->saveExpiry($id, $result('Active24'), '2026-10-12 10:00:00');
        assertSame('Active24', $services->find($id)['provider']);

        $services->update($id, ['provider' => 'Active24 (účet klienta)']);
        $services->saveExpiry($id, $result('Active24'), '2026-10-19 10:00:00');
        assertSame('Active24 (účet klienta)', $services->find($id)['provider'], 'Ruční oprava zůstává');
    },

    'migrace převede hosting a doménu webů na služby projektu, jednou' => function (): void {
        [$kernel] = loggedInKernel();
        $db = $kernel->db();
        $a = $kernel->sites()->create(['name' => 'Hotel', 'url' => 'https://hotelzlatylev.cz', 'hosting_note' => 'Wedos NoLimit', 'domain_expires_on' => '2027-03-01']);
        $b = $kernel->sites()->create(['name' => 'Rezervace', 'url' => 'https://rezervace.hotelzlatylev.cz', 'hosting_note' => 'Wedos NoLimit']);
        $project = $kernel->projects()->createForSite($a, 'hotelzlatylev.cz', null);
        $kernel->projects()->attachSite($b, $project);

        $migration = require WWW_ROOT . '/database/migrations/2026_10_05_000002_project_services.php';
        $migration($db->pdo());
        $migration($db->pdo());

        $rows = $kernel->projectServices()->forProject($project);
        assertSame(2, count($rows), 'Jedna doména (obě adresy) a jeden hosting');
        assertSame('hotelzlatylev.cz', $rows[0]['name']);
        assertSame('2027-03-01', $rows[0]['renews_on']);
        assertSame('Wedos NoLimit', $rows[1]['provider']);
        assertSame('Wedos NoLimit', $kernel->sites()->find($a)['project_hosting'], 'Přehled webu čte hosting z projektu');

        Urls::reset();
    },

    'obrazovky: přidání domény a hostingu, přehled K vyfakturování po klientech, akce z přehledu' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $client = $kernel->clients()->create(['name' => 'Pekárna Novák']);
        $project = $kernel->projects()->create(['name' => 'pekarnanovak.cz', 'client_id' => $client]);
        $soon = date('Y-m-d', strtotime('+10 days'));

        assertSame(422, kernelRequest($kernel, 'POST', '/projekty/' . $project . '/sluzby/pridat/domena', ['_token' => $token, 'name' => 'nesmysl'])->status());
        kernelRequest($kernel, 'POST', '/projekty/' . $project . '/sluzby/pridat/domena', ['_token' => $token, 'name' => 'www.pekarnanovak.cz', 'paid_by' => 'us', 'sale_price' => '390', 'renews_on' => $soon]);
        kernelRequest($kernel, 'POST', '/projekty/' . $project . '/sluzby/pridat/hosting', ['_token' => $token, 'provider' => 'Wedos', 'plan' => 'NoLimit', 'paid_by' => 'client', 'sale_price' => '1 290,50']);
        $rows = $kernel->projectServices()->forProject($project);
        assertSame('pekarnanovak.cz', $rows[0]['name']);
        assertSame('1290.50', $rows[1]['sale_price']);
        assertSame(404, kernelRequest($kernel, 'GET', '/projekty/' . $project . '/sluzby/pridat/email')->status());

        $detail = kernelRequest($kernel, 'GET', '/projekty/' . $project)->body();
        assertContainsString('k vyfakturování', $detail);
        assertContainsString('platí klient', $detail);

        // Klient: ročně jen to, co platíme my (hosting si platí sám). Seznam: nejbližší obnova.
        assertContainsString("390\u{00A0}Kč</span>", kernelRequest($kernel, 'GET', '/klienti/' . $client)->body());
        assertContainsString('za 10', kernelRequest($kernel, 'GET', '/projekty')->body());

        $overview = kernelRequest($kernel, 'GET', '/projekty/obnovy', [], ['filtr' => 'fakturace'])->body();
        assertContainsString('Pekárna Novák', $overview);
        assertContainsString("celkem 390\u{00A0}Kč", $overview);

        $response = kernelRequest($kernel, 'POST', '/projekty/' . $project . '/sluzby/' . $rows[0]['id'] . '/vyfakturovano', ['_token' => $token, 'back' => 'obnovy']);
        assertSame('/projekty/obnovy', (string) ($response->headers()['Location'] ?? ''));
        assertSame(date('Y-m-d', strtotime($soon . ' +12 months')), $kernel->projectServices()->find((int) $rows[0]['id'])['invoiced_until']);

        // Služba cizího projektu přes adresu jiného projektu = 404.
        $other = $kernel->projects()->create(['name' => 'jiny.cz']);
        assertSame(404, kernelRequest($kernel, 'GET', '/projekty/' . $other . '/sluzby/' . $rows[0]['id'] . '/upravit')->status());

        kernelRequest($kernel, 'POST', '/projekty/' . $project . '/sluzby/' . $rows[1]['id'] . '/smazat', ['_token' => $token]);
        assertSame(1, count($kernel->projectServices()->forProject($project)));

        // Nový web bez projektu dostane projekt i s doménou.
        kernelRequest($kernel, 'POST', '/weby/pridat', ['_token' => $token, 'url' => 'eshop.kavarnadobra.cz', 'platform' => 'wordpress']);
        $site = $kernel->sites()->findByUrl('https://eshop.kavarnadobra.cz');
        assertSame('kavarnadobra.cz', $kernel->projectServices()->forProject((int) $site['project_id'])[0]['name']);

        Urls::reset();
    },
];
