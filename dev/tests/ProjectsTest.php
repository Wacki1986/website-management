<?php

declare(strict_types=1);

/**
 * Projekty: převod stávajících webů migrací, klient drží projekt a weby
 * mají jeho kopii, přidání a přesun webu, smazání jen prázdného projektu,
 * přiřazení projektů klientovi.
 */

use App\Controllers\SiteController;
use App\Core\Events\EventLog;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'migrace dá každému webu v monitoringu vlastní projekt, odebrané vynechá a podruhé nic nezdvojí' => function (): void {
        [$kernel] = loggedInKernel();
        $db = $kernel->db();
        $lev = $kernel->clients()->create(['name' => 'Zlatý Lev a.s.']);
        $hotel = $kernel->sites()->create(['name' => 'Hotel', 'url' => 'https://hotelzlatylev.cz', 'client_id' => $lev]);
        $blog = $kernel->sites()->create(['name' => 'Blog', 'url' => 'https://blog.cz']);
        $old = $kernel->sites()->create(['name' => 'Starý', 'url' => 'https://stary.cz']);
        $kernel->sites()->update($old, ['removed_at' => '2026-09-01 10:00:00']);

        $migration = require WWW_ROOT . '/database/migrations/2026_10_05_000000_projects.php';
        $migration($db->pdo());
        $migration($db->pdo());

        assertSame(2, $kernel->projects()->count(), 'Odebraný web projekt nedostane, druhý běh nic nepřidá');
        $hotelSite = $kernel->sites()->find($hotel);
        $project = $kernel->projects()->find((int) $hotelSite['project_id']);
        assertSame('Hotel', $project['name']);
        assertSame($lev, (int) $project['client_id'], 'Projekt převezme klienta webu');
        assertSame(null, $kernel->projects()->find((int) $kernel->sites()->find($blog)['project_id'])['client_id']);
        assertSame(null, $kernel->sites()->find($old)['project_id']);

        Urls::reset();
    },

    'klient projektu se propíše do webů; přiřazení webu převezme klienta; archivace klienta odpojí projekty' => function (): void {
        [$kernel] = loggedInKernel();
        $projects = $kernel->projects();
        $lev = $kernel->clients()->create(['name' => 'Zlatý Lev a.s.']);
        $beran = $kernel->clients()->create(['name' => 'Jan Beran']);
        $siteA = $kernel->sites()->create(['name' => 'Hotel', 'url' => 'https://hotelzlatylev.cz']);
        $siteB = $kernel->sites()->create(['name' => 'Rezervace', 'url' => 'https://rezervace.hotelzlatylev.cz']);

        $projectId = $projects->createForSite($siteA, 'hotelzlatylev.cz', $lev);
        assertSame($lev, (int) $kernel->sites()->find($siteA)['client_id']);

        $projects->attachSite($siteB, $projectId);
        assertSame($projectId, (int) $kernel->sites()->find($siteB)['project_id']);
        assertSame($lev, (int) $kernel->sites()->find($siteB)['client_id'], 'Web převezme klienta projektu');

        $projects->update($projectId, ['client_id' => $beran]);
        assertSame($beran, (int) $kernel->sites()->find($siteA)['client_id']);
        assertSame($beran, (int) $kernel->sites()->find($siteB)['client_id']);
        assertSame(2, count($kernel->sites()->forProject($projectId)));

        $kernel->clients()->archive($beran);
        assertSame(null, $projects->find($projectId)['client_id']);
        assertSame(null, $kernel->sites()->find($siteA)['client_id']);
        assertSame(1, count($projects->unassigned()));

        Urls::reset();
    },

    'přidání webu: bez projektu založí nový s vybraným klientem, s projektem se do něj zařadí' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $lev = $kernel->clients()->create(['name' => 'Zlatý Lev a.s.']);

        kernelRequest($kernel, 'POST', '/weby/pridat', ['_token' => $token, 'url' => 'hotelzlatylev.cz', 'name' => 'Hotel', 'client_id' => (string) $lev]);
        $hotel = $kernel->sites()->findByUrl('https://hotelzlatylev.cz');
        $project = $kernel->projects()->find((int) $hotel['project_id']);
        assertSame('Hotel', $project['name']);
        assertSame($lev, (int) $project['client_id']);

        // Klient z formuláře se u existujícího projektu nepoužije — rozhoduje projekt.
        $beran = $kernel->clients()->create(['name' => 'Jan Beran']);
        kernelRequest($kernel, 'POST', '/weby/pridat', ['_token' => $token, 'url' => 'rezervace.hotelzlatylev.cz', 'project_id' => (string) $project['id'], 'client_id' => (string) $beran]);
        $booking = $kernel->sites()->findByUrl('https://rezervace.hotelzlatylev.cz');
        assertSame((int) $project['id'], (int) $booking['project_id']);
        assertSame($lev, (int) $booking['client_id']);
        assertSame(1, $kernel->projects()->count());

        // Formulář z detailu projektu má projekt předvyplněný.
        assertContainsString('<option value="' . (int) $project['id'] . '" selected>', kernelRequest($kernel, 'GET', '/weby/pridat', [], ['projekt' => (string) $project['id']])->body());

        Urls::reset();
    },

    'nastavení webu: přesun do jiného projektu, oddělení do nového, bez volby se nic nemění' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $projects = $kernel->projects();
        $lev = $kernel->clients()->create(['name' => 'Zlatý Lev a.s.']);
        $site = $kernel->sites()->create(['name' => 'Rezervace', 'url' => 'https://rezervace.cz']);
        $own = $projects->createForSite($site, 'Rezervace', null);
        $hotel = $projects->create(['name' => 'hotelzlatylev.cz', 'client_id' => $lev]);
        $save = static fn (array $extra): int => kernelRequest($kernel, 'POST', '/weby/' . $site . '/nastaveni', ['_token' => $token, 'url' => 'https://rezervace.cz', 'name' => 'Rezervace'] + $extra)->status();

        assertContainsString('name="project_id"', kernelRequest($kernel, 'GET', '/weby/' . $site . '/nastaveni')->body());

        assertSame(302, $save([]));
        assertSame($own, (int) $kernel->sites()->find($site)['project_id'], 'Formulář bez volby projektu web nepřesouvá');

        $save(['project_id' => (string) $hotel]);
        assertSame($hotel, (int) $kernel->sites()->find($site)['project_id']);
        assertSame($lev, (int) $kernel->sites()->find($site)['client_id']);
        $event = $kernel->db()->selectOne('SELECT message FROM events WHERE site_id = :id AND kind = :kind ORDER BY id DESC', ['id' => $site, 'kind' => EventLog::KIND_SETTINGS]);
        assertSame('Web přesunut do projektu hotelzlatylev.cz', $event['message']);

        $save(['project_id' => SiteController::NEW_PROJECT]);
        $split = $kernel->projects()->find((int) $kernel->sites()->find($site)['project_id']);
        assertTrue(!in_array((int) $split['id'], [$own, $hotel], true), 'Oddělení založí nový projekt');
        assertSame($lev, (int) $split['client_id'], 'Nový projekt si nese dosavadního klienta');

        $save(['project_id' => '99999']);
        assertSame((int) $split['id'], (int) $kernel->sites()->find($site)['project_id'], 'Neznámý projekt nic nemění');

        Urls::reset();
    },

    'projekt s weby nejde smazat, prázdný ano; seznam, detail a hlavička webu ho ukazují' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $site = $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $project = $kernel->projects()->createForSite($site, 'kavarnadobra.cz', null);

        $list = kernelRequest($kernel, 'GET', '/projekty')->body();
        assertContainsString('kavarnadobra.cz', $list);
        assertContainsString('href="/projekty/' . $project . '"', $list);
        assertContainsString('Bez klienta <span class="pill__note">1</span>', $list);
        assertContainsString('href="/weby/' . $site . '"', kernelRequest($kernel, 'GET', '/projekty/' . $project)->body());
        assertContainsString('href="/projekty/' . $project . '"', kernelRequest($kernel, 'GET', '/weby/' . $site)->body());

        kernelRequest($kernel, 'POST', '/projekty/' . $project . '/smazat', ['_token' => $token]);
        assertTrue($kernel->projects()->find($project) !== null, 'Projekt s webem v monitoringu zůstává');

        $response = kernelRequest($kernel, 'POST', '/projekty/pridat', ['_token' => $token, 'name' => 'pekarnanovak.cz']);
        assertSame(302, $response->status());
        $empty = (int) $kernel->db()->scalar("SELECT id FROM projects WHERE name = 'pekarnanovak.cz'");
        assertContainsString('data-confirm="project-delete"', kernelRequest($kernel, 'GET', '/projekty/' . $empty)->body());

        kernelRequest($kernel, 'POST', '/projekty/' . $empty . '/smazat', ['_token' => $token]);
        assertSame(null, $kernel->projects()->find($empty));
        assertSame(422, kernelRequest($kernel, 'POST', '/projekty/pridat', ['_token' => $token, 'name' => ''])->status());

        Urls::reset();
    },

    'klient: nový i existující dostane projekty bez klienta, jejich weby s nimi' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $site = $kernel->sites()->create(['name' => 'Truhlářství', 'url' => 'https://truhlarstvi-beran.cz']);
        $first = $kernel->projects()->createForSite($site, 'truhlarstvi-beran.cz', null);
        $second = $kernel->projects()->create(['name' => 'beran-nabytek.cz']);

        assertContainsString('name="projects[]" value="' . $first . '"', kernelRequest($kernel, 'GET', '/klienti/pridat')->body());

        kernelRequest($kernel, 'POST', '/klienti/pridat', ['_token' => $token, '_action' => 'save', 'name' => 'Jan Beran', 'last_name' => 'Beran', 'email' => 'jan@beran.cz', 'projects' => [(string) $first]]);
        $clientId = (int) $kernel->db()->scalar("SELECT id FROM clients WHERE name = 'Jan Beran'");
        assertSame($clientId, (int) $kernel->projects()->find($first)['client_id']);
        assertSame($clientId, (int) $kernel->sites()->find($site)['client_id']);

        $detail = kernelRequest($kernel, 'GET', '/klienti/' . $clientId)->body();
        assertContainsString('href="/projekty/' . $first . '"', $detail);
        assertContainsString('name="projects[]" value="' . $second . '"', $detail);

        kernelRequest($kernel, 'POST', '/klienti/' . $clientId . '/projekty', ['_token' => $token, 'projects' => [(string) $second]]);
        assertSame($clientId, (int) $kernel->projects()->find($second)['client_id']);
        assertSame(2, count($kernel->projects()->forClient($clientId)));

        Urls::reset();
    },
];
