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
        assertSame([['label' => 'A', 'done' => true, 'extra' => false], ['label' => 'B', 'done' => false, 'extra' => false], ['label' => 'C', 'done' => true, 'extra' => false]], $items);
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
            ['label' => 'Aktualizace', 'done' => true, 'extra' => false],
            ['label' => 'Záloha', 'done' => true, 'extra' => false],
            ['label' => 'Formuláře', 'done' => false, 'extra' => false],
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
        assertSame([['label' => 'Aktualizace', 'done' => true, 'extra' => false], ['label' => 'Záloha', 'done' => true, 'extra' => false]], ServiceChecklists::decode($edited['checklist']));
        assertSame($nextAfterStore, $kernel->service()->plan($siteId)['next_date'], 'Úprava neposouvá plán');

        // Bez popisu i bez odškrtnutí se úprava neuloží a formulář drží vyplněné.
        $invalid = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/servis/' . (int) $log['id'] . '/upravit', [
            '_token' => $token, 'kind' => 'small', 'performed_on' => '2026-09-20', 'minutes' => '45',
            'description' => '', 'status' => 'done',
        ]);
        assertSame(422, $invalid->status());
        assertContainsString('value="45"', $invalid->body());
        assertSame('Aktualizace a záloha', scOnlyLog($kernel)['description']);

        // Popis je nepovinný — bez něj historie ukáže odškrtnuté úkoly.
        $response = kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/servis/' . (int) $log['id'] . '/upravit', [
            '_token' => $token, 'kind' => 'small', 'performed_on' => '2026-09-20', 'minutes' => '40',
            'description' => '', 'status' => 'done', 'done' => ['small' => ['0', '1']],
        ]);
        assertSame(302, $response->status());
        assertSame('', scOnlyLog($kernel)['description']);
        $page = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis')->body();
        assertContainsString('Aktualizace, záloha', $page);
        assertContainsString('title="Upravit záznam"', $page);
        assertFalse(str_contains($page, '45–60 min'), 'Odhady času druhů servisu jsou pryč');

        assertSame(404, kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/999999/upravit')->status());

        Urls::reset();
    },
    'vlastní úkoly: přidat k seznamu, přepsat, odebrat; report je vypíše pod sebe s poznámkou' => function (): void {
        [$kernel, $token, $siteId] = scKernel();
        $kernel->serviceChecklists()->save('small', "Aktualizace WordPressu\nZáloha");

        $form = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/zapsat')->body();
        assertContainsString('name="extra[small][new][label]"', $form);
        assertContainsString('data-task-add', $form);
        assertContainsString('Poznámka k servisu', $form);

        kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/servis/zapsat', [
            '_token' => $token, 'kind' => 'small', 'performed_on' => '2026-09-20', 'minutes' => '30', 'description' => '', 'status' => 'done',
            'done' => ['small' => ['0']],
            'extra' => ['small' => [
                't1' => ['label' => ' Oprava formuláře poptávky ', 'done' => '1'],
                't2' => ['label' => 'Nová galerie'],
                'new' => ['label' => '', 'done' => '1'],
            ]],
        ]);
        $log = scOnlyLog($kernel);
        assertSame([
            ['label' => 'Aktualizace WordPressu', 'done' => true, 'extra' => false],
            ['label' => 'Záloha', 'done' => false, 'extra' => false],
            ['label' => 'Oprava formuláře poptávky', 'done' => true, 'extra' => true],
            ['label' => 'Nová galerie', 'done' => false, 'extra' => true],
        ], ServiceChecklists::decode($log['checklist']));

        // Úprava: vlastní úkoly jsou pole s textem, úkoly ze seznamu jen zaškrtávátka.
        $edit = kernelRequest($kernel, 'GET', '/weby/' . $siteId . '/servis/' . (int) $log['id'] . '/upravit')->body();
        assertContainsString('name="extra[small][0][label]" value="Oprava formuláře poptávky"', $edit);
        assertContainsString('name="extra[small][1][label]" value="Nová galerie"', $edit);
        assertContainsString('name="done[small][]" value="1"', $edit);

        // Druhý vlastní úkol odebraný (bez skriptu smazáním textu), první přepsaný.
        kernelRequest($kernel, 'POST', '/weby/' . $siteId . '/servis/' . (int) $log['id'] . '/upravit', [
            '_token' => $token, 'kind' => 'small', 'performed_on' => '2026-09-20', 'minutes' => '30',
            'description' => 'Doporučujeme obnovit fotky v galerii.', 'status' => 'done',
            'done' => ['small' => ['0', '1']],
            'extra' => ['small' => ['0' => ['label' => 'Oprava formuláře poptávky a e-mailu', 'done' => '1'], '1' => ['label' => '']]],
        ]);
        $items = ServiceChecklists::decode(scOnlyLog($kernel)['checklist']);
        assertSame(3, count($items));
        assertSame('Oprava formuláře poptávky a e-mailu', $items[2]['label']);

        $summary = $kernel->reportBuilder()->build($kernel->sites()->findWithSnapshot($siteId), '2026-09-01', '2026-09-30', 'Září 2026', '2026-10-01');
        assertSame(['Aktualizace WordPressu', 'Záloha', 'Oprava formuláře poptávky a e-mailu'], $summary['services'][0]['tasks']);
        assertSame('Doporučujeme obnovit fotky v galerii.', $summary['services'][0]['note']);

        $options = ['sections' => ['services'], 'studio' => ['name' => 'X', 'email' => '', 'phone' => '']];
        $html = $kernel->reportRenderer()->body($summary, $options);
        assertContainsString('>Oprava formuláře poptávky a e-mailu</td>', $html);
        assertContainsString('Doporučujeme obnovit fotky v galerii.', $html);
        assertContainsString("  ✓ Záloha\n", $kernel->reportRenderer()->text($summary, $options));

        // Uložený report z doby před úkoly (jen `description`) se vykreslí jako dřív.
        $old = $summary;
        $old['services'][0] = ['date' => '2026-09-20', 'kind' => 'small', 'kindLabel' => 'Malý servis', 'description' => 'Aktualizace všeho.', 'minutes' => 30];
        assertContainsString('Aktualizace všeho.', $kernel->reportRenderer()->body($old, $options));

        Urls::reset();
    },
];
