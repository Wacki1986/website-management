<?php

declare(strict_types=1);

/**
 * Klienti: hlavní kontakt, počty webů, archivace a načtení z ARESu
 * (parsování odpovědi bez sítě).
 */

use App\Core\Clients\Ares;
use App\Core\Clients\ClientRepository;
use App\Core\Clients\ContactRepository;
use App\Core\Security\Secrets;
use App\Core\Sites\SiteRepository;

return [
    'hlavní kontakt: jeden na klienta, seznam ho ukazuje a nejde odebrat' => function (): void {
        $db = freshTestDb();
        $clients = new ClientRepository($db);
        $contacts = new ContactRepository($db);
        $id = $clients->create(['name' => 'Kavárna Dobrá s.r.o.', 'company_id' => '12345678']);

        $marketa = $contacts->create(['first_name' => 'Markéta', 'last_name' => 'Dobrá', 'email' => 'info@kavarnadobra.cz', 'phone' => '+420 731 442 108']);
        $contacts->setPrimary($id, $marketa, 'majitelka');
        assertSame('info@kavarnadobra.cz', $clients->all()[0]['contact_email']);
        assertSame('majitelka', $clients->all()[0]['contact_role']);
        assertSame(1, count($contacts->forClient($id)));

        $contacts->setPrimary($id, $marketa, 'jednatelka');
        assertSame(1, count($contacts->forClient($id)), 'Opakované nastavení nesmí založit druhou vazbu');
        assertSame('jednatelka', $contacts->primaryFor($id)['role']);

        $jan = $contacts->create(['first_name' => 'Jan', 'last_name' => 'Účetní', 'email' => 'ucto@kavarnadobra.cz', 'phone' => '']);
        $contacts->link($id, $jan, 'účetní');
        assertSame(2, count($contacts->forClient($id)));
        assertSame('Markéta', $contacts->forClient($id)[0]['first_name'], 'Hlavní kontakt je první');

        assertTrue($contacts->unlink($id, $jan));
        assertSame(1, count($contacts->forClient($id)));
        assertTrue($contacts->find($jan) !== null, 'Odebraná osoba v kontaktech zůstává');
        assertFalse($contacts->unlink($id, $marketa), 'Hlavní kontakt se neodebírá');
        assertSame(1, count($contacts->forClient($id)));
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
