<?php

declare(strict_types=1);

/**
 * Kontaktní osoby sdílené mezi klienty: jedna osoba u dvou firem, změna
 * telefonu platí u obou, převod starých kontaktů se slučováním dvojníků
 * a stránky Kontaktní osoby, osoba a výběr osoby ve formuláři klienta.
 */

use App\Core\Clients\ClientRepository;
use App\Core\Clients\ContactRepository;
use App\Core\Clients\Phone;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

return [
    'telefon: české číslo po trojicích, devět číslic dostane +420, cizí zůstane' => function (): void {
        assertSame('+420 777 762 375', Phone::normalize('777762375'));
        assertSame('+420 603 213 408', Phone::normalize('603 213 408'));
        assertSame('+420 605 888 890', Phone::normalize('+420605888890'));
        assertSame('+420 605 888 890', Phone::normalize('00420 605-888-890'));
        assertSame('+420 605 888 890', Phone::normalize('420605888890'));
        assertSame('+421 905 123 456', Phone::normalize('+421905123456'));
        assertSame('+420 603 213 408', Phone::normalize(' +420 603 213 408 '));
        assertSame('+49 30 1234567', Phone::normalize('+49 30 1234567'), 'Cizí předvolba zůstane');
        assertSame('603 213 408 (odpoledne)', Phone::normalize('603 213 408 (odpoledne)'), 'Poznámka u čísla zůstane');
        assertSame('12345', Phone::normalize('12345'), 'Krátká linka zůstane');
        assertSame('', Phone::normalize(''));
    },

    'telefon se srovná při uložení i migrací u starých kontaktů' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $contacts = $kernel->contacts();

        kernelRequest($kernel, 'POST', '/klienti/kontakty/pridat', ['_token' => $token, 'first_name' => 'Eva', 'last_name' => 'Nová', 'email' => 'eva@nova.cz', 'phone' => '777762375']);
        assertSame('+420 777 762 375', $contacts->find((int) $kernel->db()->scalar("SELECT id FROM contacts WHERE email = 'eva@nova.cz'"))['phone']);

        $old = $contacts->create(['first_name' => 'Jan', 'last_name' => 'Starý', 'email' => '', 'phone' => '+420605888890']);
        $foreign = $contacts->create(['first_name' => 'Hans', 'last_name' => 'Müller', 'email' => '', 'phone' => '+49 30 1234567']);
        (require WWW_ROOT . '/database/migrations/2026_10_30_000001_contact_phones.php')($kernel->db()->pdo());
        assertSame('+420 605 888 890', $contacts->find($old)['phone']);
        assertSame('+49 30 1234567', $contacts->find($foreign)['phone']);
    },

    'jedna osoba u dvou firem: změna telefonu platí u obou, role zvlášť' => function (): void {
        $db = freshTestDb();
        $clients = new ClientRepository($db);
        $contacts = new ContactRepository($db);
        $kavarna = $clients->create(['name' => 'Kavárna Dobrá s.r.o.']);
        $pekarna = $clients->create(['name' => 'Pekárna Dobrá s.r.o.']);

        $marketa = $contacts->create(['first_name' => 'Markéta', 'last_name' => 'Dobrá', 'email' => 'marketa@dobra.cz', 'phone' => '+420 111']);
        $contacts->setPrimary($kavarna, $marketa, 'jednatelka');
        $contacts->setPrimary($pekarna, $marketa, 'majitelka');

        $contacts->update($marketa, ['first_name' => 'Markéta', 'last_name' => 'Dobrá', 'email' => 'marketa@dobra.cz', 'phone' => '+420 222']);

        assertSame('+420 222', $contacts->primaryFor($kavarna)['phone']);
        assertSame('+420 222', $contacts->primaryFor($pekarna)['phone']);
        assertSame('jednatelka', $contacts->primaryFor($kavarna)['role']);
        assertSame('majitelka', $contacts->primaryFor($pekarna)['role']);
        assertSame(['Pekárna Dobrá s.r.o.'], array_column($contacts->primaryFor($kavarna)['others'], 'name'));
        assertSame(1, $contacts->count());
        assertSame(2, count($contacts->all()[0]['clients']));
        assertSame(1, count($contacts->all('pekárna')), 'Hledá se i podle firmy');
        assertSame(0, count($contacts->all('nikdo')));
    },

    'nová hlavní osoba: původní zůstane jako další kontakt; hlavní kontakt nejde smazat' => function (): void {
        $db = freshTestDb();
        $clients = new ClientRepository($db);
        $contacts = new ContactRepository($db);
        $id = $clients->create(['name' => 'Zlatý Lev a.s.']);
        $old = $contacts->create(['first_name' => 'Petr', 'last_name' => 'Starý', 'email' => 'petr@lev.cz', 'phone' => '']);
        $new = $contacts->create(['first_name' => 'Eva', 'last_name' => 'Nová', 'email' => 'eva@lev.cz', 'phone' => '']);

        $contacts->setPrimary($id, $old, '');
        $contacts->setPrimary($id, $new, 'ředitelka');

        $rows = $contacts->forClient($id);
        assertSame(2, count($rows));
        assertSame('Eva', $rows[0]['first_name']);
        assertSame(1, (int) $rows[0]['is_primary']);
        assertSame(0, (int) $rows[1]['is_primary'], 'Původní hlavní osoba je teď další kontakt');

        assertFalse($contacts->delete($new), 'Hlavní kontakt se nemaže');
        assertSame(['Zlatý Lev a.s.'], $contacts->primaryAt($new));
        assertTrue($contacts->delete($old));
        assertSame(1, count($contacts->forClient($id)), 'Smazaná osoba zmizí i od klienta');

        // Archivovaný klient osobu nedrží — pro aplikaci už neexistuje.
        $clients->archive($id);
        assertSame([], $contacts->primaryAt($new));
        assertTrue($contacts->delete($new));
    },

    'stejné jméno a e-mail = stejná osoba, jinak nová' => function (): void {
        $contacts = new ContactRepository(freshTestDb());
        $first = $contacts->findOrCreate(['first_name' => 'Jan', 'last_name' => 'Novák', 'email' => 'jan@novak.cz', 'phone' => '']);

        assertSame($first, $contacts->findOrCreate(['first_name' => 'jan', 'last_name' => 'NOVÁK', 'email' => 'jan@novak.cz', 'phone' => '+420 333']));
        assertSame('+420 333', $contacts->find($first)['phone'], 'Chybějící telefon se doplní');
        assertSame($first, $contacts->findOrCreate(['first_name' => 'Jan', 'last_name' => 'Novák', 'email' => 'jan@novak.cz', 'phone' => '+420 999']));
        assertSame('+420 333', $contacts->find($first)['phone'], 'Vyplněný telefon se přidáním nepřepíše');

        assertTrue($first !== $contacts->findOrCreate(['first_name' => 'Jana', 'last_name' => 'Nováková', 'email' => 'jan@novak.cz', 'phone' => '']), 'Jiné jméno = jiná osoba');
        $noEmail = $contacts->findOrCreate(['first_name' => 'Jan', 'last_name' => 'Novák', 'email' => '', 'phone' => '']);
        assertTrue($noEmail !== $contacts->findOrCreate(['first_name' => 'Jan', 'last_name' => 'Novák', 'email' => '', 'phone' => '']), 'Bez e-mailu se neslučuje');
    },

    'migrace: staré kontakty převede a dvojníky ze dvou firem sloučí' => function (): void {
        $db = freshTestDb();
        $clients = new ClientRepository($db);
        $kavarna = $clients->create(['name' => 'Kavárna Dobrá s.r.o.']);
        $pekarna = $clients->create(['name' => 'Pekárna Dobrá s.r.o.']);
        $now = date('Y-m-d H:i:s');
        $old = static fn (int $client, string $first, string $last, string $email, string $phone, string $role, int $primary) => $db->insert('client_contacts', [
            'client_id' => $client, 'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'phone' => $phone, 'role' => $role, 'is_primary' => $primary, 'created_at' => $now,
        ]);

        $old($kavarna, 'Markéta', 'Dobrá', 'marketa@dobra.cz', '', 'jednatelka', 1);
        $old($pekarna, 'Markéta', 'Dobrá', 'Marketa@Dobra.cz', '+420 111', 'majitelka', 1);
        $old($kavarna, 'Jan', 'Účetní', 'ucto@dobra.cz', '', 'účetní', 0);
        $old($pekarna, 'Jan', 'Novák', '', '', '', 0);
        $old($kavarna, 'Jan', 'Novák', '', '', '', 0);

        (require WWW_ROOT . '/database/migrations/2026_10_30_000000_contacts.php')($db->pdo());

        $contacts = new ContactRepository($db);
        assertSame(4, $contacts->count(), 'Markéta jednou, Jan Účetní, dva Jan Novák bez e-mailu');
        $marketa = $contacts->primaryFor($kavarna);
        assertSame((int) $marketa['id'], (int) $contacts->primaryFor($pekarna)['id']);
        assertSame('+420 111', $marketa['phone'], 'Telefon se doplní z dvojníka');
        assertSame('jednatelka', $marketa['role']);
        assertSame('majitelka', $contacts->primaryFor($pekarna)['role']);
        assertSame(3, count($contacts->forClient($kavarna)));

        // Druhé spuštění nad naplněnou tabulkou nic nezdvojí.
        (require WWW_ROOT . '/database/migrations/2026_10_30_000000_contacts.php')($db->pdo());
        assertSame(4, $contacts->count());
    },

    'formulář klienta: vybraná osoba se nezaloží znovu a úprava platí u obou firem' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $contacts = $kernel->contacts();

        $first = ['_token' => $token, '_action' => 'save', 'name' => 'Kavárna Dobrá s.r.o.', 'first_name' => 'Markéta', 'last_name' => 'Dobrá', 'role' => 'jednatelka', 'email' => 'marketa@dobra.cz', 'phone' => '+420 111'];
        assertSame(302, kernelRequest($kernel, 'POST', '/klienti/pridat', $first)->status());
        $kavarna = (int) $kernel->db()->scalar("SELECT id FROM clients WHERE name = 'Kavárna Dobrá s.r.o.'");
        $marketa = (int) $contacts->primaryFor($kavarna)['id'];

        $form = kernelRequest($kernel, 'GET', '/klienti/pridat')->body();
        assertContainsString('data-contact-pick="edit"', $form);
        assertContainsString('Markéta Dobrá · marketa@dobra.cz', $form, 'Osoba je v datech našeptávače');

        $second = ['_token' => $token, '_action' => 'save', 'name' => 'Pekárna Dobrá s.r.o.', 'contact_id' => (string) $marketa,
            'first_name' => 'Markéta', 'last_name' => 'Dobrá', 'role' => 'majitelka', 'email' => 'marketa@dobra.cz', 'phone' => '+420 222'];
        assertSame(302, kernelRequest($kernel, 'POST', '/klienti/pridat', $second)->status());
        $pekarna = (int) $kernel->db()->scalar("SELECT id FROM clients WHERE name = 'Pekárna Dobrá s.r.o.'");

        assertSame(1, $contacts->count(), 'Osoba je v kontaktech jednou');
        assertSame('+420 222', $contacts->primaryFor($kavarna)['phone'], 'Nový telefon platí i u první firmy');
        assertSame('jednatelka', $contacts->primaryFor($kavarna)['role']);
        assertSame('majitelka', $contacts->primaryFor($pekarna)['role']);

        assertContainsString('value="Markéta Dobrá · marketa@dobra.cz"', kernelRequest($kernel, 'GET', '/klienti/' . $pekarna . '/upravit')->body(), 'Vybraná osoba v poli hledání');
        $detail = kernelRequest($kernel, 'GET', '/klienti/' . $kavarna)->body();
        assertContainsString('Kontaktní osoba je i u:', $detail);
        assertContainsString('Pekárna Dobrá s.r.o.', $detail);
        assertContainsString('+420 222', kernelRequest($kernel, 'GET', '/klienti')->body());

        // Úprava klienta s „Nová osoba" — původní zůstane jako další kontakt.
        $edit = ['_token' => $token, '_action' => 'save', 'name' => 'Kavárna Dobrá s.r.o.', 'contact_id' => '',
            'first_name' => 'Petr', 'last_name' => 'Barista', 'role' => '', 'email' => 'petr@dobra.cz', 'phone' => ''];
        assertSame(302, kernelRequest($kernel, 'POST', '/klienti/' . $kavarna . '/upravit', $edit)->status());
        assertSame('Petr', $contacts->primaryFor($kavarna)['first_name']);
        assertSame(2, count($contacts->forClient($kavarna)));
        assertSame('Markéta', $contacts->primaryFor($pekarna)['first_name'], 'U druhé firmy se nic nemění');
    },

    'Další kontakty: připojení osoby z kontaktů, odebrání ji v kontaktech nechá' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $contacts = $kernel->contacts();
        $kavarna = $kernel->clients()->create(['name' => 'Kavárna Dobrá s.r.o.']);
        $pekarna = $kernel->clients()->create(['name' => 'Pekárna Dobrá s.r.o.']);
        $jan = $contacts->create(['first_name' => 'Jan', 'last_name' => 'Účetní', 'email' => 'ucto@dobra.cz', 'phone' => '']);
        $contacts->link($kavarna, $jan, 'účetní');

        assertContainsString('data-contact-pick="link"', kernelRequest($kernel, 'GET', '/klienti/' . $pekarna)->body());
        assertSame(302, kernelRequest($kernel, 'POST', '/klienti/' . $pekarna . '/kontakt', ['_token' => $token, 'contact_id' => (string) $jan, 'role' => 'externí účetní'])->status());
        assertSame(1, $contacts->count());
        assertSame('externí účetní', $contacts->forClient($pekarna)[0]['role']);
        assertSame('účetní', $contacts->forClient($kavarna)[0]['role']);
        assertContainsString('také u Kavárna Dobrá s.r.o.', kernelRequest($kernel, 'GET', '/klienti/' . $pekarna)->body());

        // Nová osoba se stejným jménem a e-mailem = připojí se stávající.
        kernelRequest($kernel, 'POST', '/klienti/' . $pekarna . '/kontakt', ['_token' => $token, 'first_name' => 'Jan', 'last_name' => 'Účetní', 'email' => 'UCTO@dobra.cz']);
        assertSame(1, $contacts->count());

        kernelRequest($kernel, 'POST', '/klienti/' . $pekarna . '/kontakt/' . $jan . '/smazat', ['_token' => $token]);
        assertSame([], $contacts->forClient($pekarna));
        assertSame(1, count($contacts->forClient($kavarna)), 'U první firmy osoba zůstává');
    },

    'stránky Kontaktní osoby: seznam, nová osoba, úprava rolí a smazání' => function (): void {
        [$kernel, $token] = loggedInKernel();
        $contacts = $kernel->contacts();
        $kavarna = $kernel->clients()->create(['name' => 'Kavárna Dobrá s.r.o.']);

        $list = kernelRequest($kernel, 'GET', '/klienti/kontakty');
        assertSame(200, $list->status());
        assertContainsString('Zatím žádná kontaktní osoba', $list->body());
        assertContainsString('Kontaktní osoby', kernelRequest($kernel, 'GET', '/klienti')->body(), 'Záložka u seznamu klientů');
        assertSame(200, kernelRequest($kernel, 'GET', '/klienti/kontakty/pridat')->status());

        $store = kernelRequest($kernel, 'POST', '/klienti/kontakty/pridat', ['_token' => $token, 'first_name' => 'Eva', 'last_name' => 'Správcová', 'email' => 'eva@it.cz', 'client_id' => (string) $kavarna, 'role' => 'správce IT']);
        assertSame(302, $store->status());
        $eva = (int) $kernel->db()->scalar("SELECT id FROM contacts WHERE email = 'eva@it.cz'");
        assertSame('správce IT', $contacts->forClient($kavarna)[0]['role']);

        $same = kernelRequest($kernel, 'POST', '/klienti/kontakty/pridat', ['_token' => $token, 'first_name' => 'Eva', 'last_name' => 'Správcová', 'email' => 'eva@it.cz']);
        assertSame(422, $same->status(), 'Dvojník se nezaloží');
        assertContainsString('už v kontaktech je', $same->body());
        assertSame(422, kernelRequest($kernel, 'POST', '/klienti/kontakty/pridat', ['_token' => $token, 'first_name' => 'Bez'])->status());

        $page = kernelRequest($kernel, 'GET', '/klienti/kontakty/' . $eva);
        assertSame(200, $page->status());
        assertContainsString('name="roles[' . $kavarna . ']"', $page->body());

        kernelRequest($kernel, 'POST', '/klienti/kontakty/' . $eva, ['_token' => $token, 'first_name' => 'Eva', 'last_name' => 'Správcová', 'email' => 'eva@it.cz', 'phone' => '+420 444', 'roles' => [$kavarna => 'IT a web']]);
        assertSame('+420 444', $contacts->find($eva)['phone']);
        assertSame('IT a web', $contacts->forClient($kavarna)[0]['role']);
        assertContainsString('Kavárna Dobrá s.r.o.', kernelRequest($kernel, 'GET', '/klienti/kontakty', [], ['q' => 'eva'])->body());

        assertSame(302, kernelRequest($kernel, 'POST', '/klienti/kontakty/' . $eva . '/smazat', ['_token' => $token])->status());
        assertSame(null, $contacts->find($eva));
        assertSame(404, kernelRequest($kernel, 'GET', '/klienti/kontakty/' . $eva)->status());
    },
];
