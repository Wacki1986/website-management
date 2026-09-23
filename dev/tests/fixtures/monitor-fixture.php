<?php

declare(strict_types=1);

/**
 * Sdílená sestava monitoru pro testy (MonitorTest, ServiceTest, ReportsTest):
 * čistá DB, pošta do souboru, push vypnutý.
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

const MONITOR_KEY = "mg_live_MONITORKEY00000000000000000000";

/**
 * Celá sestava monitoru nad čistou DB: pošta do souboru, push vypnutý.
 *
 * @return array{db: Connection, sites: SiteRepository, uptime: UptimeRepository, alerts: AlertRepository, engine: AlertEngine, run: MonitorRun, settings: Settings, events: EventLog, logDir: string}
 */
function monitorFixture(): array
{
    $db = freshTestDb();
    $secrets = new Secrets(str_repeat('ef', 32));
    $settings = new Settings($db, $secrets);
    $monitorSettings = new MonitorSettings($settings);
    $sites = new SiteRepository($db, $secrets);
    $events = new EventLog($db);
    $uptime = new UptimeRepository($db);
    $alerts = new AlertRepository($db);
    $logDir = sys_get_temp_dir() . '/sprava-webu-monitor-' . getmypid();

    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    foreach (glob($logDir . '/*.eml') ?: [] as $old) {
        unlink($old);
    }

    $logger = new Logger($logDir);
    $mailSettings = new MailSettings($settings, ['transport' => 'log', 'from_address' => 'monitor@test.cz', 'from_name' => 'Test']);
    $mailer = new Mailer($mailSettings, $logger, $logDir);
    $push = new PushNotifier(new PushSubscriptions($db), $settings, new RateLimiter($db), $logger, null, '/');
    $notifier = new Notifier($mailer, $push, $monitorSettings, $logger, 'https://sprava.test');
    $engine = new AlertEngine($alerts, $events, $sites, $notifier, $monitorSettings);
    $importer = new SnapshotImporter($db, $sites, $events);
    $security = new SecurityAudit($db, new OutsideProbe(timeout: 3));

    $run = new MonitorRun(
        $db, $sites, $uptime, new SslChecker(timeout: 3), new DomainChecker(fetcher: static fn (): array => ['status' => 0, 'body' => '']),
        new PluginClient(timeout: 5), $importer, $security, $engine, $notifier, $monitorSettings, $settings,
        new RateLimiter($db), $events, $logger, $logDir . '/monitor.lock', new UptimeClient(timeout: 3),
    );

    $settings->set('alert_emails', 'studio@test.cz');

    return ['db' => $db, 'sites' => $sites, 'uptime' => $uptime, 'alerts' => $alerts, 'engine' => $engine, 'run' => $run, 'settings' => $settings, 'events' => $events, 'logDir' => $logDir,
        'mailer' => $mailer, 'notifier' => $notifier, 'logger' => $logger, 'security' => $security, 'monitorSettings' => $monitorSettings, 'mailSettings' => $mailSettings, 'importer' => $importer];
}

/** @return array{ok: bool, status: int, ms: int, error: ?string} */
function uptimeResult(bool $ok, int $status = 200): array
{
    return ['ok' => $ok, 'status' => $status, 'ms' => $ok ? 320 : 0, 'error' => $ok ? null : ($status > 0 ? 'HTTP ' . $status : 'timeout')];
}

