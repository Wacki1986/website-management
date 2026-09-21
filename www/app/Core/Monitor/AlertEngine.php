<?php

declare(strict_types=1);

namespace App\Core\Monitor;

use App\Core\Events\EventLog;
use App\Core\Sites\SiteRepository;

/**
 * Pravidla alertů — z výsledku kontroly rozhodne, jestli alert otevřít,
 * nebo naopak uzavřít. Jeden otevřený alert na dvojici (web, typ).
 *
 * Čistá logika nad repozitáři, bez sítě: testuje se s poli místo
 * skutečných kontrol. Prahy bere z `MonitorSettings`.
 */
final class AlertEngine
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly EventLog $events,
        private readonly SiteRepository $sites,
        private readonly Notifier $notifier,
        private readonly MonitorSettings $settings,
    ) {
    }

    /**
     * Po kontrole dostupnosti. Vstup je řádek webu **před** zápisem
     * kontroly (kvůli `consecutive_failures`), výstup nový stav.
     *
     * @param array<string, mixed> $site
     * @param array{ok: bool, status: int, ms: int, error: ?string} $result
     * @return array{status: string, consecutive_failures: int}
     */
    public function afterUptime(array $site, array $result, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $siteId = (int) $site['id'];
        $failures = $result['ok'] ? 0 : (int) $site['consecutive_failures'] + 1;
        $threshold = $this->settings->int('monitor_fail_threshold');
        $open = $this->alerts->openOf($siteId, 'down');

        $update = [
            'consecutive_failures' => $failures,
            'last_check_at' => $now,
            'last_status_code' => $result['status'],
            'last_response_ms' => $result['ms'],
            'last_error' => $result['error'],
        ];

        if ($result['ok']) {
            $update['status'] = 'ok';
            $update['last_ok_at'] = $now;

            if ($open !== null) {
                // Obnovení: alert se zavře sám, délka výpadku do historie.
                $minutes = max(1, (int) round((strtotime($now) - strtotime((string) $open['opened_at'])) / 60));
                $note = 'Web zase odpovídá (HTTP ' . $result['status'] . ') — výpadek ' . get_duration((string) $open['opened_at'], $now) . '.';
                $this->alerts->resolve((int) $open['id'], 'monitor', $note, $now);
                $this->events->record($siteId, EventLog::KIND_UPTIME, $minutes > 30 ? 'error' : 'warning',
                    'Výpadek ' . get_duration((string) $open['opened_at'], $now) . ' – web zase dostupný',
                    ['minutes' => $minutes, 'from' => $open['opened_at'], 'to' => $now]);
                $this->notifier->alertResolved($site, $open, $note);
            }
        } else {
            $update['status'] = $failures >= $threshold ? 'down' : (string) $site['status'];

            if ($failures === $threshold && $open === null && (int) $site['watch_uptime'] === 1) {
                $reason = $result['status'] > 0 ? 'HTTP ' . $result['status'] : 'neodpovídá';
                $body = $threshold . ' ' . get_plural($threshold, 'kontrola za sebou selhala', 'kontroly za sebou selhaly', 'kontrol za sebou selhalo')
                    . '. ' . ($result['status'] > 0 ? 'Server odpovídá, ale web vrací chybu ' . $result['status'] . '.' : 'Server neodpovídá: ' . (string) $result['error']);
                $id = $this->alerts->open($siteId, 'down', 'error', 'Web nedostupný – ' . $reason, $body,
                    'pravidlo: ' . $threshold . ' selhané kontroly', ['status' => $result['status'], 'error' => $result['error']], $now);
                $this->events->record($siteId, EventLog::KIND_ALERT, 'error', 'Web nedostupný – ' . $reason . ', alert odeslán', ['alert_id' => $id]);
                $this->notify($site, $id);
            }
        }

        $this->sites->update($siteId, $update);

        return ['status' => (string) $update['status'], 'consecutive_failures' => $failures];
    }

    /**
     * Po kontrole certifikátu.
     *
     * @param array<string, mixed> $site
     * @param array{ok: bool, valid_to: ?string, valid_from: ?string, issuer: ?string, days_left: ?int, error: ?string} $ssl
     */
    public function afterSsl(array $site, array $ssl, ?string $now = null): void
    {
        $now ??= date('Y-m-d H:i:s');
        $siteId = (int) $site['id'];

        $this->sites->update($siteId, [
            'ssl_checked_at' => $now,
            'ssl_valid_to' => $ssl['valid_to'],
            'ssl_issuer' => $ssl['issuer'],
            'ssl_error' => $ssl['error'],
        ]);

        if (!$ssl['ok'] || $ssl['days_left'] === null || (int) $site['watch_ssl'] !== 1) {
            return;
        }

        $days = (int) $ssl['days_left'];
        $warnDays = $this->settings->int('rule_ssl_days');
        $expiring = $this->alerts->openOf($siteId, 'ssl_expiring');
        $expired = $this->alerts->openOf($siteId, 'ssl_expired');

        if ($days < 0) {
            if ($expiring !== null) {
                $this->alerts->resolve((int) $expiring['id'], 'monitor', 'Certifikát vypršel — nahrazeno kritickým alertem.', $now);
            }

            if ($expired === null) {
                $id = $this->alerts->open($siteId, 'ssl_expired', 'error', 'SSL certifikát vypršel',
                    'Certifikát vypršel ' . get_czech_date((string) $ssl['valid_to']) . '. Prohlížeč návštěvníkům zobrazuje varování.',
                    'pravidlo: platnost SSL < 0 dní', ['valid_to' => $ssl['valid_to']], $now);
                $this->events->record($siteId, EventLog::KIND_ALERT, 'error', 'SSL certifikát vypršel', ['alert_id' => $id]);
                $this->notify($site, $id);
            }

            return;
        }

        if ($expired !== null) {
            $this->alerts->resolve((int) $expired['id'], 'monitor', 'Certifikát je obnovený, platí do ' . get_czech_date((string) $ssl['valid_to']) . '.', $now);
            $this->events->record($siteId, EventLog::KIND_SECURITY, 'ok', 'SSL certifikát obnoven, platí do ' . get_czech_date((string) $ssl['valid_to']));
        }

        if ($this->settings->bool('rule_ssl_on') && $days <= $warnDays) {
            if ($expiring === null) {
                $id = $this->alerts->open($siteId, 'ssl_expiring', 'warning', 'SSL certifikát vyprší za ' . get_count($days, 'den', 'dny', 'dní'),
                    'Platí do ' . get_czech_date((string) $ssl['valid_to']) . ($ssl['issuer'] !== null ? ' (' . $ssl['issuer'] . ')' : '') . '. Ověřte, že automatické obnovení funguje.',
                    'pravidlo: platnost SSL < ' . $warnDays . ' dní', ['valid_to' => $ssl['valid_to'], 'days_left' => $days], $now);
                $this->events->record($siteId, EventLog::KIND_ALERT, 'warning', 'SSL certifikát vyprší za ' . get_count($days, 'den', 'dny', 'dní'), ['alert_id' => $id]);
                $this->notify($site, $id);
            }
        } elseif ($expiring !== null) {
            $this->alerts->resolve((int) $expiring['id'], 'monitor', 'Certifikát je obnovený, platí do ' . get_czech_date((string) $ssl['valid_to']) . '.', $now);
            $this->events->record($siteId, EventLog::KIND_SECURITY, 'ok', 'SSL certifikát obnoven, platí do ' . get_czech_date((string) $ssl['valid_to']));
        }
    }

    /**
     * Po kontrole domény (jen se zapnutým pravidlem).
     *
     * @param array<string, mixed> $site
     * @param array{ok: bool, domain: ?string, expires_on: ?string, days_left: ?int, error: ?string} $domain
     */
    public function afterDomain(array $site, array $domain, ?string $now = null): void
    {
        $now ??= date('Y-m-d H:i:s');
        $siteId = (int) $site['id'];

        $this->sites->update($siteId, ['domain_checked_at' => $now, 'domain_expires_on' => $domain['expires_on']]);

        if (!$domain['ok'] || $domain['days_left'] === null) {
            return;
        }

        $open = $this->alerts->openOf($siteId, 'domain_expiring');
        $warnDays = $this->settings->int('rule_domain_days');

        if ($this->settings->bool('rule_domain_on') && $domain['days_left'] <= $warnDays) {
            if ($open === null) {
                $id = $this->alerts->open($siteId, 'domain_expiring', 'info', 'Doména ' . $domain['domain'] . ' expiruje za ' . get_count((int) $domain['days_left'], 'den', 'dny', 'dní'),
                    'Registrace končí ' . get_czech_date((string) $domain['expires_on']) . '. Ověřte u registrátora, že se prodlouží.',
                    'pravidlo: expirace domény < ' . $warnDays . ' dní', ['expires_on' => $domain['expires_on']], $now);
                $this->notify($site, $id);
            }
        } elseif ($open !== null) {
            $this->alerts->resolve((int) $open['id'], 'monitor', 'Doména je prodloužená do ' . get_czech_date((string) $domain['expires_on']) . '.', $now);
        }
    }

    /**
     * Po načtení dat z pluginu (nebo po jeho selhání): PHP bez podpory,
     * plugin neodpovídá, moc čekajících aktualizací, stará záloha.
     *
     * @param array<string, mixed> $site       řádek webu PO importu (aktuální api_status/api_failures)
     * @param array<string, mixed>|null $snapshot řádek `site_snapshots` (null = zatím žádná data)
     */
    public function afterSnapshot(array $site, ?array $snapshot, ?string $now = null): void
    {
        $now ??= date('Y-m-d H:i:s');
        $siteId = (int) $site['id'];
        $nowTs = strtotime($now);

        // Plugin neodpovídá (3× po sobě).
        $apiDown = ($site['api_status'] ?? 'ok') !== 'ok' && (int) ($site['api_failures'] ?? 0) >= 3;
        $this->toggle($site, 'api_error', $apiDown, 'warning', 'Plugin MEDIAGRAFIK Monitor neodpovídá',
            (string) ($site['snapshot_error'] ?? '') . ' Data v detailu jsou z poslední úspěšné kontroly.',
            'pravidlo: 3 neúspěšné pokusy o načtení dat', 'Plugin zase odpovídá, data jsou čerstvá.', $now);

        if ($snapshot === null) {
            return;
        }

        // PHP bez bezpečnostní podpory.
        $php = (string) $snapshot['php_version'];
        $eol = $php !== '' && PhpSupport::isEol($php, $nowTs);
        $this->toggle($site, 'php_eol', $eol, 'warning', 'PHP bez bezpečnostní podpory',
            'Hosting běží na PHP ' . $php . ', které už nedostává opravy. Doporučen přechod na ' . PhpSupport::RECOMMENDED . '.',
            'pravidlo: verze PHP po konci podpory', 'PHP je aktualizované na ' . $php . '.', $now);

        // Čekající aktualizace nad prahem.
        $updates = (int) $snapshot['plugins_updates'] + ($snapshot['wp_update_version'] !== null ? 1 : 0);
        $max = $this->settings->int('rule_updates_max');
        $tooMany = $this->settings->bool('rule_updates_on') && (int) $site['watch_updates'] === 1 && $updates > $max;
        $this->toggle($site, 'updates', $tooMany, 'warning', get_count($updates, 'čekající aktualizace', 'čekající aktualizace', 'čekajících aktualizací'),
            'Web má ' . get_count($updates, 'nenainstalovanou aktualizaci', 'nenainstalované aktualizace', 'nenainstalovaných aktualizací') . ($snapshot['wp_update_version'] !== null ? ' včetně WordPressu ' . $snapshot['wp_update_version'] : '') . '.',
            'pravidlo: více než ' . $max . ' aktualizací', 'Aktualizace jsou pod prahem.', $now);

        // Stará záloha — jen když plugin datum zálohy vůbec zjistil.
        $backupAt = $snapshot['last_backup_at'] ?? null;

        if ($backupAt !== null) {
            $hours = (int) floor(($nowTs - strtotime((string) $backupAt)) / 3600);
            $limit = $this->settings->int('rule_backup_hours');
            $old = $this->settings->bool('rule_backup_on') && $hours > $limit;
            $this->toggle($site, 'backup_old', $old, 'warning', 'Poslední záloha je ' . get_count((int) round($hours / 24), 'den', 'dny', 'dní') . ' stará',
                'Poslední úspěšná záloha ' . get_when((string) $backupAt) . '. Zálohovací plugin možná neběží.',
                'pravidlo: záloha starší než ' . $limit . ' h', 'Záloha je zase čerstvá.', $now);
        }
    }

    /**
     * Po změně plánu servisu nebo v denním bloku: termín prošel o víc než
     * `rule_service_hours` a servis není zapsaný (zápis termín posune).
     *
     * @param array<string, mixed>      $site
     * @param array<string, mixed>|null $plan řádek `service_plans` (null = bez plánu)
     */
    public function afterServicePlan(array $site, ?array $plan, ?string $now = null): void
    {
        $now ??= date('Y-m-d H:i:s');
        $hours = $this->settings->int('rule_service_hours');
        $next = $plan !== null && (int) $plan['is_active'] === 1 ? $plan['next_date'] : null;
        $overdue = $this->settings->bool('rule_service_on') && $next !== null
            && strtotime($now) > strtotime((string) $next) + $hours * 3600;
        $days = $next !== null ? max(1, (int) floor((strtotime($now) - strtotime((string) $next)) / 86400)) : 0;

        $this->toggle($site, 'service_overdue', $overdue, 'warning', 'Servis po termínu' . ($next !== null ? ' – ' . get_czech_date((string) $next) : ''),
            $next !== null ? 'Plánovaný servis měl proběhnout ' . get_czech_date((string) $next) . ' (před ' . get_count($days, 'dnem', 'dny', 'dny') . ') a není zapsaný v historii.' : '',
            'pravidlo: servis nezapsaný ' . $hours . ' h po termínu', 'Servis je zapsaný nebo termín posunutý.', $now);
    }

    /** Ruční akce ze stránky Alerty. */
    public function resolve(int $alertId, string $who, string $note = ''): void
    {
        $alert = $this->alerts->find($alertId);

        if ($alert === null || $alert['status'] !== 'open') {
            return;
        }

        $this->alerts->resolve($alertId, $who, $note);
        $this->events->record((int) $alert['site_id'], EventLog::KIND_ALERT, 'ok', 'Alert vyřešen: ' . $alert['title'], ['alert_id' => $alertId], $who);
    }

    public function ignore(int $alertId, string $who): void
    {
        $alert = $this->alerts->find($alertId);

        if ($alert === null || $alert['status'] !== 'open') {
            return;
        }

        $this->alerts->ignore($alertId, $who);
        $this->events->record((int) $alert['site_id'], EventLog::KIND_ALERT, 'ok', 'Alert ignorován: ' . $alert['title'], ['alert_id' => $alertId], $who);
    }

    public function reopen(int $alertId, string $who): void
    {
        $alert = $this->alerts->find($alertId);

        if ($alert === null || $alert['status'] === 'open') {
            return;
        }

        $this->alerts->reopen($alertId);
        $this->events->record((int) $alert['site_id'], EventLog::KIND_ALERT, 'warning', 'Alert vrácen mezi otevřené: ' . $alert['title'], ['alert_id' => $alertId], $who);
    }

    /**
     * Stav pravidla „platí / neplatí": otevře alert, když začne platit
     * a žádný otevřený není; zavře, když přestane platit.
     *
     * @param array<string, mixed> $site
     */
    private function toggle(array $site, string $type, bool $active, string $severity, string $title, string $body, string $rule, string $resolveNote, string $now): void
    {
        $siteId = (int) $site['id'];
        $open = $this->alerts->openOf($siteId, $type);

        if ($active && $open === null) {
            $id = $this->alerts->open($siteId, $type, $severity, $title, trim($body), $rule, [], $now);
            $this->events->record($siteId, EventLog::KIND_ALERT, $severity === 'error' ? 'error' : 'warning', $title, ['alert_id' => $id]);
            $this->notify($site, $id);
        } elseif (!$active && $open !== null) {
            $this->alerts->resolve((int) $open['id'], 'monitor', $resolveNote, $now);
            $this->events->record($siteId, EventLog::KIND_ALERT, 'ok', 'Vyřešeno samo: ' . $open['title'], ['alert_id' => (int) $open['id']]);
        }
    }

    /** @param array<string, mixed> $site */
    private function notify(array $site, int $alertId): void
    {
        $alert = $this->alerts->find($alertId);

        if ($alert === null) {
            return;
        }

        $this->notifier->alertOpened($site, $alert);
        $this->alerts->markNotified($alertId);
    }
}
