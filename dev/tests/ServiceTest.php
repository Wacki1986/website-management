<?php

declare(strict_types=1);

/**
 * Servis: počítání termínů (čistá logika) a plán + historie v databázi.
 */

use App\Core\Security\Secrets;
use App\Core\Service\ServiceRepository;
use App\Core\Service\ServiceSchedule;
use App\Core\Sites\SiteRepository;

require_once __DIR__ . '/fixtures/monitor-fixture.php';

return [
    'termíny: měsíčně, čtvrtletně, pololetně, jednorázově' => function (): void {
        assertSame('2026-09-21', ServiceSchedule::next('2026-09-21', 'monthly', '2026-09-09'));
        assertSame('2026-10-21', ServiceSchedule::next('2026-09-21', 'monthly', '2026-09-22'));
        assertSame('2026-12-21', ServiceSchedule::next('2026-09-21', 'quarterly', '2026-09-22'));
        assertSame('2027-03-21', ServiceSchedule::next('2026-09-21', 'halfyearly', '2026-09-22'));
        assertSame('2026-09-21', ServiceSchedule::next('2026-09-21', 'once', '2026-09-09'));
        assertSame(null, ServiceSchedule::next('2026-09-21', 'once', '2026-09-22'));
        // Termín dnes = dnes, ne za měsíc.
        assertSame('2026-09-21', ServiceSchedule::next('2026-09-21', 'monthly', '2026-09-21'));
    },

    'konec měsíce se ořezává, ale rytmus se od 31. neodchýlí' => function (): void {
        assertSame('2026-02-28', ServiceSchedule::next('2026-01-31', 'monthly', '2026-02-01'));
        assertSame('2026-03-31', ServiceSchedule::next('2026-01-31', 'monthly', '2026-03-01'));
        assertSame(['2026-09-21', '2026-10-21', '2026-11-21'], ServiceSchedule::upcoming('2026-09-21', 'monthly', '2026-09-09'));
        assertSame(['2026-12-21', '2027-03-21', '2027-06-21'], ServiceSchedule::upcoming('2026-09-21', 'quarterly', '2026-09-22'));
        assertSame(['2026-09-21'], ServiceSchedule::upcoming('2026-09-21', 'once', '2026-09-09'));
    },

    'po provedeném servisu se termín posune na další v rytmu; posun o týden' => function (): void {
        assertSame('2026-10-21', ServiceSchedule::afterPerformed('2026-09-21', 'monthly', '2026-09-21'));
        // Servis o týden dřív plní nadcházející termín, rytmus zůstává.
        assertSame('2026-10-21', ServiceSchedule::afterPerformed('2026-09-21', 'monthly', '2026-09-14', '2026-09-21'));
        // Servis o měsíc zpožděný: další je ten po dni provedení.
        assertSame('2026-11-21', ServiceSchedule::afterPerformed('2026-09-21', 'monthly', '2026-10-25', '2026-09-21'));
        assertSame(null, ServiceSchedule::afterPerformed('2026-09-21', 'once', '2026-09-21'));
        assertSame('2026-09-28', ServiceSchedule::postpone('2026-09-21'));
        assertSame(12, ServiceSchedule::daysUntil('2026-09-21', '2026-09-09'));
        assertSame('za ' . get_count(12, 'den', 'dny', 'dní'), ServiceSchedule::countdown('2026-09-21', '2026-09-09'));
        assertSame('zítra', ServiceSchedule::countdown('2026-09-10', '2026-09-09'));
        assertSame('dnes', ServiceSchedule::countdown('2026-09-09', '2026-09-09'));
        assertSame('po termínu 5 d', ServiceSchedule::countdown('2026-09-04', '2026-09-09'));
    },

    'plán a historie v databázi: uložení, next_date, statistika, servis po termínu' => function (): void {
        $db = freshTestDb();
        $sites = new SiteRepository($db, new Secrets(str_repeat('ab', 32)));
        $service = new ServiceRepository($db);
        $id = $sites->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);

        $service->savePlan($id, ['is_active' => true, 'kind' => 'small', 'frequency' => 'monthly', 'first_date' => '2026-08-21'], '2026-09-09');
        $plan = $service->plan($id);
        assertSame('2026-09-21', $plan['next_date']);
        assertSame([$id => ['next_date' => '2026-09-21', 'kind' => 'small', 'is_active' => 1]], $service->plansFor([$id]));

        $service->addLog($id, ['performed_on' => '2026-09-01', 'kind' => 'small', 'description' => 'Aktualizace', 'minutes' => 45, 'status' => 'done', 'user_name' => 'Petra']);
        $service->addLog($id, ['performed_on' => '2026-08-04', 'kind' => 'medium', 'description' => 'Čištění DB', 'minutes' => 110, 'status' => 'done', 'user_name' => 'Petra']);
        $service->addLog($id, ['performed_on' => '2026-07-02', 'kind' => 'small', 'description' => 'Přeskočeno', 'minutes' => null, 'status' => 'skipped', 'user_name' => 'Petra']);

        assertSame(3, count($service->logs($id, 12, '2026-09-09')));
        assertSame(['count' => 2, 'minutes' => 155], $service->yearStats($id, '2026'));
        assertSame(2, count($service->logsBetween($id, '2026-08-01', '2026-09-30')));
        assertSame('2026-09-01', $service->lastDone($id)['performed_on']);

        // Po termínu: 21. 9. nezapsán do 25. 9.
        assertSame(1, count($service->overdue('2026-09-25')));
        assertSame(0, count($service->overdue('2026-09-20')));

        // Neplatný druh/opakování spadne na výchozí.
        $service->savePlan($id, ['is_active' => false, 'kind' => 'obr', 'frequency' => 'nikdy', 'first_date' => null], '2026-09-09');
        assertSame('small', $service->plan($id)['kind']);
        assertSame(null, $service->plan($id)['next_date']);
    },
    'pravidlo service_overdue: otevře po termínu + práh hodin, zavře po zapsání servisu' => function (): void {
        $f = monitorFixture();
        $service = new ServiceRepository($f['db']);
        $id = $f['sites']->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $site = $f['sites']->find($id);

        $service->savePlan($id, ['is_active' => true, 'kind' => 'small', 'frequency' => 'monthly', 'first_date' => '2026-09-21'], '2026-09-09');

        // Den termínu a den po něm (práh 24 h) ještě nic.
        $f['engine']->afterServicePlan($site, $service->plan($id), '2026-09-21 10:00:00');
        $f['engine']->afterServicePlan($site, $service->plan($id), '2026-09-21 23:00:00');
        assertSame(null, $f['alerts']->openOf($id, 'service_overdue'));

        // Dva dny po termínu → alert.
        $f['engine']->afterServicePlan($site, $service->plan($id), '2026-09-23 08:00:00');
        $open = $f['alerts']->openOf($id, 'service_overdue');
        assertSame('warning', $open['severity']);
        assertSame(1, count(glob($f['logDir'] . '/*.eml')));

        // Zapsaný servis posune termín → alert se zavře sám.
        $service->setNextDate($id, ServiceSchedule::afterPerformed('2026-09-21', 'monthly', '2026-09-23'));
        $f['engine']->afterServicePlan($site, $service->plan($id), '2026-09-23 09:00:00');
        assertSame(null, $f['alerts']->openOf($id, 'service_overdue'));
        assertSame('resolved', $f['alerts']->find((int) $open['id'])['status']);

        // Vypnuté pravidlo neotevírá.
        $f['settings']->set('rule_service_on', '0');
        $service->setNextDate($id, '2026-09-01');
        $f['engine']->afterServicePlan($site, $service->plan($id), '2026-09-23 09:00:00');
        assertSame(null, $f['alerts']->openOf($id, 'service_overdue'));

        // Buňka v seznamu.
        assertSame('nenaplánováno', ServiceSchedule::cell(null, '2026-09-09')['label']);
        assertSame(['label' => 'po termínu 5 d', 'tone' => 'error'], array_slice(ServiceSchedule::cell(['next_date' => '2026-09-04', 'is_active' => 1], '2026-09-09'), 0, 2));
    },
];
