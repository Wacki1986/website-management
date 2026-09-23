<?php

declare(strict_types=1);

/**
 * Úkoly servisu: seznamy k druhům (Nastavení → Servis), checklist
 * v zápisu servisu, úprava zapsaného servisu.
 *
 * Hlavní pravidlo: zápis si nese **kopii** seznamu — změna seznamu
 * v nastavení starý zápis nepřepíše.
 */

use App\Core\Kernel;
use App\Core\Service\ServiceChecklists;
use App\Core\View\Urls;

require_once __DIR__ . '/fixtures/logged-in-kernel.php';

/** @return array{0: Kernel, 1: string, 2: int} kernel s přihlášeným technikem, CSRF token, id webu */
function scKernel(): array
{
    [$kernel, $token] = loggedInKernel('Technik');

    return [$kernel, $token, $kernel->sites()->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz'])];
}

/** @return array<string, mixed> jediný zápis servisu v databázi */
function scOnlyLog(Kernel $kernel): array
{
    $logs = $kernel->db()->select('SELECT * FROM service_logs');
    assertSame(1, count($logs));

    return $logs[0];
}

return [
    'seznam z textu: úkol na řádek, bez prázdných řádků a opakování' => function (): void {
        assertSame(['Záloha', 'Pluginy'], ServiceChecklists::parse("  Záloha \n\n Pluginy\r\nZáloha\n"));
        assertSame(30, count(ServiceChecklists::parse(implode("\n", array_map(static fn (int $i): string => 'Úkol ' . $i, range(1, 40))))));

        $items = ServiceChecklists::build(['A', 'B', 'C'], ['0', '2', 'x']);
        assertSame([['label' => 'A', 'done' => true], ['label' => 'B', 'done' => false], ['label' => 'C', 'done' => true]], $items);
        assertSame($items, ServiceChecklists::decode(ServiceChecklists::encode($items)));
        assertSame('2 z 3 úkolů', ServiceChecklists::progress($items));
        assertSame('', ServiceChecklists::progress(null));
        assertSame(null, ServiceChecklists::encode([]));
    },

    'nastavení: výchozí seznamy, uložení vlastních, prázdný seznam = bez checklistu' => function (): void {
        [$kernel, $token] = scKernel();

        $html = kernelRequest($kernel, 'GET', '/nastaveni/servis')->body();
        assertContainsString('Kontrola zálohy', $html);
        assertContainsString('name="checklist_large"', $html);

        $response = kernelRequest($kernel, 'POST', '/nastaveni/servis', [
            '_token' => $token,
            'checklist_small' => "Aktualizace\nZáloha\n",
            'checklist_medium' => '',
            'checklist_large' => "Rychlost",
        ]);
        assertSame(302, $response->status());

        $checklists = $kernel->serviceChecklists();
        assertSame(['Aktualizace', 'Záloha'], $checklists->template('small'));
        assertSame([], $checklists->template('medium'), 'Uložený prázdný seznam se nesmí vrátit k výchozímu');
        assertSame(['Rychlost'], $checklists->template('large'));

        Urls::reset();
    },

    'zápis servisu: checklist vybraného druhu se uloží i s odškrtnutím' => function (): void {
        [$kernel, $token, $siteId] = scKernel();
        $kernel->serviceChecklists()->save('small', "Aktualizace\nZáloha\nFormuláře");

        $form = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/zapsat')->body();
        assertContainsString('name="done[small][]" value="2"', $form);
        assertContainsString('service-checklist--medium', $form, 'Checklisty ostatních druhů jsou ve formuláři taky');

        $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/servis/zapsat', [
            '_token' => $token,
            'kind' => 'small',
            'performed_on' => '2026-09-20',
            'minutes' => '45',
            'description' => 'Aktualizace a kontrola zálohy',
            'status' => 'done',
            // Odškrtnutí u jiného druhu se zahodí.
            'done' => ['small' => ['0', '1'], 'medium' => ['0']],
        ]);
        assertSame(302, $response->status());

        $log = scOnlyLog($kernel);
        assertSame([
            ['label' => 'Aktualizace', 'done' => true],
            ['label' => 'Záloha', 'done' => true],
            ['label' => 'Formuláře', 'done' => false],
        ], ServiceChecklists::decode($log['checklist']));

        assertContainsString('2 z 3 úkolů', kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis')->body());

        Urls::reset();
    },

    'úprava zápisu: doodškrtnout a dopsat; změna seznamu v nastavení starý zápis nemění' => function (): void {
        [$kernel, $token, $siteId] = scKernel();
        $kernel->serviceChecklists()->save('small', "Aktualizace\nZáloha");
        $plan = ['is_active' => true, 'kind' => 'small', 'frequency' => 'monthly', 'first_date' => '2026-09-01'];
        $kernel->service()->savePlan($siteId, $plan, '2026-09-20');

        kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/servis/zapsat', [
            '_token' => $token, 'kind' => 'small', 'performed_on' => '2026-09-20', 'minutes' => '30',
            'description' => 'Aktualizace', 'status' => 'done', 'done' => ['small' => ['0']],
        ]);
        $log = scOnlyLog($kernel);
        $nextAfterStore = $kernel->service()->plan($siteId)['next_date'];

        // Seznam v nastavení se mezitím změní.
        $kernel->serviceChecklists()->save('small', "Úplně jiný úkol");

        $form = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/' . (int) $log['id'] . '/upravit')->body();
        assertContainsString('Upravit záznam servisu', $form);
        assertContainsString('Záloha', $form);
        assertFalse(str_contains($form, 'Úplně jiný úkol'), 'Formulář úpravy ukazuje kopii seznamu ze zápisu, ne nový seznam');

        $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/servis/' . (int) $log['id'] . '/upravit', [
            '_token' => $token, 'kind' => 'small', 'performed_on' => '2026-09-20', 'minutes' => '40',
            'description' => 'Aktualizace a záloha', 'status' => 'done', 'done' => ['small' => ['0', '1']],
        ]);
        assertSame(302, $response->status());

        $edited = scOnlyLog($kernel);
        assertSame('Aktualizace a záloha', $edited['description']);
        assertSame(40, (int) $edited['minutes']);
        assertSame('Technik', $edited['user_name'], 'Autor zápisu se úpravou nemění');
        assertTrue($edited['updated_at'] !== null);
        assertSame([['label' => 'Aktualizace', 'done' => true], ['label' => 'Záloha', 'done' => true]], ServiceChecklists::decode($edited['checklist']));
        assertSame($nextAfterStore, $kernel->service()->plan($siteId)['next_date'], 'Úprava neposouvá plán');

        // Bez popisu se úprava neuloží a formulář drží odškrtnutí.
        $invalid = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/servis/' . (int) $log['id'] . '/upravit', [
            '_token' => $token, 'kind' => 'small', 'performed_on' => '2026-09-20', 'minutes' => '40',
            'description' => '', 'status' => 'done', 'done' => ['small' => ['1']],
        ]);
        assertSame(422, $invalid->status());
        assertContainsString('name="done[small][]" value="1" checked', $invalid->body());
        assertSame('Aktualizace a záloha', scOnlyLog($kernel)['description']);

        assertSame(404, kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/999999/upravit')->status());

        Urls::reset();
    },
];
