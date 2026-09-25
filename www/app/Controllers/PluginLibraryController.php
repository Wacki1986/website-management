<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\UserRepository;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Monitor\PluginClient;
use App\Core\Plugin\PluginDistribution;
use App\Core\Plugin\PluginLibrary;
use App\Core\Sites\SiteActions;

/**
 * Knihovna pluginů — placené a vlastní pluginy mimo wordpress.org.
 *
 * Stránka `/knihovna` (nahrání ZIPu, přehled, na kterých webech plugin je,
 * „Aktualizovat všude" — i pro náš MEDIAGRAFIK Monitor v samostatné kartě)
 * a dvě veřejné adresy pro plugin MEDIAGRAFIK Monitor na webech — pod
 * `/plugin/mediagrafik-monitor/`, kde už je výjimka z Basic auth:
 *
 *  - `knihovna.json` — seznam pluginů s odkazy ke stažení; web se prokáže
 *    otiskem svého API klíče (hlavička `X-MG-Key-Hash`),
 *  - `knihovna/<slug>.zip?web=&podpis=` — ZIP, jen s podpisem pro daný web.
 */
final class PluginLibraryController extends Controller
{
    /**
     * Do kolika webů se plugin vypisuje celý. U víc webů se aktuální schovají
     * pod „+ 12 aktuálních" — jménem zůstanou jen ty, kde je co dělat.
     */
    private const LISTED_SITES = 5;

    public function index(): Response
    {
        $library = $this->kernel->pluginLibrary();
        $usage = $library->usage();
        $rows = [];

        foreach ($library->all() as $plugin) {
            $rows[] = $this->row($plugin, $usage[(string) $plugin['file']] ?? [], true) + [
                'sizeLabel' => self::size((int) $plugin['size']),
                'uploaded' => get_when((string) $plugin['uploaded_at']) . ((string) $plugin['uploaded_by'] !== '' ? ' · ' . $plugin['uploaded_by'] : ''),
                'downloadUrl' => get_url('knihovna/' . rawurlencode((string) $plugin['slug']) . '/stahnout'),
                'removeAction' => get_url('knihovna/' . rawurlencode((string) $plugin['slug']) . '/smazat'),
            ];
        }

        $monitor = $this->monitorRow();

        return $this->view('library/index', [
            'title' => 'Knihovna pluginů',
            'monitor' => $monitor,
            'rows' => $rows,
            // Okno „Aktualizovat všude" jen u pluginů, kde je co aktualizovat.
            'dialogs' => array_values(array_filter([$monitor, ...$rows], static fn (?array $row): bool => $row !== null && $row['targets'] !== [])),
            'since' => PluginClient::LIBRARY_SINCE,
            'maxUpload' => (string) ini_get('upload_max_filesize'),
        ]);
    }

    /**
     * Náš MEDIAGRAFIK Monitor — do knihovny se nenahrává, rozdává ho správa
     * sama (`PluginDistribution`, ZIP dává `build-plugin.ps1`). Na stránce
     * je kvůli „Aktualizovat všude" po vydání nové verze. Null = správa
     * zatím žádnou verzi nerozdává.
     *
     * @return array<string, mixed>|null
     */
    private function monitorRow(): ?array
    {
        $info = $this->kernel->pluginDistribution()->info();

        if ($info === null) {
            return null;
        }

        $version = (string) $info['version'];
        $zip = $this->kernel->pluginDistribution()->zipPath(PluginDistribution::SLUG . '-' . $version . '.zip');
        $plugin = [
            'slug' => PluginDistribution::SLUG,
            'name' => (string) ($info['name'] ?? 'MEDIAGRAFIK Monitor'),
            'file' => PluginDistribution::SLUG . '/' . PluginDistribution::SLUG . '.php',
            'version' => $version,
        ];

        return $this->row($plugin, $this->kernel->pluginLibrary()->monitorUsage(), false) + [
            'sizeLabel' => $zip !== null ? self::size((int) filesize($zip)) : '',
            'uploaded' => isset($info['last_updated']) ? get_when((string) $info['last_updated']) : '',
            'downloadUrl' => (string) $info['download_url'],
            'removeAction' => null,
        ];
    }

