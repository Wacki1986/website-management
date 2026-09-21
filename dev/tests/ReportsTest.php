<?php

declare(strict_types=1);

/**
 * Klientské reporty: termíny a období (čistá logika), souhrn z dat,
 * vykreslení e-mailu, odeslání přes log transport, fronta ke schválení,
 * sledovací pixel.
 */

use App\Core\Reports\ReportBuilder;
use App\Core\Reports\ReportRenderer;
use App\Core\Reports\ReportRepository;
use App\Core\Reports\ReportSchedule;
use App\Core\Reports\ReportSender;
use App\Core\Service\ServiceRepository;

require_once __DIR__ . '/fixtures/monitor-fixture.php';

/** @return array<string, mixed> sestava reportů nad monitorFixture() */
function reportsFixture(): array
{
    $f = monitorFixture();
    $reports = new ReportRepository($f['db']);
    $service = new ServiceRepository($f['db']);
    $builder = new ReportBuilder($f['sites'], $f['uptime'], $f['alerts'], $f['events'], $service, $f['security']);
    $renderer = new ReportRenderer();
    $sender = new ReportSender($reports, $builder, $renderer, $f['sites'], $f['mailer'], $f['mailSettings'], $f['settings'], $f['events'], $f['notifier'], $f['logger'], 'https://sprava.test');

    return $f + ['reports' => $reports, 'service' => $service, 'builder' => $builder, 'renderer' => $renderer, 'sender' => $sender];
}

/** @return array<int, string> */
function emlFiles(string $dir): array
{
    return glob($dir . '/*.eml') ?: [];
}

