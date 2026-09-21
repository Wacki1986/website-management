<?php

declare(strict_types=1);

/**
 * Monitor: kontroly dostupnosti (denní součty), pravidla alertů,
 * SSL/doména (parsování bez sítě), celý průchod proti falešnému webu.
 */

use App\Core\Db\Connection;
use App\Core\Events\EventLog;
use App\Core\Log\Logger;
use App\Core\Monitor\AlertEngine;
use App\Core\Monitor\AlertRepository;
use App\Core\Monitor\DomainChecker;
use App\Core\Monitor\MonitorRun;
use App\Core\Monitor\MonitorSettings;
use App\Core\Monitor\Notifier;
use App\Core\Monitor\OutsideProbe;
use App\Core\Monitor\PluginClient;
use App\Core\Monitor\SecurityAudit;
use App\Core\Monitor\SnapshotImporter;
use App\Core\Monitor\SslChecker;
use App\Core\Monitor\UptimeClient;
use App\Core\Monitor\UptimeRepository;
use App\Core\Notifications\MailSettings;
use App\Core\Notifications\Mailer;
use App\Core\Notifications\PushNotifier;
use App\Core\Notifications\PushSubscriptions;
use App\Core\Security\RateLimiter;
use App\Core\Security\Secrets;
use App\Core\Settings\Settings;
use App\Core\Sites\SiteRepository;

require_once __DIR__ . '/fixtures/FakeInstance.php';
require_once __DIR__ . '/fixtures/monitor-fixture.php';

