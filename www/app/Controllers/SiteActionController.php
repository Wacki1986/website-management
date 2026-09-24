<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\UserRepository;
use App\Core\Events\EventLog;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Monitor\PluginClient;
use App\Core\Sites\SiteActions;

/**
 * Akce, které na webu klienta něco mění — přes podepsaný požadavek na
 * plugin MEDIAGRAFIK Monitor:
 *
 *  - aktualizace pluginů (záložka Pluginy, hromadně i z řádku),
 *  - aktivace a deaktivace pluginu (ikona v řádku),
 *  - smazání neaktivního pluginu (potvrzovací stránka),
 *  - aktualizace WordPressu (odkaz na Přehledu → potvrzovací stránka),
 *  - přihlášení do administrace jedním klikem (tlačítko „wp-admin").
 *
 * Po každé akci se hned načte čerstvý souhrn z webu: záložky ukážou nový
 * stav a historie dostane změny z importu („X aktualizován 1.0 → 1.1",
 * „X odstraněn", „WordPress aktualizován"). Selhání zapisuje akce sama.
 */
final class SiteActionController extends Controller
{
    use SiteHeaderTrait;

    // -----------------------------------------------------------------
    // Aktualizace pluginů
    // -----------------------------------------------------------------

    /**
     * Hromadně (`plugins[]`) nebo jeden z řádku (`plugin`). Posílají se jen
     * pluginy z `SiteActions::updatable()`, ostatní se tiše vynechají.
     *
     * Skript (`plugin-update.js`) posílá pluginy po jednom a čeká JSON —
     * kvůli průběhu v tabulce; data z webu se pak načtou jen po posledním
     * (`refresh=1`). Bez skriptu jde všechno najednou a stránka se překreslí.
     */
    public function updatePlugins(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $back = 'weby/' . $id . '/pluginy';
        $json = $this->request()->wantsJson();
        $blocked = SiteActions::blocked($site, $this->kernel->snapshots()->snapshot((int) $id), PluginClient::ACTION_PLUGIN_UPDATE);

        if ($blocked !== null) {
            return $json ? Response::json(['ok' => false, 'error' => $blocked], 422) : $this->redirectWithFlash($back, $blocked, 'warning');
        }

        $single = $this->request()->string('plugin');
        $requested = $single !== '' ? [$single] : array_map('strval', array_filter((array) $this->request()->input('plugins', []), 'is_scalar'));
        $library = SiteActions::libraryFor($this->kernel->snapshots()->snapshot((int) $id), $this->kernel->pluginLibrary()->versions());
        $updatable = SiteActions::updatable($this->kernel->snapshots()->plugins((int) $id), $this->kernel->pluginDistribution()->version(), $library);
        $files = array_values(array_intersect(array_unique($requested), array_keys($updatable)));

        if ($files === [] || count($files) > PluginClient::MAX_UPDATES) {
            $why = $files === [] ? 'Vyberte plugin, který má dostupnou aktualizaci.' : 'Najednou jde aktualizovat nejvýš ' . PluginClient::MAX_UPDATES . ' pluginů — vyberte méně.';

            return $json ? Response::json(['ok' => false, 'error' => $why], 422) : $this->redirectWithFlash($back, $why, 'warning');
        }

        $this->keepRunning();
        $result = $this->kernel->pluginClient()->updatePlugins((string) $site['url'], $this->apiKey($site), $files);
        $names = implode(', ', array_map(static fn (string $file): string => $updatable[$file]['name'], $files));

        if (!$result['ok']) {
            $failure = $this->failed($site, $back, AuditLog::ACTION_PLUGIN_UPDATE, 'Aktualizace se nezdařila (' . $names . ')', (string) $result['error']);

            return $json ? Response::json(['ok' => false, 'error' => (string) $result['error']], 502) : $failure;
        }

        $updated = [];
        $failed = [];

        foreach ((array) ($result['data']['plugins'] ?? []) as $item) {
            $name = (string) ($item['name'] ?? $item['file'] ?? '');

            if (($item['status'] ?? '') === 'updated') {
                $updated[] = $name . ' ' . $item['from'] . ' → ' . $item['to'];
            } elseif (($item['status'] ?? '') === 'failed') {
                $failed[] = $name . ' (' . (string) ($item['message'] ?? '') . ')';
                $this->kernel->events()->record((int) $id, EventLog::KIND_PLUGIN, 'error', $name . ' se nepodařilo aktualizovat: ' . (string) ($item['message'] ?? ''), ['action' => 'update_failed', 'plugin' => $name], $this->actorName());
            }
        }

        // Čerstvá data z webu: bez skriptu vždy, ze skriptu jen po posledním pluginu.
        if (!$json || $this->request()->bool('refresh')) {
            $this->refresh((int) $id);
        }

        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_PLUGIN_UPDATE, $failed === [],
            'Aktualizace pluginů: ' . ($updated !== [] ? implode(', ', $updated) : 'nic') . ($failed !== [] ? ' · selhalo: ' . implode(', ', $failed) : ''),
            ['plugins' => $result['data']['plugins'] ?? []]);