return [
    'termíny: měsíčně, čtvrtletně, týdně, ručně; přelom roku a února' => function (): void {
        assertSame('2026-10-01 06:00:00', ReportSchedule::nextSendAt('monthly', 1, 6, '2026-09-19 10:00:00'));
        assertSame('2026-10-01 06:00:00', ReportSchedule::nextSendAt('monthly', 1, 6, '2026-09-01 06:00:00'));
        assertSame('2026-09-01 06:00:00', ReportSchedule::nextSendAt('monthly', 1, 6, '2026-09-01 05:59:59'));
        assertSame('2027-01-01 06:00:00', ReportSchedule::nextSendAt('monthly', 1, 6, '2026-12-15 10:00:00'));
        assertSame('2026-10-01 06:00:00', ReportSchedule::nextSendAt('quarterly', 1, 6, '2026-09-19 10:00:00'));
        assertSame('2027-01-01 06:00:00', ReportSchedule::nextSendAt('quarterly', 1, 6, '2026-10-01 06:00:00'));
        assertSame('2026-09-21 07:00:00', ReportSchedule::nextSendAt('weekly', 1, 7, '2026-09-19 10:00:00'));
        assertSame('2026-09-28 07:00:00', ReportSchedule::nextSendAt('weekly', 1, 7, '2026-09-21 07:00:00'));
        assertSame(null, ReportSchedule::nextSendAt('manual', 1, 6, '2026-09-19 10:00:00'));
        // Den 28 vždy existuje (i v únoru).
        assertSame('2027-02-28 06:00:00', ReportSchedule::nextSendAt('monthly', 28, 6, '2027-02-01 10:00:00'));
    },

    'období: předchozí měsíc / čtvrtletí / týden a štítky' => function (): void {
        assertSame(['from' => '2026-08-01', 'to' => '2026-08-31', 'label' => 'Srpen 2026'], ReportSchedule::period('monthly', '2026-09-01 06:00:00'));
        assertSame(['from' => '2025-12-01', 'to' => '2025-12-31', 'label' => 'Prosinec 2025'], ReportSchedule::period('monthly', '2026-01-01 06:00:00'));
        assertSame(['from' => '2026-07-01', 'to' => '2026-09-30', 'label' => '3. čtvrtletí 2026'], ReportSchedule::period('quarterly', '2026-10-01 06:00:00'));
        assertSame(['from' => '2025-10-01', 'to' => '2025-12-31', 'label' => '4. čtvrtletí 2025'], ReportSchedule::period('quarterly', '2026-01-01 06:00:00'));
        // Pondělí 21. 9. 2026 → týden 14.–20. 9.
        assertSame(['from' => '2026-09-14', 'to' => '2026-09-20', 'label' => 'Týden 14. 9. – 20. 9. 2026'], ReportSchedule::period('weekly', '2026-09-21 07:00:00'));
        assertSame(['from' => '2026-08-20', 'to' => '2026-09-18', 'label' => '20. 8. – 18. 9. 2026'], ReportSchedule::period('manual', '2026-09-19 10:00:00'));
        assertSame('Měsíčně, 1. den v měsíci v 06:00', ReportSchedule::describe('monthly', 1, 6));
    },

    'souhrn z dat: uptime, aktualizace, servis, doporučení, titulek' => function (): void {
        $f = reportsFixture();
        $id = $f['sites']->create(['name' => 'Kavárna Dobrá', 'url' => 'https://kavarnadobra.cz']);

        // Srpen: 30 dní OK, jeden den výpadek 44 min.
        for ($d = 1; $d <= 31; $d++) {
            $day = sprintf('2026-08-%02d', $d);
            $f['uptime']->record($id, uptimeResult(true), 'cron', 15, $day . ' 08:00:00');

            if ($d === 22) {
                $f['uptime']->record($id, uptimeResult(false, 503), 'cron', 15, $day . ' 09:00:00');
                $f['uptime']->record($id, uptimeResult(false, 503), 'cron', 15, $day . ' 09:15:00');
                $f['uptime']->record($id, uptimeResult(false, 503), 'cron', 15, $day . ' 09:30:00');
            }
        }

        $f['events']->record($id, 'core', 'ok', 'WordPress aktualizován 6.8 → 6.9', ['action' => 'updated', 'from' => '6.8', 'to' => '6.9']);
        $f['db']->execute("UPDATE events SET created_at = '2026-08-10 10:00:00'");
        $f['events']->record($id, 'plugin', 'ok', 'WooCommerce aktualizován', ['action' => 'updated', 'plugin' => 'WooCommerce', 'from' => '9.0', 'to' => '9.1']);
        $f['events']->record($id, 'plugin', 'ok', 'Contact Form 7 aktualizován', ['action' => 'updated', 'plugin' => 'Contact Form 7', 'from' => '5.9', 'to' => '6.0']);
        $f['db']->execute("UPDATE events SET created_at = '2026-08-12 10:00:00' WHERE kind = 'plugin'");

        $f['service']->addLog($id, ['performed_on' => '2026-08-04', 'kind' => 'medium', 'description' => 'Pročistili jsme databázi.', 'minutes' => 110, 'status' => 'done', 'user_name' => 'Petra']);
        $f['service']->savePlan($id, ['is_active' => true, 'kind' => 'small', 'frequency' => 'monthly', 'first_date' => '2026-09-21'], '2026-09-01');

        $f['db']->execute("INSERT INTO site_snapshots (site_id, fetched_at, payload, php_version, wp_version, plugins_total, plugins_active, plugins_updates) VALUES (:id, '2026-08-31 12:00:00', '{}', '7.4.33', '6.9', 20, 18, 2)", ['id' => $id]);
        $f['sites']->update($id, ['ssl_valid_to' => '2026-11-12 00:00:00']);

        $site = $f['sites']->findWithSnapshot($id);
        $summary = $f['builder']->build($site, '2026-08-01', '2026-08-31', 'Srpen 2026', '2026-09-01');

        assertSame(34, $summary['uptime']['checks']);
        assertSame(3, $summary['uptime']['failed'] ?? 3);
        assertTrue($summary['uptime']['percent'] < 100 && $summary['uptime']['percent'] > 90, 'uptime v procentech');
        assertSame(31, count($summary['uptime']['days']));
        assertSame('error', $summary['uptime']['days'][21]['tone']);
        assertSame(3, $summary['updates']['total']);
        assertSame(['6.9'], $summary['updates']['core']);
        assertSame(1, count($summary['services']));
        assertSame('2026-09-21', $summary['nextService']['date']);
        assertSame('Novější verze PHP', $summary['recommendations'][0]['title']);
        assertFalse($summary['allGood']);
        assertSame('Zpráva o vašem webu — srpen 2026', $summary['subject']);
        assertContainsString('Aktualizovali jsme WordPress', $summary['done'][0]['strong']);
        assertContainsString('doplňky', $summary['done'][1]['strong']);
        assertContainsString('uptime', $summary['summaryLine']);
        assertContainsString('aktualizace', $summary['summaryLine']);

        // Bez problémů → „vše v pořádku".
        $f['db']->execute('UPDATE site_snapshots SET php_version = :php', ['php' => '8.3.10']);
        $clean = $f['sites']->create(['name' => 'Čistý', 'url' => 'https://cisty.cz']);
        $f['uptime']->record($clean, uptimeResult(true), 'cron', 15, '2026-08-10 08:00:00');
        $good = $f['builder']->build($f['sites']->findWithSnapshot($clean), '2026-08-01', '2026-08-31', 'Srpen 2026', '2026-09-01');
        assertTrue($good['allGood']);
        assertSame('Váš web celý srpen: vše v pořádku', $good['subject']);
    },

    'vykreslení: sekce, poznámka, pixel, bez skriptů, textová varianta' => function (): void {
        $f = reportsFixture();
        $id = $f['sites']->create(['name' => 'Kavárna Dobrá', 'url' => 'https://kavarnadobra.cz']);
        $f['uptime']->record($id, uptimeResult(true), 'cron', 15, '2026-08-10 08:00:00');
        $summary = $f['builder']->build($f['sites']->findWithSnapshot($id), '2026-08-01', '2026-08-31', 'Srpen 2026', '2026-09-01');
        $renderer = new ReportRenderer();

        $html = $renderer->document($summary, ['note' => 'Tento měsíc <navíc> zrychlení.', 'sections' => ['uptime_chart', 'updates', 'cta'], 'pixelUrl' => 'https://sprava.test/r/abc.gif', 'logoUrl' => 'https://sprava.test/assets/img/logo-mediagrafik.svg', 'studio' => ['name' => 'MEDIAGRAFIK', 'email' => 'studio@mediagrafik.cz', 'phone' => '+420 777 123 456']]);
        assertContainsString('POZNÁMKA OD STUDIA', $html);
        assertContainsString('Tento měsíc &lt;navíc&gt; zrychlení.', $html);
        assertContainsString('src="https://sprava.test/r/abc.gif"', $html);
        assertContainsString('Napište nám', $html);
        assertContainsString('Dostupnost v srpnu', $html);
        assertContainsString('kavarnadobra.cz', $html);
        assertFalse(str_contains($html, '<script'), 'e-mail bez skriptů');
        assertFalse(str_contains($html, 'Na co bychom se rádi domluvili'), 'vypnutá sekce doporučení');
        assertFalse(str_contains($html, 'Technická příloha'), 'technická příloha default vypnutá');

        $tech = $renderer->body($summary, ['sections' => ['technical'], 'studio' => ['name' => 'X', 'email' => '', 'phone' => '']]);
        assertFalse(str_contains($tech, 'Napište nám'), 'CTA vypnuté');

        $text = $renderer->text($summary, ['note' => 'Poznámka.', 'sections' => ['updates']]);
        assertContainsString('Poznámka od studia: Poznámka.', $text);
        assertContainsString('Dostupnost webu:', $text);
    },

    'odeslání: koncept, log transport → .eml, stav sent, bez adresáta failed, zkouška nemění stav' => function (): void {
        $f = reportsFixture();
        $id = $f['sites']->create(['name' => 'Kavárna Dobrá', 'url' => 'https://kavarnadobra.cz']);
        $f['uptime']->record($id, uptimeResult(true), 'cron', 15, '2026-08-10 08:00:00');
        $f['reports']->saveSettings($id, ['is_active' => true, 'frequency' => 'monthly', 'send_day' => 1, 'send_hour' => 6, 'requires_approval' => false], '2026-09-19 10:00:00');
        $site = $f['sites']->findWithSnapshot($id);

        // Bez adresáta → failed + událost.
        $reportId = $f['sender']->draft($site, 'manual', '2026-08-01', '2026-08-31', 'Srpen 2026', null, 'draft', 'Petra', '2026-09-19');
        $report = $f['reports']->find($reportId);
        assertSame('draft', $report['status']);
        assertSame(32, strlen((string) $report['token']));
        $result = $f['sender']->send($report, 'Petra', null, '2026-09-19 10:00:00');
        assertFalse($result['ok']);
        assertSame('failed', $f['reports']->find($reportId)['status']);

        // S adresáty → sent, .eml existuje, pixel v HTML, další termín posunut.
        $f['reports']->addRecipient($id, 'info@kavarnadobra.cz', 'klient');
        $f['reports']->addRecipient($id, 'studio@test.cz', 'kopie');
        $before = count(emlFiles($f['logDir']));
        $reportId = $f['sender']->draft($site, 'scheduled', '2026-08-01', '2026-08-31', 'Srpen 2026', '2026-09-01 06:00:00', 'draft', 'monitor', '2026-09-19');
        $report = $f['reports']->find($reportId);
        assertSame(['info@kavarnadobra.cz', 'studio@test.cz'], $report['recipients']);
        $result = $f['sender']->send($report, 'monitor', null, '2026-09-19 10:00:00');
        assertTrue($result['ok']);
        assertSame(2, $result['sent']);
        assertSame($before + 2, count(emlFiles($f['logDir'])));
        $sent = $f['reports']->find($reportId);
        assertSame('sent', $sent['status']);
        assertSame('2026-09-19 10:00:00', $sent['sent_at']);
        assertContainsString('/r/' . $sent['token'] . '.gif', $sent['html']);
        $settings = $f['reports']->settings($id);
        assertSame('2026-09-19 10:00:00', $settings['last_sent_at']);
        assertSame('2026-10-01 06:00:00', $settings['next_send_at']);

        // Zkušební odeslání nemění stav ani nepřidává pixel.
        $draftId = $f['sender']->draft($site, 'manual', '2026-08-20', '2026-09-18', '20. 8. – 18. 9. 2026', null, 'draft', 'Petra', '2026-09-19');
        $test = $f['sender']->send($f['reports']->find($draftId), 'Petra', ['petra@test.cz'], '2026-09-19 10:05:00');
        assertTrue($test['ok']);
        assertSame('draft', $f['reports']->find($draftId)['status']);

        // Pixel: první otevření zapíše čas, druhé jen počítá; koncept se nepočítá.
        assertTrue($f['reports']->markOpened((string) $sent['token'], '2026-09-19 12:00:00'));
        assertTrue($f['reports']->markOpened((string) $sent['token'], '2026-09-19 13:00:00'));
        $opened = $f['reports']->find($reportId);
        assertSame('2026-09-19 12:00:00', $opened['opened_at']);
        assertSame(2, (int) $opened['open_count']);
        assertFalse($f['reports']->markOpened((string) $f['reports']->find($draftId)['token']));
        assertFalse($f['reports']->markOpened(str_repeat('0', 32)));

        // Metriky a historie.
        $counts = $f['reports']->counts('2026-09-19 14:00:00');
        assertSame(1, $counts['sent_month']);
        assertSame(1, $counts['opened_90d']);
        assertSame(1, $counts['failed']);
        assertSame(3, count($f['reports']->forSite($id)));
        assertSame($reportId, (int) $f['reports']->latestSentFor([$id])[$id]['id']);
    },

    'cron: den před termínem vznikne report ke schválení, v termínu se bez schvalování odešle' => function (): void {
        $f = reportsFixture();
        $a = $f['sites']->create(['name' => 'Schvalovaný', 'url' => 'https://a.cz']);
        $b = $f['sites']->create(['name' => 'Automatický', 'url' => 'https://b.cz']);
        $f['reports']->addRecipient($a, 'a@klient.cz', 'klient');
        $f['reports']->addRecipient($b, 'b@klient.cz', 'klient');
        $f['reports']->saveSettings($a, ['is_active' => true, 'frequency' => 'monthly', 'send_day' => 1, 'send_hour' => 6, 'requires_approval' => true], '2026-09-19 10:00:00');
        $f['reports']->saveSettings($b, ['is_active' => true, 'frequency' => 'monthly', 'send_day' => 1, 'send_hour' => 6, 'requires_approval' => false], '2026-09-19 10:00:00');

        // 29. 9. — nic (termín 1. 10. 06:00 je dál než 24 h).
        assertSame(0, $f['sender']->step(strtotime('2026-09-29 10:00:00')));
        assertSame([], $f['reports']->pendingApproval());

        // 30. 9. 08:00 — schvalovaný dostane pending, automatický nic.
        $emls = count(emlFiles($f['logDir']));
        assertSame(0, $f['sender']->step(strtotime('2026-09-30 08:00:00')));
        $pending = $f['reports']->pendingApproval();
        assertSame(1, count($pending));
        assertSame($a, (int) $pending[0]['site_id']);
        assertSame('Září 2026', $pending[0]['period_label']);
        assertSame('2026-10-01 06:00:00', $pending[0]['scheduled_for']);
        assertTrue(count(emlFiles($f['logDir'])) > $emls, 'e-mail „čeká na schválení" na alert adresu');

        // Opakovaný běh nezakládá druhý pending.
        $f['sender']->step(strtotime('2026-09-30 09:00:00'));
        assertSame(1, count($f['reports']->pendingApproval()));

        // 1. 10. 06:05 — automatický odešel, schvalovaný zůstal čekat, oba mají další termín 1. 11.
        $sent = $f['sender']->step(strtotime('2026-10-01 06:05:00'));
        assertSame(1, $sent);
        $bReports = $f['reports']->forSite($b);
        assertSame(1, count($bReports));
        assertSame('sent', $bReports[0]['status']);
        assertSame('Září 2026', $bReports[0]['period_label']);
        assertSame(1, count($f['reports']->pendingApproval()));
        assertSame('2026-11-01 06:00:00', $f['reports']->settings($a)['next_send_at']);
        assertSame('2026-11-01 06:00:00', $f['reports']->settings($b)['next_send_at']);

        // Schválení = ruční odeslání čekajícího.
        $result = $f['sender']->send($f['reports']->pendingApproval()[0], 'Petra', null, '2026-10-01 09:00:00');
        assertTrue($result['ok']);
        assertSame([], $f['reports']->pendingApproval());
        assertSame('sent', $f['reports']->forSite($a)[0]['status']);
    },
];