return [
    'denní součty: kontroly, selhání, minuty výpadku, pásek 30 dní' => function (): void {
        $f = monitorFixture();
        $id = $f['sites']->create(['name' => 'A', 'url' => 'https://a.cz']);

        $f['uptime']->record($id, uptimeResult(true), 'cron', 15, '2026-09-18 08:00:00');
        $f['uptime']->record($id, uptimeResult(false, 503), 'cron', 15, '2026-09-18 08:15:00');
        $f['uptime']->record($id, uptimeResult(false, 503), 'cron', 15, '2026-09-18 08:30:00');
        $f['uptime']->record($id, uptimeResult(true), 'cron', 15, '2026-09-18 08:45:00');
        // Ruční kontrola nepřičítá výpadek.
        $f['uptime']->record($id, uptimeResult(false, 0), 'manual', 15, '2026-09-18 08:50:00');

        $days = $f['uptime']->days($id, 30, '2026-09-18');
        assertSame(30, count($days));
        $today = $days[29];
        assertSame(5, $today['checks']);
        assertSame(3, $today['failed']);
        assertSame(30, $today['downtime_min']);
        assertSame('warning', $today['tone'], 'Výpadek do 30 min = warning');
        assertSame('none', $days[0]['tone'], 'Den bez kontrol');

        $stats = $f['uptime']->stats($id, '2026-08-20', '2026-09-18');
        assertSame(40.0, $stats['percent']);
        assertSame(320, $stats['avg_ms']);
        assertSame(5, $f['uptime']->countToday('2026-09-18'));
        assertSame([$id => 40.0], $f['uptime']->percentsFor([$id], 30, '2026-09-18'));
    },

    'alert nedostupnosti: po třetím selhání jednou, obnovení ho zavře a zapíše délku výpadku' => function (): void {
        $f = monitorFixture();
        $id = $f['sites']->create(['name' => 'Kavárna', 'url' => 'https://kavarnadobra.cz']);
        $site = fn (): array => $f['sites']->find($id);

        $f['engine']->afterUptime($site(), uptimeResult(false, 503), '2026-09-18 08:00:00');
        $f['engine']->afterUptime($site(), uptimeResult(false, 503), '2026-09-18 08:15:00');
        assertSame(null, $f['alerts']->openOf($id, 'down'), 'Dvě selhání ještě nejsou alert');
        assertSame(2, (int) $site()['consecutive_failures']);

        $state = $f['engine']->afterUptime($site(), uptimeResult(false, 503), '2026-09-18 08:30:00');
        assertSame('down', $state['status']);
        $open = $f['alerts']->openOf($id, 'down');
        assertTrue($open !== null);
        assertSame('error', $open['severity']);
        assertContainsString('HTTP 503', $open['title']);
        assertTrue($open['notified_at'] !== null, 'Alert se má odeslat');
        assertSame(1, count(glob($f['logDir'] . '/*.eml') ?: []), 'Jeden e-mail studiu');

        // Čtvrté selhání nezakládá druhý alert.
        $f['engine']->afterUptime($site(), uptimeResult(false, 503), '2026-09-18 08:45:00');
        assertSame(1, count($f['alerts']->all(['status' => 'all'])));

        // Obnovení: alert vyřešený monitorem, událost s délkou výpadku.
        $state = $f['engine']->afterUptime($site(), uptimeResult(true), '2026-09-18 09:15:00');
        assertSame('ok', $state['status']);
        assertSame(null, $f['alerts']->openOf($id, 'down'));
        $resolved = $f['alerts']->find((int) $open['id']);
        assertSame('resolved', $resolved['status']);
        assertSame('monitor', $resolved['resolved_by']);
        $messages = array_map(static fn (array $e): string => (string) $e['message'], $f['events']->latest($id, 10));
        assertTrue((bool) array_filter($messages, static fn (string $m): bool => str_starts_with($m, 'Výpadek ')), implode(' | ', $messages));
        assertSame(2, count(glob($f['logDir'] . '/*.eml') ?: []), 'Druhý e-mail o obnovení');
    },

    'vypnuté hlídání dostupnosti alert nezaloží, stav webu se přesto sleduje' => function (): void {
        $f = monitorFixture();
        $id = $f['sites']->create(['name' => 'B', 'url' => 'https://b.cz', 'watch_uptime' => 0]);

        for ($i = 0; $i < 3; $i++) {
            $f['engine']->afterUptime($f['sites']->find($id), uptimeResult(false, 500), '2026-09-18 08:' . (10 + $i) . ':00');
        }

        assertSame('down', $f['sites']->find($id)['status']);
        assertSame(null, $f['alerts']->openOf($id, 'down'));
    },

    'SSL: varování před vypršením, kritický po vypršení, obnova zavře' => function (): void {
        $f = monitorFixture();
        $id = $f['sites']->create(['name' => 'C', 'url' => 'https://c.cz']);
        $site = fn (): array => $f['sites']->find($id);
        $ssl = static fn (int $days, string $to): array => ['ok' => true, 'valid_to' => $to, 'valid_from' => '2026-01-01', 'issuer' => "Let's Encrypt", 'days_left' => $days, 'error' => null];

        $f['engine']->afterSsl($site(), $ssl(45, '2026-11-02'), '2026-09-18 08:00:00');
        assertSame(null, $f['alerts']->openOf($id, 'ssl_expiring'));
        assertSame('2026-11-02', $site()['ssl_valid_to']);

        $f['engine']->afterSsl($site(), $ssl(20, '2026-10-08'), '2026-09-18 08:00:00');
        $warning = $f['alerts']->openOf($id, 'ssl_expiring');
        assertTrue($warning !== null);
        assertSame('warning', $warning['severity']);

        $f['engine']->afterSsl($site(), $ssl(-2, '2026-09-16'), '2026-09-18 08:00:00');
        assertSame(null, $f['alerts']->openOf($id, 'ssl_expiring'), 'Varování nahradil kritický alert');
        assertTrue($f['alerts']->openOf($id, 'ssl_expired') !== null);

        $f['engine']->afterSsl($site(), $ssl(89, '2026-12-16'), '2026-09-18 08:00:00');
        assertSame(null, $f['alerts']->openOf($id, 'ssl_expired'));
        assertSame(null, $f['alerts']->openOf($id, 'ssl_expiring'));
    },

    'snapshot: PHP bez podpory, plugin neodpovídá, moc aktualizací, stará záloha — a zpátky' => function (): void {
        $f = monitorFixture();
        $id = $f['sites']->create(['name' => 'D', 'url' => 'https://d.cz']);
        $snapshot = static fn (array $over = []): array => $over + ['php_version' => '8.2.1', 'plugins_updates' => 2, 'wp_update_version' => null, 'last_backup_at' => '2026-09-18 03:00:00'];
        $site = fn (array $over = []): array => $over + $f['sites']->find($id);
        $now = '2026-09-18 12:00:00';

        $f['engine']->afterSnapshot($site(), $snapshot(['php_version' => '7.4.33']), $now);
        assertTrue($f['alerts']->openOf($id, 'php_eol') !== null);

        $f['engine']->afterSnapshot($site(), $snapshot(['php_version' => '8.3.0']), $now);
        assertSame(null, $f['alerts']->openOf($id, 'php_eol'), 'Po přechodu na 8.3 se alert zavře');

        $f['engine']->afterSnapshot($site(['api_status' => 'bad_key', 'api_failures' => 3, 'snapshot_error' => 'Neplatný klíč']), $snapshot(), $now);
        assertTrue($f['alerts']->openOf($id, 'api_error') !== null);
        $f['engine']->afterSnapshot($site(['api_status' => 'ok', 'api_failures' => 0]), $snapshot(), $now);
        assertSame(null, $f['alerts']->openOf($id, 'api_error'));

        $f['engine']->afterSnapshot($site(), $snapshot(['plugins_updates' => 14]), $now);
        $updates = $f['alerts']->openOf($id, 'updates');
        assertTrue($updates !== null);
        assertContainsString('14', $updates['title']);
        $f['engine']->afterSnapshot($site(), $snapshot(['plugins_updates' => 3]), $now);
        assertSame(null, $f['alerts']->openOf($id, 'updates'));

        $f['engine']->afterSnapshot($site(), $snapshot(['last_backup_at' => '2026-09-10 03:00:00']), $now);
        assertTrue($f['alerts']->openOf($id, 'backup_old') !== null);
        $f['engine']->afterSnapshot($site(), $snapshot(['last_backup_at' => null]), $now);
        assertTrue($f['alerts']->openOf($id, 'backup_old') !== null, 'Bez data zálohy se pravidlo přeskočí — alert zůstává, nic se nemění');

        // Vypnuté pravidlo aktualizací.
        $f['settings']->set('rule_updates_on', '0');
        $f['engine']->afterSnapshot($site(), $snapshot(['plugins_updates' => 40]), $now);
        assertSame(null, $f['alerts']->openOf($id, 'updates'));
    },

    'ruční akce: vyřešit, ignorovat, vrátit — s událostí' => function (): void {
        $f = monitorFixture();
        $id = $f['sites']->create(['name' => 'E', 'url' => 'https://e.cz']);
        $alertId = $f['alerts']->open($id, 'down', 'error', 'Web nedostupný', 'x', 'pravidlo', [], '2026-09-18 08:00:00');
        $before = $f['events']->count($id);

        $f['engine']->ignore($alertId, 'Petra');
        assertSame('ignored', $f['alerts']->find($alertId)['status']);
        $f['engine']->reopen($alertId, 'Petra');
        assertSame('open', $f['alerts']->find($alertId)['status']);
        $f['engine']->resolve($alertId, 'Petra', 'opraveno');
        assertSame('resolved', $f['alerts']->find($alertId)['status']);
        assertSame('Petra', $f['alerts']->find($alertId)['resolved_by']);
        assertSame($before + 3, $f['events']->count($id));

        $counts = $f['alerts']->counts('2026-09-18');
        assertSame(0, $counts['open']);
        assertSame(1, $counts['resolved']);
    },

    'druhý výskyt za 30 dní se počítá' => function (): void {
        $f = monitorFixture();
        $id = $f['sites']->create(['name' => 'F', 'url' => 'https://f.cz']);
        $first = $f['alerts']->open($id, 'down', 'error', 'x', 'x', 'r', [], '2026-09-01 08:00:00');
        $f['alerts']->resolve($first, 'monitor', '', '2026-09-01 09:00:00');
        $second = $f['alerts']->open($id, 'down', 'error', 'x', 'x', 'r', [], '2026-09-18 08:00:00');

        assertSame(2, (int) $f['alerts']->find($second)['occurrences_30d']);
    },

    'SSL a doména: rozbor bez sítě' => function (): void {
        $pem = self_signed_pem();

        if ($pem === null) {
            skip('openssl neumí vyrobit testovací certifikát');
        }

        $parsed = SslChecker::parse($pem);
        assertTrue($parsed['ok'], (string) $parsed['error']);
        assertTrue($parsed['days_left'] >= 29 && $parsed['days_left'] <= 31, 'Certifikát na 30 dní: ' . $parsed['days_left']);

        assertSame('hotelzlatylev.cz', DomainChecker::registrableDomain('rezervace.hotelzlatylev.cz'));
        assertSame('example.co.uk', DomainChecker::registrableDomain('www.example.co.uk'));
        assertSame(null, DomainChecker::registrableDomain('127.0.0.1'));
        assertSame(null, DomainChecker::registrableDomain('localhost'));

        $checker = new DomainChecker(fetcher: static fn (string $url): array => [
            'status' => 200,
            'body' => json_encode(['events' => [['eventAction' => 'registration', 'eventDate' => '2019-01-01T00:00:00Z'], ['eventAction' => 'expiration', 'eventDate' => '2026-12-05T00:00:00Z']]]),
        ]);
        $result = $checker->check('rezervace.hotelzlatylev.cz', strtotime('2026-09-18 12:00:00'));
        assertTrue($result['ok']);
        assertSame('2026-12-05', $result['expires_on']);
        assertSame(78, $result['days_left']);
    },

    'celý průchod proti falešnému webu: uptime, data z pluginu, zápis posledního běhu, zámek' => function (): void {
        $f = monitorFixture();

        if (!FakeInstance::start(8221, ['FAKE_WP_MODE' => 'ok', 'FAKE_WP_KEY' => MONITOR_KEY], 'fake-wp-site.php')) {
            skip('falešný web nenastartoval: ' . FakeInstance::lastError());
        }

        try {
            $id = $f['sites']->create(['name' => 'Kavárna', 'url' => 'http://127.0.0.1:8221']);
            $f['sites']->setApiKey($id, MONITOR_KEY);
            // Web bez klíče a bez odpovědi — nedostupný.
            $dead = $f['sites']->create(['name' => 'Mrtvý', 'url' => 'http://127.0.0.1:8299']);

            $summary = $f['run']->run(strtotime('2026-09-18 10:00:00'), 40);

            assertSame('ok', $summary['status'], (string) $summary['error']);
            assertSame(2, $summary['checked']);
            assertSame(1, $summary['pulled']);
            assertSame('ok', $f['sites']->find($id)['status']);
            assertSame(1, (int) $f['sites']->find($dead)['consecutive_failures']);
            assertTrue($f['sites']->find($id)['last_snapshot_at'] !== null);
            assertTrue($f['alerts']->openOf($id, 'php_eol') !== null, 'Falešný web hlásí PHP 7.4');

            $last = MonitorRun::lastRun($f['settings']);
            assertTrue($last !== null && $last['ok'] === true);

            // Druhý běh hned po prvním: weby ještě nemají vypršený interval.
            $second = $f['run']->run(strtotime('2026-09-18 10:01:00'), 40);
            assertSame(0, $second['checked']);

            // Ruční kontrola jednoho webu jde stejnou cestou.
            $one = $f['run']->checkOne($f['sites']->find($id), strtotime('2026-09-18 10:05:00'));
            assertTrue($one['uptime']['ok']);
            assertTrue($one['plugin']['ok']);
            assertTrue($one['audit'] !== null);
        } finally {
            FakeInstance::stop();
        }
    },

    'zámek: druhý souběžný běh se přeskočí' => function (): void {
        $f = monitorFixture();
        $lock = fopen($f['logDir'] . '/monitor.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            $summary = $f['run']->run();
            assertSame('busy', $summary['status']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    },
];

/** Testovací certifikát na 30 dní od 18. 9. 2026 — PEM, nebo null bez openssl. */
function self_signed_pem(): ?string
{
    if (!function_exists('openssl_pkey_new')) {
        return null;
    }

    $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

    // Vlastní konfigurace: minimální cnf jádra nemá sekci [req], bez které
    // OpenSSL na Windows CSR nevyrobí.
    $config['config'] = __DIR__ . '/fixtures/openssl-test.cnf';

    $key = @openssl_pkey_new($config);

    if ($key === false) {
        return null;
    }

    // Platnost se dá zadat jen ve dnech od teď; test počítá days_left od
    // skutečného „teď", proto porovnává s tolerancí.
    $csr = @openssl_csr_new(['CN' => 'test.cz'], $key, $config);
    $cert = $csr !== false ? @openssl_csr_sign($csr, null, $key, 30, $config) : false;

    if ($cert === false || !openssl_x509_export($cert, $pem)) {
        return null;
    }

    return $pem;
}
