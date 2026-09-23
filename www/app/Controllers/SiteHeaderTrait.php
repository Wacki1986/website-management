<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Monitor\PluginClient;
use App\Core\Service\ServiceSchedule;
use App\Core\Sites\SiteActions;
use App\Core\Sites\SiteRepository;
use App\Core\Sites\SiteStatus;

/**
 * Hlavička detailu webu (`partials/site-header`) sdílená controllery
 * záložek: SiteController, ServiceController, ReportController.
 */
trait SiteHeaderTrait
{
    /**
     * Data pro hlavičku detailu (`partials/site-header`): stav, meta,
     * záložky s odznaky. Počítá se jednou pro všech sedm záložek.
     *
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function header(array $site, string $tab): array
    {
        $state = SiteStatus::of($site);
        $apiDown = ($site['api_status'] ?? 'unknown') !== 'ok' && (int) ($site['api_failures'] ?? 0) >= 3;
        $inactive = (int) ($site['snap_plugins_total'] ?? 0) - (int) ($site['snap_plugins_active'] ?? 0);
        $missing = (int) ($site['snap_security_missing'] ?? 0);

        $headerStatus = match (true) {
            ($site['status'] ?? '') === 'down' => ['tone' => 'error', 'label' => 'Nedostupný' . ($site['last_status_code'] ? ' · HTTP ' . $site['last_status_code'] : '')],
            $apiDown => ['tone' => 'error', 'label' => 'Neznámý stav · API neodpovídá'],
            default => $state,
        };

        $base = 'weby/' . (int) $site['id'];
        $service = ServiceSchedule::cell($this->kernel->service()->plan((int) $site['id']), date('Y-m-d'));
        $tabs = [
            ['key' => 'prehled', 'label' => 'Přehled', 'url' => get_url($base . '/prehled')],
            ['key' => 'pluginy', 'label' => 'Pluginy', 'url' => get_url($base . '/pluginy'), 'badge' => $inactive > 0 ? get_badge($inactive . ' neakt.', 'warning') : ''],
            ['key' => 'obsah', 'label' => 'Obsah', 'url' => get_url($base . '/obsah')],
            ['key' => 'zabezpeceni', 'label' => 'Zabezpečení', 'url' => get_url($base . '/zabezpeceni'), 'badge' => $missing > 0 ? get_badge($missing . ' chybí', 'warning') : ''],
            ['key' => 'servis', 'label' => 'Servis', 'url' => get_url($base . '/servis'), 'badge' => $service['badge']],
            ['key' => 'reporty', 'label' => 'Reporty', 'url' => get_url($base . '/reporty')],
            ['key' => 'nastaveni', 'label' => 'Nastavení', 'url' => get_url($base . '/nastaveni')],
        ];

        return [
            'site' => $site,
            'tab' => $tab,
            'tabsHtml' => get_tabs($tabs, $tab),
            'headerStatus' => $headerStatus,
            'host' => SiteRepository::host((string) $site['url']),
            'adminUrl' => (string) $site['admin_url'] !== '' ? (string) $site['admin_url'] : rtrim((string) $site['url'], '/') . '/wp-admin/',
            'login' => $this->headerLogin($site),
            'intervalLabel' => 'kontrola ' . (SiteRepository::INTERVALS[(int) $site['check_interval_min']] ?? 'každých 15 min'),
            'apiWarning' => $apiDown ? [
                'title' => 'Web neodpovídá monitorovacímu API',
                'text' => 'Plugin MEDIAGRAFIK Monitor neodpověděl' . ($site['last_snapshot_at'] !== null ? ' od ' . get_when((string) $site['last_snapshot_at']) : '')
                    . '. ' . ((string) $site['snapshot_error'] !== '' ? $site['snapshot_error'] . ' ' : '') . 'Data níže jsou z poslední úspěšné kontroly.',
            ] : null,
        ];
    }

    /**
     * Tlačítko „wp-admin": přihlášení jedním klikem, když ho web umí a je
     * nastavený účet studia; jinak null a hlavička ukáže obyčejný odkaz.
     *
     * @param array<string, mixed> $site řádek z `findWithSnapshot()` (sloupce `snap_*`)
     * @return array{action: string, user: string}|null
     */
    private function headerLogin(array $site): ?array
    {
        $user = SiteActions::loginUser($site, $this->kernel->settings()->get(SiteActions::LOGIN_USER_SETTING));
        $snapshot = ($site['snap_fetched_at'] ?? null) !== null ? ['plugin_version' => (string) $site['snap_plugin_version']] : null;

        if ($user === '' || SiteActions::blocked($site, $snapshot, PluginClient::ACTION_LOGIN_LINK) !== null) {
            return null;
        }

        return ['action' => get_url('weby/' . (int) $site['id'] . '/prihlasit'), 'user' => $user];
    }
}