        $message = $updated !== [] ? 'Aktualizováno: ' . implode(', ', $updated) : 'Nic se neaktualizovalo — pluginy už jsou v nejnovější verzi.';

        if ($failed !== []) {
            $message .= ' · Nepodařilo se: ' . implode(', ', $failed);
        }

        if ($json) {
            return Response::json(['ok' => true, 'items' => array_values((array) ($result['data']['plugins'] ?? [])), 'message' => $message]);
        }

        return $this->redirectWithFlash($back, $message, $failed === [] ? 'success' : 'warning');
    }

    // -----------------------------------------------------------------
    // Aktivace a deaktivace pluginu
    // -----------------------------------------------------------------

    /**
     * Deaktivace je první krok odebrání pluginu: web se zkontroluje, a teprve
     * pak se neaktivní plugin smaže košem. Obojí jde vrátit „Aktivovat".
     */
    public function deactivatePlugin(string $id): Response
    {
        return $this->setPluginActive($id, false);
    }

    public function activatePlugin(string $id): Response
    {
        return $this->setPluginActive($id, true);
    }

    private function setPluginActive(string $id, bool $active): Response
    {
        $site = $this->siteOr404((int) $id);
        $back = 'weby/' . $id . '/pluginy';
        $blocked = SiteActions::blocked($site, $this->kernel->snapshots()->snapshot((int) $id), PluginClient::ACTION_PLUGIN_ACTIVATION);

        if ($blocked !== null) {
            return $this->redirectWithFlash($back, $blocked, 'warning');
        }

        $file = $this->request()->string('plugin');
        $plugin = null;

        foreach ($this->kernel->snapshots()->plugins((int) $id) as $row) {
            if ((string) $row['file'] === $file && SiteActions::canToggleActive($row)) {
                $plugin = $row;
            }
        }

        if ($plugin === null || ((int) $plugin['is_active'] === 1) === $active) {
            return $this->redirectWithFlash($back, $active ? 'Aktivovat jde jen neaktivní plugin, který na webu je.' : 'Deaktivovat jde jen aktivní plugin (kromě MEDIAGRAFIK Monitoru).', 'warning');
        }

        $name = (string) $plugin['name'];
        $what = $active ? 'aktivovat' : 'deaktivovat';
        $result = $this->kernel->pluginClient()->setPluginActive((string) $site['url'], $this->apiKey($site), $file, $active);

        if (!$result['ok']) {
            return $this->failed($site, $back, AuditLog::ACTION_PLUGIN_ACTIVATION, $name . ' se nepodařilo ' . $what, (string) $result['error']);
        }

        // Událost „X aktivován / deaktivován" zapíše import z rozdílu.
        $this->refresh((int) $id);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_PLUGIN_ACTIVATION, true, ($active ? 'Aktivován plugin ' : 'Deaktivován plugin ') . $name);

        return $this->redirectWithFlash($back, $active
            ? 'Plugin ' . $name . ' je aktivní.'
            : 'Plugin ' . $name . ' je deaktivovaný. Zkontrolujte, že web funguje — pak ho můžete smazat ikonou koše, nebo znovu aktivovat.');
    }

    // -----------------------------------------------------------------
    // Smazání neaktivního pluginu
    // -----------------------------------------------------------------

    public function deleteForm(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $back = 'weby/' . $id . '/pluginy';
        $blocked = SiteActions::blocked($site, $this->kernel->snapshots()->snapshot((int) $id), PluginClient::ACTION_PLUGIN_DELETE);

        if ($blocked !== null) {
            return $this->redirectWithFlash($back, $blocked, 'warning');
        }

        $plugin = $this->deletablePlugin((int) $id, $this->request()->string('plugin'));

        if ($plugin === null) {
            return $this->redirectWithFlash($back, 'Smazat jde jen neaktivní plugin, který na webu je.', 'warning');
        }

        return $this->view('sites/plugin-delete', $this->header($site, 'pluginy') + [
            'plugin' => $plugin,
        ]);
    }

    public function deletePlugin(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $back = 'weby/' . $id . '/pluginy';
        $blocked = SiteActions::blocked($site, $this->kernel->snapshots()->snapshot((int) $id), PluginClient::ACTION_PLUGIN_DELETE);

        if ($blocked !== null) {
            return $this->redirectWithFlash($back, $blocked, 'warning');
        }

        $plugin = $this->deletablePlugin((int) $id, $this->request()->string('plugin'));

        if ($plugin === null) {
            return $this->redirectWithFlash($back, 'Smazat jde jen neaktivní plugin, který na webu je.', 'warning');
        }

        $name = (string) $plugin['name'];
        $this->keepRunning();
        $result = $this->kernel->pluginClient()->deletePlugins((string) $site['url'], $this->apiKey($site), [(string) $plugin['file']]);

        if (!$result['ok']) {
            return $this->failed($site, $back, AuditLog::ACTION_PLUGIN_DELETE, $name . ' se nepodařilo smazat', (string) $result['error']);
        }

        $item = (array) (($result['data']['plugins'] ?? [])[0] ?? []);

        if (($item['status'] ?? '') !== 'deleted') {
            return $this->failed($site, $back, AuditLog::ACTION_PLUGIN_DELETE, $name . ' se nepodařilo smazat', (string) ($item['message'] ?? 'Web plugin nesmazal.'));
        }

        // Událost „X odstraněn" zapíše import — plugin v souhrnu chybí.
        $this->refresh((int) $id);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_PLUGIN_DELETE, true, 'Smazán plugin ' . $name . ' ' . $plugin['version']);

        return $this->redirectWithFlash($back, 'Plugin ' . $name . ' je smazaný.');
    }

    // -----------------------------------------------------------------
    // Aktualizace WordPressu
    // -----------------------------------------------------------------

    public function coreForm(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $snapshot = $this->kernel->snapshots()->snapshot((int) $id);
        $unavailable = $this->coreUnavailable($site, $snapshot);

        if ($unavailable !== null) {
            return $this->redirectWithFlash('weby/' . $id, $unavailable, 'warning');
        }

        $lastBackup = $snapshot['last_backup_at'] !== null ? (string) $snapshot['last_backup_at'] : null;

        return $this->view('sites/core-update', $this->header($site, 'prehled') + [
            'from' => (string) $snapshot['wp_version'],
            'to' => (string) $snapshot['wp_update_version'],
            'major' => self::isMajor((string) $snapshot['wp_version'], (string) $snapshot['wp_update_version']),
            'backupNote' => $lastBackup !== null ? 'Poslední záloha webu ' . get_when($lastBackup) . '.' : 'Plugin na webu žádnou zálohu nenašel.',
            'backupOld' => $lastBackup === null || strtotime($lastBackup) < time() - 2 * 86400,
        ]);
    }

    public function updateCore(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $snapshot = $this->kernel->snapshots()->snapshot((int) $id);
        $unavailable = $this->coreUnavailable($site, $snapshot);

        if ($unavailable !== null) {
            return $this->redirectWithFlash('weby/' . $id, $unavailable, 'warning');
        }

        // Potvrzovala se konkrétní verze z formuláře; nesedí-li s tím, co
        // teď hlásí web, data se mezitím změnila a potvrzení neplatí.
        $version = $this->request()->string('version');

        if ($version !== (string) $snapshot['wp_update_version']) {
            return $this->redirectWithFlash('weby/' . $id . '/wordpress', 'Web mezitím hlásí jinou verzi — zkontrolujte ji a potvrďte znovu.', 'warning');
        }

        $this->keepRunning(900);
        $result = $this->kernel->pluginClient()->updateCore((string) $site['url'], $this->apiKey($site), $version);

        if (!$result['ok']) {
            return $this->failed($site, 'weby/' . $id, AuditLog::ACTION_CORE_UPDATE, 'Aktualizace WordPressu na ' . $version . ' se nezdařila', (string) $result['error'], EventLog::KIND_CORE);
        }

        $from = (string) ($result['data']['core']['from'] ?? $snapshot['wp_version']);
        $to = (string) ($result['data']['core']['to'] ?? $version);

        // Událost „WordPress aktualizován" zapíše import z rozdílu verzí.
        $this->refresh((int) $id);
        $this->kernel->audit()->record((int) $id, (string) $site['name'], AuditLog::ACTION_CORE_UPDATE, true, 'WordPress ' . $from . ' → ' . $to);

        return $this->redirectWithFlash('weby/' . $id, 'WordPress je aktualizovaný: ' . $from . ' → ' . $to . '. Zkontrolujte, že web funguje.');
    }

    // -----------------------------------------------------------------
    // Přihlášení do administrace jedním klikem
    // -----------------------------------------------------------------

    /**
     * Tlačítko „wp-admin" (formulář do nového okna): správa si od pluginu
     * vyžádá jednorázový odkaz a prohlížeč na něj přesměruje. Když to nejde
     * (starší plugin, nenastavený účet), otevře se obyčejné přihlášení —
     * tlačítko nikdy neskončí slepě.
     */
    public function login(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $user = SiteActions::loginUser($site, $this->kernel->settings()->get(SiteActions::LOGIN_USER_SETTING));
        $blocked = SiteActions::blocked($site, $this->kernel->snapshots()->snapshot((int) $id), PluginClient::ACTION_LOGIN_LINK);

        if ($blocked !== null || $user === '') {
            return Response::redirect($this->adminUrl($site));
        }

        $result = $this->kernel->pluginClient()->loginLink((string) $site['url'], $this->apiKey($site), $user);
        $url = (string) ($result['data']['login']['url'] ?? '');

        if (!$result['ok'] || !str_starts_with($url, 'http')) {
            return $this->redirectWithFlash('weby/' . $id, 'Přihlášení jedním klikem se nepodařilo: ' . ($result['error'] ?? 'web nevrátil odkaz') . ' Otevřete wp-admin a přihlaste se heslem.', 'error');
        }

        return Response::redirect($url);
    }

    // -----------------------------------------------------------------
    // Pomocníci
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $site */
    private function adminUrl(array $site): string
    {
        return (string) $site['admin_url'] !== '' ? (string) $site['admin_url'] : rtrim((string) $site['url'], '/') . '/wp-admin/';
    }

    /**
     * Proč teď WordPress aktualizovat nejde (null = jde).
     *
     * @param array<string, mixed>      $site
     * @param array<string, mixed>|null $snapshot
     */
    private function coreUnavailable(array $site, ?array $snapshot): ?string
    {
        $blocked = SiteActions::blocked($site, $snapshot, PluginClient::ACTION_CORE_UPDATE);

        if ($blocked !== null) {
            return $blocked;
        }

        return $snapshot['wp_update_version'] === null ? 'Web žádnou novější verzi WordPressu nenabízí.' : null;
    }

    /** Hlavní verze (6.8 → 6.9) mění víc než opravná (6.8.1 → 6.8.2). */
    private static function isMajor(string $from, string $to): bool
    {
        $minor = static fn (string $version): string => implode('.', array_slice(explode('.', $version), 0, 2));

        return $minor($from) !== $minor($to);
    }

    /** @return array<string, mixed>|null řádek `site_plugins`, jen když jde smazat */
    private function deletablePlugin(int $siteId, string $file): ?array
    {
        foreach ($this->kernel->snapshots()->plugins($siteId) as $plugin) {
            if ((string) $plugin['file'] === $file) {
                return SiteActions::isDeletable($plugin) ? $plugin : null;
            }
        }

        return null;
    }

    /**
     * Web stahuje a rozbaluje balíčky; zavřený prohlížeč nesmí akci utnout
     * uprostřed — výsledek se i tak zapíše do historie.
     */
    private function keepRunning(int $seconds = 300): void
    {
        ignore_user_abort(true);
        @set_time_limit($seconds);
    }

    /** Čerstvý souhrn z webu — nové verze v záložkách, změny do historie. */
    private function refresh(int $siteId): void
    {
        $this->kernel->monitor()->checkOne($this->siteOr404($siteId));
    }

    /** Selhání akce: historie, audit a chybová zpráva. @param array<string, mixed> $site */
    private function failed(array $site, string $back, string $action, string $what, string $error, string $kind = EventLog::KIND_PLUGIN): Response
    {
        $siteId = (int) $site['id'];
        $this->kernel->events()->record($siteId, $kind, 'error', $what . ': ' . $error, ['action' => $action . '-failed'], $this->actorName());
        $this->kernel->audit()->record($siteId, (string) $site['name'], $action, false, $what, ['error' => $error]);

        return $this->redirectWithFlash($back, $what . ': ' . $error, 'error');
    }

    /** @param array<string, mixed> $site */
    private function apiKey(array $site): string
    {
        return (string) $this->kernel->sites()->apiKey($site);
    }

    private function actorName(): string
    {
        $user = $this->kernel->auth()->current();

        return $user !== null ? UserRepository::displayName($user) : '';
    }

    /** @return array<string, mixed> */
    private function siteOr404(int $id): array
    {
        $site = $this->kernel->sites()->findWithSnapshot($id);

        if ($site === null || $site['removed_at'] !== null) {
            throw HttpException::notFound('Web neexistuje nebo byl odebrán z monitoringu.');
        }

        return $site;
    }
}