    /**
     * Řádek tabulky a okno „Aktualizovat všude": které weby plugin mají,
     * které čekají na novou verzi a které z nich akce přeskočí (a proč).
     *
     * @param array<string, mixed> $plugin slug, name, file, version
     * @param array<int, array{id: int, name: string, version: string, file?: string}> $sites weby s pluginem
     * @param bool $fromLibrary plugin z knihovny (web ho umí stáhnout až s Monitorem 1.4.0+), ne Monitor sám
     * @return array<string, mixed>
     */
    private function row(array $plugin, array $sites, bool $fromLibrary): array
    {
        $outdated = [];
        $current = [];

        foreach ($sites as $site) {
            // Monitor může na webu ležet v jinak pojmenované složce — aktualizace
            // se posílá s cestou, jakou má ten web.
            $site += ['file' => (string) $plugin['file'], 'url' => get_url('weby/' . $site['id'] . '/pluginy')];

            if (version_compare($site['version'], (string) $plugin['version'], '<')) {
                $outdated[] = $site + [
                    'outdated' => true,
                    'blocked' => $this->updateBlocked($site['id'], $site['file'], $fromLibrary),
                    'updateAction' => get_url('weby/' . $site['id'] . '/pluginy/aktualizovat'),
                ];
            } else {
                $current[] = $site + ['outdated' => false, 'blocked' => null];
            }
        }

        $collapse = count($outdated) + count($current) > self::LISTED_SITES;

        return $plugin + [
            'sites' => $outdated !== [] || $current !== [],
            'listedSites' => $collapse ? $outdated : array_merge($outdated, $current),
            'hiddenSites' => $collapse ? $current : [],
            'hiddenLabel' => $outdated !== []
                ? '+ ' . get_count(count($current), 'aktuální', 'aktuální', 'aktuálních')
                : 'na ' . get_count(count($current), 'webu', 'webech', 'webech'),
            'outdated' => count($outdated),
            'targets' => array_values(array_filter($outdated, static fn (array $site): bool => $site['blocked'] === null)),
            'skipped' => array_values(array_filter($outdated, static fn (array $site): bool => $site['blocked'] !== null)),
            'dialogId' => 'library-update-' . preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $plugin['slug'])),
        ];
    }

    private static function size(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' MB';
    }

    public function upload(): Response
    {
        try {
            $result = $this->kernel->pluginLibrary()->upload($this->request()->files['plugin'] ?? null, $this->actorName());
        } catch (HttpException $e) {
            return $this->redirectWithFlash('knihovna', $e->getMessage(), 'error');
        }

        $entry = $result['entry'];
        $what = $entry['name'] . ' ' . $entry['version'];
        // Nová verze v knihovně mění, co se kde nabízí k aktualizaci — počty
        // ve výpisu webů a na dashboardu se přepočítají hned, ne až s další kontrolou.
        $this->kernel->snapshots()->recountAll();
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_SETTINGS, true, 'Knihovna pluginů: nahrán ' . $what . ($result['previous'] !== null ? ' (místo ' . $result['previous'] . ')' : ''));

        return $this->redirectWithFlash('knihovna', $result['previous'] !== null && $result['previous'] !== (string) $entry['version']
            ? 'Nahráno ' . $what . ' místo ' . $result['previous'] . '. Weby s pluginem ho dostanou nabídnutý k aktualizaci.'
            : 'Nahráno ' . $what . '.');
    }

    public function remove(string $slug): Response
    {
        $plugin = $this->pluginOr404($slug);
        $this->kernel->pluginLibrary()->remove($slug);
        $this->kernel->snapshots()->recountAll();
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_SETTINGS, true, 'Knihovna pluginů: odebrán ' . $plugin['name']);

        return $this->redirectWithFlash('knihovna', 'Plugin ' . $plugin['name'] . ' je z knihovny odebraný. Na webech zůstává, jen se už nebude nabízet jeho aktualizace.');
    }

    /** Stažení pro ruční instalaci na nový web (přihlášení). */
    public function download(string $slug): Response
    {
        $plugin = $this->pluginOr404($slug);

        return $this->zip($this->kernel->pluginLibrary()->zipPath($slug), $slug . '-' . $plugin['version'] . '.zip');
    }

    // -----------------------------------------------------------------
    // Pro plugin na webech (veřejné adresy, ověření vlastní)
    // -----------------------------------------------------------------

    public function manifest(): Response
    {
        $site = $this->kernel->sites()->findByKeyHash($this->request()->header('X-MG-Key-Hash'));

        if ($site === null) {
            return Response::json(['ok' => false, 'error' => ['code' => 'unknown_site', 'message' => 'Web se neprokázal platným API klíčem.']], 403)
                ->withHeader('Cache-Control', 'no-store');
        }

        return Response::json(['ok' => true, 'plugins' => $this->kernel->pluginLibrary()->manifest((int) $site['id'], $this->kernel->appUrl())])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function package(string $file): Response
    {
        $library = $this->kernel->pluginLibrary();
        $slug = preg_replace('/\.zip$/', '', rawurldecode($file)) ?? '';
        $siteId = (int) $this->request()->int('web', 0);
        $site = $this->kernel->sites()->find($siteId);

        // Neplatný podpis i odebraný web = jako by ZIP neexistoval.
        if ($site === null || $site['removed_at'] !== null || !$library->validToken($slug, $siteId, $this->request()->string('podpis')) || $library->find($slug) === null) {
            throw HttpException::notFound('Balíček neexistuje.');
        }

        return $this->zip($library->zipPath($slug), $slug . '.zip');
    }

    // -----------------------------------------------------------------

    /**
     * Proč „Aktualizovat všude" web se starší verzí přeskočí (null = zařadí
     * ho). Stejná pravidla jako tlačítko na záložce Pluginy webu — akce
     * „všude" posílá weby právě tam, po jednom.
     */
    private function updateBlocked(int $siteId, string $file, bool $fromLibrary): ?string
    {
        $site = $this->kernel->sites()->find($siteId);
        $snapshot = $this->kernel->snapshots()->snapshot($siteId);

        if ($site === null) {
            return 'Web nejde najít.';
        }

        $blocked = SiteActions::blocked($site, $snapshot, PluginClient::ACTION_PLUGIN_UPDATE);

        if ($blocked !== null) {
            return $blocked;
        }

        $plugins = $this->kernel->snapshots()->plugins($siteId);

        if (isset($this->kernel->pluginOffers()->forSite($plugins, $snapshot)[$file])) {
            return null;
        }

        if (!$fromLibrary) {
            return 'Web aktualizaci teď nenabízí — zkuste ji na záložce Pluginy webu.';
        }

        $row = current(array_filter($plugins, static fn (array $plugin): bool => (string) $plugin['file'] === $file));

        return SiteActions::libraryUnoffered($row !== false ? $row : [], $snapshot);
    }

    private function zip(string $path, string $downloadName): Response
    {
        if (!is_file($path)) {
            throw HttpException::notFound('ZIP pluginu na disku chybí — nahrajte ho do knihovny znovu.');
        }

        return Response::stream(
            static function () use ($path): void {
                readfile($path);
            },
            [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) filesize($path),
                'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $downloadName) . '"',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function pluginOr404(string $slug): array
    {
        $plugin = $this->kernel->pluginLibrary()->find($slug);

        if ($plugin === null) {
            throw HttpException::notFound('Plugin v knihovně není.');
        }

        return $plugin;
    }

    private function actorName(): string
    {
        $user = $this->kernel->auth()->current();

        return $user !== null ? UserRepository::displayName($user) : '';
    }
}
