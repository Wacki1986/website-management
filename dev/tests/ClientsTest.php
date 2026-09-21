<?php

declare(strict_types=1);

/**
 * Klienti: hlavní kontakt, počty webů, archivace a načtení z ARESu
 * (parsování odpovědi bez sítě).
 */

use App\Core\Clients\Ares;
use App\Core\Clients\ClientRepository;
use App\Core\Security\Secrets;
use App\Core\Sites\SiteRepository;

return [
    'hlavní kontakt se promítne do řádku klienta a jde přepsat' => function (): void {
        $db = freshTestDb();
        $clients = new ClientRepository($db);
        $id = $clients->create(['name' => 'Kavárna Dobrá s.r.o.', 'company_id' => '12345678']);

        $clients->savePrimaryContact($id, ['first_name' => 'Markéta', 'last_name' => 'Dobrá', 'role' => 'majitelka', 'email' => 'info@kavarnadobra.cz', 'phone' => '+420 731 442 108']);
        assertSame('info@kavarnadobra.cz', $clients->find($id)['email']);
        assertSame(1, count($clients->contacts($id)));

        $clients->savePrimaryContact($id, ['first_name' => 'Markéta', 'last_name' => 'Dobrá', 'role' => 'majitelka', 'email' => 'marketa@kavarnadobra.cz', 'phone' => '']);
        assertSame('marketa@kavarnadobra.cz', $clients->find($id)['email']);
        assertSame(1, count($clients->contacts($id)), 'Přepis nesmí založit druhý hlavní kontakt');

        $contactId = $clients->addContact($id, ['first_name' => 'Jan', 'last_name' => 'Účetní', 'role' => 'účetní', 'email' => 'ucto@kavarnadobra.cz', 'phone' => '']);
        assertSame(2, count($clients->contacts($id)));
        assertSame('Markéta', $clients->contacts($id)[0]['first_name'], 'Hlavní kontakt je první');

        $clients->removeContact($id, $contactId);
        assertSame(1, count($clients->contacts($id)));
        $clients->removeContact($id, (int) $clients->contacts($id)[0]['id']);
        assertSame(1, count($clients->contacts($id)), 'Hlavní kontakt se nemaže');
    },

    'seznam počítá weby, filtruje víc webů a archivace weby odpojí' => function (): void {
        $db = freshTestDb();
        $clients = new ClientRepository($db);
        $sites = new SiteRepository($db, new Secrets(str_repeat('cd', 32)));

        $lev = $clients->create(['name' => 'Zlatý Lev a.s.', 'vat_id' => 'CZ25604418']);
        $osvc = $clients->create(['name' => 'Jan Beran']);
        $sites->create(['name' => 'Hotel', 'url' => 'https://hotelzlatylev.cz', 'client_id' => $lev]);
        $sites->create(['name' => 'Rezervace', 'url' => 'https://rezervace.hotelzlatylev.cz', 'client_id' => $lev]);
        $sites->create(['name' => 'Truhlářství', 'url' => 'https://truhlarstvi-beran.cz', 'client_id' => $osvc]);

        $rows = $clients->all();
        assertSame(2, count($rows));
        assertSame(1, count($clients->all(['filter' => 'multi'])));
        assertSame('Zlatý Lev a.s.', $clients->all(['filter' => 'multi'])[0]['name']);
        assertSame(1, count($clients->all(['filter' => 'person'])));
        assertSame(1, count($clients->all(['q' => 'beran'])));

        $clients->archive($lev);
        assertSame(1, count($clients->all()));
        assertSame(2, count($sites->unassigned()), 'Weby zůstávají v monitoringu bez klienta');
        assertSame(1, count($clients->options()), 'Nabídka výběru nemá archivované');
    },

    'ARES: IČO se doplní nulami, odpověď se přeloží na název, DIČ a adresu' => function (): void {
        assertSame('00012345', Ares::normalizeIco('12345'));
        assertSame('25604418', Ares::normalizeIco('256 04 418'));
        assertSame('', Ares::normalizeIco('123456789'));

        $ares = new Ares(fetcher: static fn (string $url): array => [
            'status' => str_ends_with($url, '/25604418') ? 200 : 404,
            'body' => json_encode(['ico' => '25604418', 'obchodniJmeno' => 'Zlatý Lev a.s.', 'dic' => 'CZ25604418',
                'sidlo' => ['textovaAdresa' => 'Zámecká 1, 46001 Liberec']]),
        ]);

        $found = $ares->lookup('25604418');
        assertSame('Zlatý Lev a.s.', $found['name']);
        assertSame('CZ25604418', $found['vat_id']);
        assertSame('Zámecká 1, 46001 Liberec', $found['address']);
        assertSame(null, $ares->lookup('11111111'));
        assertSame(null, $ares->lookup(''));
    },
];
