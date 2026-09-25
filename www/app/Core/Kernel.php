<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AlertController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\CredentialController;
use App\Controllers\DashboardController;
use App\Controllers\ForgottenPasswordController;
use App\Controllers\MonitorCronController;
use App\Controllers\PluginDistributionController;
use App\Controllers\PluginLibraryController;
use App\Controllers\ReportController;
use App\Controllers\TrackingController;
use App\Controllers\TwoFactorController;
use App\Controllers\ServiceController;
use App\Controllers\SettingsController;
use App\Controllers\SiteActionController;
use App\Controllers\SiteController;
use App\Core\Monitor\AlertEngine;
use App\Core\Monitor\AlertRepository;
use App\Core\Monitor\DbSupport;
use App\Core\Monitor\DomainChecker;
use App\Core\Monitor\MonitorRun;
use App\Core\Monitor\MonitorSettings;
use App\Core\Monitor\Notifier;
use App\Core\Monitor\PhpSupport;
use App\Core\Monitor\PluginDirectory;
use App\Core\Monitor\SslChecker;
use App\Core\Monitor\SupportTables;
use App\Core\Monitor\UptimeClient;
use App\Core\Monitor\UptimeRepository;
use App\Core\Audit\AuditLog;
use App\Core\Clients\Ares;
use App\Core\Clients\ClientRepository;
use App\Core\Events\EventLog;
use App\Core\Monitor\OutsideProbe;
use App\Core\Monitor\PluginClient;
use App\Core\Monitor\SecurityAudit;
use App\Core\Monitor\SnapshotImporter;
use App\Core\Dashboard\DashboardData;
use App\Core\Plugin\PluginDistribution;
use App\Core\Plugin\PluginLibrary;
use App\Core\Reports\ReportBuilder;
use App\Core\Reports\ReportRenderer;
use App\Core\Reports\ReportRepository;
use App\Core\Reports\ReportSender;
use App\Core\Service\ServiceChecklists;
use App\Core\Service\ServiceRepository;
use App\Core\Sites\PluginOffers;
use App\Core\Sites\SiteCredentials;
use App\Core\Sites\SiteIcons;
use App\Core\Sites\SiteRepository;
use App\Core\Auth\Auth;
use App\Core\Auth\Avatars;
use App\Core\Auth\LoginRateLimiter;
use App\Core\Auth\PasswordReset;
use App\Core\Auth\RememberMe;
use App\Core\Auth\TwoFactor;
use App\Core\Auth\UserRepository;
use App\Core\Db\Connection;
use App\Core\Db\Migrator;
use App\Core\Http\Controller;
use App\Core\Http\Csrf;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Log\Logger;
use App\Core\Notifications\MailSettings;
use App\Core\Notifications\Mailer;
use App\Core\Notifications\PushNotifier;
use App\Core\Notifications\PushSubscriptions;
use App\Core\Notifications\VapidKeys;
use App\Core\Notifications\WebPush\WebPush;
use App\Core\Security\RateLimiter;
use App\Core\Security\Secrets;
use App\Core\Settings\Settings;
use App\Core\View\View;
use Throwable;

/**
 * Jádro Správy webů.
 *
 * Převzaté ze správy instancí dispu (stejný Router, View, Connection,
 * Migrator i celá autentizace). Co tu záměrně není: moduly, role s právy
 * (uživatelé jsou si rovni, role je jen štítek), firemní kód a instalační
 * průvodce — aplikaci nasazuje tentýž člověk, který ji používá.
 *
 * Služby vznikají líně: přihlašovací stránka nesmí spadnout jen proto, že
 * databáze zrovna neodpovídá.
 */
final class Kernel
{
    public const VERSION = '0.7.2';

    /**
     * Kam smí přihlášený účet, který ještě nemá spárovaný telefon
     * (povinné dvoufázové přihlášení, viz `handle()`): párování a odhlášení.
     */
    private const ROUTES_BEFORE_TWO_FACTOR = [
        'settings.2fa.show',
        'settings.2fa.enable',
        'settings.2fa.codes',
        'auth.logout',
    ];

    /** Název aplikace — v liště a v předmětech e-mailů. */
    public const APP_NAME = 'Správa webů';

    private ?Connection $db = null;
    private ?Logger $logger = null;
    private ?Secrets $secrets = null;
    private ?Csrf $csrf = null;
    private ?Router $router = null;
    private ?View $view = null;
    private ?Migrator $migrator = null;
    private ?RateLimiter $limiter = null;
    private ?LoginRateLimiter $loginRateLimiter = null;
    private ?UserRepository $users = null;
    private ?Avatars $avatars = null;
    private ?RememberMe $rememberMe = null;
    private ?Auth $auth = null;
    private ?TwoFactor $twoFactor = null;
    private ?SiteCredentials $credentials = null;
    private ?AuditLog $audit = null;
    private ?Settings $settings = null;
    private ?MailSettings $mailSettings = null;
    private ?Mailer $mailer = null;
    private ?PasswordReset $passwordReset = null;
    private ?VapidKeys $vapidKeys = null;
    private ?PushSubscriptions $pushSubscriptions = null;
    private ?PushNotifier $pushNotifier = null;
    private ?ClientRepository $clients = null;
    private ?SiteRepository $sites = null;
    private ?EventLog $events = null;
    private ?PluginClient $pluginClient = null;
    private ?SnapshotImporter $snapshots = null;
    private ?OutsideProbe $outsideProbe = null;
    private ?SecurityAudit $securityAudit = null;
    private ?PluginDistribution $pluginDistribution = null;
    private ?MonitorSettings $monitorSettings = null;
    private ?UptimeRepository $uptime = null;
    private ?AlertRepository $alerts = null;
    private ?AlertEngine $alertEngine = null;
    private ?Notifier $notifier = null;
    private ?MonitorRun $monitor = null;
    private ?SupportTables $supportTables = null;
    private ?PluginDirectory $pluginDirectory = null;
    private ?ServiceRepository $service = null;
    private ?ReportRepository $reports = null;
    private ?ReportBuilder $reportBuilder = null;
    private ?ReportRenderer $reportRenderer = null;
    private ?ReportSender $reportSender = null;
    private ?DashboardData $dashboard = null;

    private ?Request $request = null;
    private string $basePath = '';

    /** @var array<int, array{message: string, type: string}>|null */
    private ?array $flashes = null;

    private ?string $scriptNonce = null;

    /** @param array<string, mixed> $env obsah config/env.php */
    public function __construct(
        private readonly array $env,
        private readonly string $rootPath,
    ) {
    }

    // -----------------------------------------------------------------
    // Konfigurace a cesty
    // -----------------------------------------------------------------

    public function env(string $key, mixed $default = null): mixed
    {
        return $this->env[$key] ?? $default;
    }

    public function isProduction(): bool
    {
        return (string) $this->env('environment', 'production') === 'production';
    }

    public function rootPath(string $append = ''): string
    {
        return rtrim($this->rootPath, '/\\') . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }

    public function storagePath(string $append = ''): string
    {
        $configured = (string) $this->env('storage_path', $this->rootPath('storage'));

        return rtrim($configured, '/\\') . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }

    /** Absolutní URL aplikace (z configu, nikdy z hlavičky Host). */
    public function appUrl(string $append = ''): string
    {
        return rtrim((string) $this->env('app_url', ''), '/') . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }

    /** URL v rámci instalace (funguje i v podadresáři). */
    public function url(string $path = '/'): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $this->basePath . '/' . ltrim($path, '/');
    }

    /**
     * URL statického souboru s otiskem podle času změny — na assety míří
     * roční cache a bez otisku by po nasazení zůstal starý styl.
     */
    public function asset(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $relative = ltrim($path, '/');
        $file = $this->rootPath('assets/' . $relative);
        $stamp = is_file($file) ? (string) filemtime($file) : self::VERSION;

        return $this->url('assets/' . $relative) . '?v=' . substr(md5($stamp), 0, 8);
    }

    /**
     * Nonce pro inline skripty (CSP `script-src`) — jeden na požadavek.
     * Jediný inline skript je import mapa (viz jsImportMap).
     */
    public function scriptNonce(): string
    {
        return $this->scriptNonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Import mapa JS modulů — verzování importů.
     *
     * Otisk `?v=` nese jen `<script src="app.js">`; moduly, které si app.js
     * importuje, by prohlížeč nechal staré z cache. Mapa přesměruje každý
     * modul na jeho verzovanou adresu; layout ji vypisuje PŘED
     * `<script type="module">`.
     *
     * @return array{imports: array<string, string>}
     */
    public function jsImportMap(): array
    {
        $imports = [];

        // Moduly aplikace i převzaté knihovny (vendor), které si moduly načítají.
        foreach (['modules', 'vendor'] as $dir) {
            foreach (glob($this->rootPath('assets/js/' . $dir . '/*.js')) ?: [] as $file) {
                $name = $dir . '/' . basename($file);
                $imports[$this->url('assets/js/' . $name)] = $this->asset('js/' . $name);
            }
        }

        return ['imports' => $imports];
    }

    // -----------------------------------------------------------------
    // Služby
    // -----------------------------------------------------------------

    public function db(): Connection
    {
        return $this->db ??= new Connection((array) $this->env('database', []));
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->storagePath('logs'));
    }

    public function secrets(): Secrets
    {
        return $this->secrets ??= new Secrets((string) $this->env('app_key', ''));
    }

    public function csrf(): Csrf
    {
        return $this->csrf ??= new Csrf();
    }

    public function view(): View
    {
        return $this->view ??= new View($this->rootPath('app/templates'));
    }

    public function migrator(): Migrator
    {
        if ($this->migrator === null) {
            $this->migrator = new Migrator($this->db());
            $this->migrator->addPath('core', $this->rootPath('database/migrations'));
        }

        return $this->migrator;
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->db());
    }

    public function loginRateLimiter(): LoginRateLimiter
    {
        return $this->loginRateLimiter ??= new LoginRateLimiter($this->limiter());
    }

    public function users(): UserRepository
    {
        return $this->users ??= new UserRepository($this->db());
    }

    public function avatars(): Avatars
    {
        return $this->avatars ??= new Avatars($this->users(), $this->storagePath());
    }

    public function rememberMe(): RememberMe
    {
        // Cesta cookie se odvíjí od basePath — ten vzniká v handle(), služba
        // se staví líně až potom, takže je hodnota už známá.
        return $this->rememberMe ??= new RememberMe($this->db(), $this->basePath === '' ? '/' : $this->basePath);
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this->users(), $this->rememberMe(), $this->loginRateLimiter(), $this->csrf(), $this->twoFactor());
    }

    public function twoFactor(): TwoFactor
    {
        return $this->twoFactor ??= new TwoFactor($this->users(), $this->secrets());
    }

    public function audit(): AuditLog
    {
        return $this->audit ??= new AuditLog($this->db(), $this->auth());
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db(), $this->secrets());
    }

    public function mailSettings(): MailSettings
    {
        return $this->mailSettings ??= new MailSettings($this->settings(), (array) $this->env('mail', []));
    }

    public function mailer(): Mailer
    {
        if ($this->mailer === null) {
            // Logo do hlavičky e-mailů (Nastavení → Odchozí pošta). Bez
            // databáze se prostě nepoužije — hlavičku nese název odesílatele.
            try {
                $logoUrl = $this->settings()->get('mail_logo_url');
            } catch (Throwable) {
                $logoUrl = '';
            }

            $this->mailer = new Mailer($this->mailSettings(), $this->logger(), $this->storagePath('logs'), $logoUrl);
        }

        return $this->mailer;
    }

    public function passwordReset(): PasswordReset
    {
        return $this->passwordReset ??= new PasswordReset($this->db());
    }

    public function vapidKeys(): VapidKeys
    {
        return $this->vapidKeys ??= new VapidKeys($this->settings(), (string) $this->env('app_url', ''));
    }

    public function pushSubscriptions(): PushSubscriptions
    {
        return $this->pushSubscriptions ??= new PushSubscriptions($this->db());
    }

    /**
     * Upozornění na telefon. Bez klíčů, bez databáze i bez `openssl` se
     * postaví — jen pak `ready()` řekne ne a `send()` mlčí. Push nikdy nesmí
     * být důvod, proč stránka nejde.
     */
    public function pushNotifier(): PushNotifier
    {
        if ($this->pushNotifier === null) {
            try {
                $vapid = $this->vapidKeys()->vapid();
            } catch (Throwable) {
                $vapid = null;
            }

            $this->pushNotifier = new PushNotifier(
                $this->pushSubscriptions(),
                $this->settings(),
                $this->limiter(),
                $this->logger(),
                $vapid !== null ? new WebPush($vapid) : null,
                $this->url('/'),
            );
        }

        return $this->pushNotifier;
    }

    // --- weby, klienti, plugin ----------------------------------------

    public function clients(): ClientRepository
    {
        return $this->clients ??= new ClientRepository($this->db());
    }

    public function ares(): Ares
    {
        return new Ares();
    }

    public function sites(): SiteRepository
    {
        return $this->sites ??= new SiteRepository($this->db(), $this->secrets());
    }

    public function credentials(): SiteCredentials
    {
        return $this->credentials ??= new SiteCredentials($this->db(), $this->secrets());
    }

    public function events(): EventLog
    {
        return $this->events ??= new EventLog($this->db());
    }

    public function pluginClient(): PluginClient
    {
        return $this->pluginClient ??= new PluginClient();
    }

    public function snapshots(): SnapshotImporter
    {
        return $this->snapshots ??= new SnapshotImporter($this->db(), $this->sites(), $this->events(), $this->pluginOffers());
    }

    public function pluginOffers(): PluginOffers
    {
        return new PluginOffers($this->pluginDirectory(), $this->pluginDistribution(), $this->pluginLibrary());
    }

    public function outsideProbe(): OutsideProbe
    {
        return $this->outsideProbe ??= new OutsideProbe();
    }

    public function securityAudit(): SecurityAudit
    {
        return $this->securityAudit ??= new SecurityAudit($this->db(), $this->outsideProbe());
    }

    public function pluginDistribution(): PluginDistribution
    {
        return $this->pluginDistribution ??= new PluginDistribution($this->storagePath('plugin'), $this->appUrl());
    }

    /** Knihovna placených a vlastních pluginů (mimo wordpress.org). */
    public function pluginLibrary(): PluginLibrary
    {
        return new PluginLibrary($this->db(), $this->storagePath(), (string) $this->env('app_key', ''));
    }

    // --- monitoring a alerty ------------------------------------------

    public function monitorSettings(): MonitorSettings
    {
        return $this->monitorSettings ??= new MonitorSettings($this->settings());
    }

    public function uptime(): UptimeRepository
    {
        return $this->uptime ??= new UptimeRepository($this->db());
    }

    public function service(): ServiceRepository
    {
        return $this->service ??= new ServiceRepository($this->db());
    }

    /** Ikony webů do seznamů — favicona z webu nebo nahrané logo. */
    public function siteIcons(): SiteIcons
    {
        return new SiteIcons($this->db(), $this->sites(), $this->storagePath());
    }

    /** Seznamy úkolů k druhům servisu (Nastavení → Servis). */
    public function serviceChecklists(): ServiceChecklists
    {
        return new ServiceChecklists($this->settings());
    }

    public function dashboard(): DashboardData
    {
        return $this->dashboard ??= new DashboardData($this->sites(), $this->uptime(), $this->alerts(), $this->service(), $this->monitorSettings());
    }

    public function reports(): ReportRepository
    {
        return $this->reports ??= new ReportRepository($this->db());
    }

    public function reportBuilder(): ReportBuilder
    {
        return $this->reportBuilder ??= new ReportBuilder($this->sites(), $this->uptime(), $this->alerts(), $this->events(), $this->service(), $this->securityAudit(), $this->snapshots(), $this->pluginDirectory());
    }

    public function reportRenderer(): ReportRenderer
    {
        return $this->reportRenderer ??= new ReportRenderer();
    }

    public function reportSender(): ReportSender
    {
        return $this->reportSender ??= new ReportSender(
            $this->reports(), $this->reportBuilder(), $this->reportRenderer(), $this->sites(), $this->mailer(), $this->mailSettings(),
            $this->settings(), $this->events(), $this->notifier(), $this->logger(), $this->appUrl(),
        );
    }

    public function alerts(): AlertRepository
    {
        return $this->alerts ??= new AlertRepository($this->db());
    }

    public function notifier(): Notifier
    {
        return $this->notifier ??= new Notifier($this->mailer(), $this->pushNotifier(), $this->monitorSettings(), $this->logger(), $this->appUrl());
    }

    public function alertEngine(): AlertEngine
    {
        return $this->alertEngine ??= new AlertEngine($this->alerts(), $this->events(), $this->sites(), $this->notifier(), $this->monitorSettings(), $this->pluginDirectory());
    }

    /** Jeden průchod monitoru — cron i tlačítka „Zkontrolovat". */
    /** Pluginy v adresáři wordpress.org — opuštěné a stažené (krok cronu). */
    public function pluginDirectory(): PluginDirectory
    {
        return $this->pluginDirectory ??= new PluginDirectory($this->db(), $this->monitorSettings());
    }

    /** Konce podpory PHP a databází z endoflife.date (Nastavení → Monitoring, krok cronu). */
    public function supportTables(): SupportTables
    {
        return $this->supportTables ??= new SupportTables($this->settings());
    }

    public function monitor(): MonitorRun
    {
        if ($this->monitor !== null) {
            return $this->monitor;
        }

        // Cron běží mimo handle() — i on má počítat s aktuálními tabulkami.
        $this->supportTables()->apply();

        $this->monitor = new MonitorRun(
            $this->db(),
            $this->sites(),
            $this->uptime(),
            new SslChecker(),
            new DomainChecker(),
            $this->pluginClient(),
            $this->snapshots(),
            $this->securityAudit(),
            $this->alertEngine(),
            $this->notifier(),
            $this->monitorSettings(),
            $this->settings(),
            $this->limiter(),
            $this->events(),
            $this->logger(),
            $this->storagePath(MonitorRun::LOCK_FILE),
            new UptimeClient($this->monitorSettings()->int('monitor_timeout_s')),
            $this->service(),
        );

        // Reporty: den před termínem příprava ke schválení, v termínu odeslání.
        $this->monitor->addStep('reports', fn (int $now, float $deadline): int => $this->reportSender()->step($now));

        // Ikony webů až po reportech — nikam nespěchají. Vrací 0: součet
        // kroků se v souhrnu běhu počítá jako odeslané reporty.
        $this->monitor->addStep('icons', function (int $now, float $deadline): int {
            $this->siteIcons()->refreshStale($now, $deadline);

            return 0;
        });

        // Pluginy na wordpress.org: nejvýš 40 za průchod, každý jednou týdně.
        $this->monitor->addStep('plugin-directory', function (int $now, float $deadline): int {
            $this->pluginDirectory()->refresh($this->pluginDirectory()->dueSlugs($now), $now, $deadline);

            return 0;
        });

        // Konce podpory PHP a databází: jednou za měsíc čerstvá data.
        $this->monitor->addStep('support-tables', function (int $now, float $deadline): int {
            if ($this->supportTables()->isDue($now) && microtime(true) + 15 < $deadline) {
                $this->supportTables()->refresh($now);
            }

            return 0;
        });

        return $this->monitor;
    }

    public function router(): Router
    {
        if ($this->router === null) {
            $this->router = new Router();
            $this->registerRoutes($this->router);
        }

        return $this->router;
    }

    public function request(): Request
    {
        return $this->request ?? Request::fromGlobals();
    }

    // -----------------------------------------------------------------
    // Životní cyklus
    // -----------------------------------------------------------------

    public function boot(): void
    {
        date_default_timezone_set((string) $this->env('timezone', 'Europe/Prague'));
        mb_internal_encoding('UTF-8');

        error_reporting(E_ALL);
        ini_set('display_errors', $this->isProduction() ? '0' : '1');
        ini_set('log_errors', '1');
        ini_set('error_log', $this->storagePath('logs') . '/php-errors.log');
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $this->basePath = $this->detectBasePath($request);

        try {
            /**
             * Omezení na povolené adresy — vrstva NAD přihlášením. Primární
             * filtr patří do `.htaccess`; tahle kontrola je záložní a navíc
             * umí hezkou 403 s IP návštěvníka. Prázdný seznam = vypnuto.
             */
            if (!$this->ipAllowed($request->ip())) {
                return $this->renderHttpError(
                    new HttpException(403, 'Přístup k aplikaci je omezen na povolené adresy. '
                        . 'Připojujete se z ' . $request->ip() . ' — pokud je to vaše nová adresa, '
                        . 'přidejte ji do `allowed_ips` v config/env.php.'),
                    $request,
                );
            }

            $this->startSession($request);

            /**
             * Čekající migrace se aplikují samy, hned na začátku requestu.
             * Aplikaci používá pár lidí ze studia — tentýž člověk, který by
             * migraci stejně spustil ručně; stránka údržby by mu jen přidala
             * krok. Selhání se zaloguje a request skončí pětistovkou.
             */
            $this->runPendingMigrations();

            // Stažené konce podpory PHP a databází místo vestavěných tabulek.
            // Bez databáze (přihlášení, instalace) platí vestavěné tabulky.
            try {
                $this->supportTables()->apply();
            } catch (\Throwable) {
                PhpSupport::useTable(null);
                DbSupport::useTables(null);
            }

            $route = $this->router()->match($request->method, $request->path);

            if ($route === null) {
                throw HttpException::notFound();
            }

            $user = $this->auth()->user($request);

            if ($route['capability'] !== Router::PUBLIC_ACCESS && $user === null) {
                if ($request->wantsJson()) {
                    throw HttpException::unauthorized();
                }

                return Response::redirect($this->url('prihlaseni'));
            }

            /**
             * Dvoufázové přihlášení je povinné. Účet bez spárovaného telefonu
             * (nový kolega po pozvánce, odpárovaný účet) se dostane jen na
             * stránku párování — nic jiného, ani seznam webů, neuvidí.
             */
            if ($user !== null && $route['capability'] !== Router::PUBLIC_ACCESS
                && !in_array($route['name'], self::ROUTES_BEFORE_TWO_FACTOR, true)
                && $this->mustPairPhone($user)) {
                if ($request->wantsJson()) {
                    throw new HttpException(403, 'Nejdřív si spárujte telefon pro dvoufázové přihlášení.');
                }

                return Response::redirect($this->url('nastaveni/dvoufazove'));
            }

            // CSRF centrálně na každé mutaci.
            if ($request->isMutating() && $route['csrf'] && !$this->csrf()->validate($request)) {
                throw new HttpException(419, 'Platnost formuláře vypršela. Načtěte stránku znovu.');
            }

            $this->shareViewGlobals();

            [$class, $method] = $route['handler'];
            /** @var Controller $controller */
            $controller = new $class($this);

            $response = $controller->{$method}(...array_values($route['params']));

            return $response instanceof Response ? $response : Response::html((string) $response);
        } catch (HttpException $e) {
            return $this->renderHttpError($e, $request);
        } catch (Throwable $e) {
            return $this->renderServerError($e, $request);
        }
    }

    /**
     * Musí si přihlášený účet nejdřív spárovat telefon?
     *
     * Bez `app_key` se dvoufázové přihlášení zapnout nedá — vynucovat ho
     * by znamenalo zamknout všechny. Stránka párování pak chybějící klíč
     * sama ohlásí.
     *
     * @param array<string, mixed> $user
     */
    private function mustPairPhone(array $user): bool
    {
        return $this->twoFactor()->isAvailable() && !$this->twoFactor()->isEnabled($user);
    }

    // -----------------------------------------------------------------
    // Flash zprávy
    // -----------------------------------------------------------------

    public function flash(string $message, string $type = 'success'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_flash'][] = ['message' => $message, 'type' => $type];
        }
    }

    /** @return array<int, array{message: string, type: string}> */
    public function takeFlashes(): array
    {
        if ($this->flashes !== null) {
            return $this->flashes;
        }

        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $this->flashes = is_array($flashes) ? $flashes : [];
    }

    // -----------------------------------------------------------------
    // Vnitřnosti
    // -----------------------------------------------------------------

    /** @see handle() — proč se migrace spouští samy */
    private function runPendingMigrations(): void
    {
        try {
            if (!$this->migrator()->hasPending()) {
                return;
            }
        } catch (Throwable) {
            // Nedostupná databáze není „čekající migrace" — chybu ohlásí až
            // akce, která databázi opravdu potřebuje (login stránka ji nechce).
            return;
        }

        $report = $this->migrator()->run();

        if ($report['failed'] !== null) {
            $this->logger()->error('Migrace selhala', [
                'failed' => $report['failed'],
                'message' => $report['message'],
            ]);

            $this->pushNotifier()->send(
                'ops',
                'Migrace databáze selhala',
                $report['failed'] . ': ' . $report['message'],
                'nastaveni',
                onceKey: 'migrace',
            );

            throw new HttpException(500, 'Aktualizace databáze selhala: ' . $report['message']);
        }

        if ($report['applied'] !== []) {
            $this->logger()->info('Migrace aplikovány', ['applied' => $report['applied']]);
        }
    }

    private function startSession(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('SPRAVA_WEBU_SESSION');
        $cookie = [
            // Měsíc — stejná hodnota jako Auth::IDLE_TIMEOUT a RememberMe.
            'lifetime' => 2592000,
            'path' => $this->basePath === '' ? '/' : $this->basePath,
            'secure' => $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        session_set_cookie_params($cookie);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        /**
         * Session musí na serveru vydržet stejně dlouho jako cookie. Na
         * sdíleném hostingu ji ve společném `/tmp` maže i GC cizích webů —
         * proto vlastní adresář ve storage a vlastní `gc_maxlifetime`.
         */
        ini_set('session.gc_maxlifetime', '2592000');

        $sessions = $this->storagePath('sessions');

        if ((is_dir($sessions) || @mkdir($sessions, 0700, true)) && is_writable($sessions)) {
            session_save_path($sessions);
        }

        session_start();

        // Posuvná platnost: cookie se obnovuje každým požadavkem.
        if (!headers_sent()) {
            setcookie(session_name(), session_id(), ['expires' => time() + 2592000] + array_diff_key($cookie, ['lifetime' => 0]));
        }
    }

    private function shareViewGlobals(): void
    {
        $view = $this->view();

        $view->share('kernel', $this);
        $view->share('appName', self::APP_NAME);
        $view->share('currentUser', $this->auth()->current());
        $view->share('csrfToken', session_status() === PHP_SESSION_ACTIVE ? $this->csrf()->token() : '');
        $view->share('flashes', $this->takeFlashes());
        $view->share('currentPath', $this->request()->path);

        // Počty v bočním menu a stav monitoru v patičce. Každé zvlášť
        // v try/catch: bez databáze (nebo před první migrací) se prostě
        // neukážou, stránka tím nesmí spadnout.
        foreach ($this->sidebarCounters() as $key => $value) {
            $view->share($key, $value);
        }
    }

    /**
     * Čísla pro boční menu — počet webů, otevřených alertů a stav monitoru.
     *
     * @return array{siteCount: int, openAlertCount: int, monitorStatus: array<string, mixed>|null}
     */
    private function sidebarCounters(): array
    {
        $counters = ['siteCount' => 0, 'openAlertCount' => 0, 'monitorStatus' => null, 'monitorState' => ['state' => 'never', 'at' => null, 'error' => '']];

        try {
            $counters['siteCount'] = (int) $this->db()->scalar('SELECT COUNT(*) FROM sites WHERE removed_at IS NULL');
        } catch (Throwable) {
        }

        try {
            $counters['openAlertCount'] = (int) $this->db()->scalar("SELECT COUNT(*) FROM alerts WHERE status = 'open'");
        } catch (Throwable) {
        }

        try {
            $raw = $this->settings()->get('monitor_last_run');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $counters['monitorStatus'] = is_array($decoded) ? $decoded : null;
            $counters['monitorState'] = DashboardData::monitorState($counters['monitorStatus'], $this->monitorSettings()->int('monitor_interval_min'), time());
        } catch (Throwable) {
            $counters['monitorState'] = ['state' => 'never', 'at' => null, 'error' => ''];
        }

        return $counters;
    }

    private function renderHttpError(HttpException $e, Request $request): Response
    {
        $status = $e->status();

        if ($request->wantsJson()) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $status);
        }

        if ($status === 401) {
            return Response::redirect($this->url('prihlaseni'));
        }

        $this->shareViewGlobals();

        $template = in_array($status, [403, 404], true) ? 'errors/' . $status : 'errors/generic';

        return Response::html(
            $this->view()->render($template, [
                'title' => match ($status) {
                    403 => 'Přístup odepřen',
                    404 => 'Stránka nenalezena',
                    419 => 'Formulář vypršel',
                    429 => 'Příliš mnoho pokusů',
                    default => 'Chyba',
                },
                'message' => $e->getMessage(),
                'status' => $status,
            ]),
            $status,
        );
    }

    private function renderServerError(Throwable $e, Request $request): Response
    {
        /**
         * Značka chyby: uživatel z pětistovky nic nevyčte a nemá co hlásit —
         * značka spojí to, co vidí na obrazovce, s řádkem v logu.
         */
        $marker = 'err-' . date('Y-m-d-Hi');

        $this->logger()->error('Neošetřená chyba [' . $marker . ']', [
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'path' => $request->path,
        ]);

        $this->pushOnError($marker, $e, $request);

        $detail = $this->isProduction()
            ? 'Došlo k neočekávané chybě. Zkuste to prosím znovu.'
            : $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')';

        if ($request->wantsJson()) {
            return Response::json(['status' => 'error', 'message' => $detail, 'marker' => $marker], 500);
        }

        try {
            $this->shareViewGlobals();

            return Response::html(
                $this->view()->render('errors/generic', [
                    'title' => 'Chyba aplikace',
                    'message' => $detail,
                    'status' => 500,
                    'marker' => $marker,
                ]),
                500,
            );
        } catch (Throwable) {
            return Response::html('<h1>Chyba aplikace</h1><p>' . htmlspecialchars($detail) . '</p>', 500);
        }
    }

    /**
     * Upozornění na telefon o neošetřené chybě. Jsme uvnitř `catch` bloku
     * `handle()` — cokoli odsud vyletí, shodí i chybovou stránku, proto
     * `try` kolem. Mimo produkci mlčí.
     */
    private function pushOnError(string $marker, Throwable $e, Request $request): void
    {
        if (!$this->isProduction()) {
            return;
        }

        try {
            $this->pushNotifier()->send(
                'error',
                'Chyba aplikace · ' . $marker,
                $request->path . ' — ' . $e->getMessage(),
                'nastaveni/oznameni',
                onceKey: $marker,
                onceMinutes: 5,
            );
        } catch (Throwable) {
            // Ticho. Chybu už zapsal logger o pár řádků výš.
        }
    }

    /**
     * Je adresa v povoleném seznamu? Přesná IPv4/IPv6 adresa nebo IPv4 CIDR.
     * Prázdný seznam povoluje všechny.
     */
    private function ipAllowed(string $ip): bool
    {
        $allowed = $this->env('allowed_ips', []);

        if (!is_array($allowed) || $allowed === []) {
            return true;
        }

        foreach ($allowed as $entry) {
            $entry = trim((string) $entry);

            if ($entry === $ip) {
                return true;
            }

            if (str_contains($entry, '/') && $this->ipInCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $bits = (int) $bits;

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** Instalace v podadresáři musí fungovat stejně jako v kořeni domény. */
    private function detectBasePath(Request $request): string
    {
        $scriptName = (string) ($request->server['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        return $base === '/' ? '' : $base;
    }

    /**
     * Routy jsou natvrdo v kódu — tabulka rout je zároveň úplný seznam toho,
     * co aplikace umí. Statické cesty stojí PŘED parametrickými, aby
     * „pridat" nespolkl `{id}`.
     */
    private function registerRoutes(Router $router): void
    {
        $router->add('GET', '/', [DashboardController::class, 'index'], name: 'dashboard');

        // Weby — seznam, přidání, detail se záložkami (každá vlastní URL).
        $router->add('GET', '/weby', [SiteController::class, 'index'], name: 'sites');
        $router->add('GET', '/weby/pridat', [SiteController::class, 'createForm'], name: 'sites.add');
        $router->add('POST', '/weby/pridat', [SiteController::class, 'store'], Router::AUTH_ONLY, 'sites.store');
        $router->add('POST', '/weby/zkontrolovat-vse', [SiteController::class, 'checkAll'], Router::AUTH_ONLY, 'sites.check-all');
        $router->add('GET', '/weby/{id}', [SiteController::class, 'overview'], name: 'sites.detail');
        $router->add('GET', '/weby/{id}/prehled', [SiteController::class, 'overview'], name: 'sites.overview');
        $router->add('GET', '/weby/{id}/pluginy', [SiteController::class, 'plugins'], name: 'sites.plugins');
        $router->add('POST', '/weby/{id}/pluginy/aktualizovat', [SiteActionController::class, 'updatePlugins'], Router::AUTH_ONLY, 'sites.plugins.update');
        $router->add('GET', '/weby/{id}/pluginy/smazat', [SiteActionController::class, 'deleteForm'], name: 'sites.plugins.delete.form');
        $router->add('POST', '/weby/{id}/pluginy/smazat', [SiteActionController::class, 'deletePlugin'], Router::AUTH_ONLY, 'sites.plugins.delete');
        $router->add('POST', '/weby/{id}/pluginy/mimo-adresar', [SiteController::class, 'markExternal'], Router::AUTH_ONLY, 'sites.plugins.external');
        $router->add('POST', '/weby/{id}/pluginy/z-adresare', [SiteController::class, 'unmarkExternal'], Router::AUTH_ONLY, 'sites.plugins.directory');
        $router->add('POST', '/weby/{id}/pluginy/deaktivovat', [SiteActionController::class, 'deactivatePlugin'], Router::AUTH_ONLY, 'sites.plugins.deactivate');
        $router->add('POST', '/weby/{id}/pluginy/aktivovat', [SiteActionController::class, 'activatePlugin'], Router::AUTH_ONLY, 'sites.plugins.activate');
        $router->add('POST', '/weby/{id}/pluginy/nesledovat', [SiteController::class, 'unwatchPlugin'], Router::AUTH_ONLY, 'sites.plugins.unwatch');
        $router->add('POST', '/weby/{id}/pluginy/sledovat', [SiteController::class, 'watchPlugin'], Router::AUTH_ONLY, 'sites.plugins.watch');
        $router->add('GET', '/weby/{id}/wordpress', [SiteActionController::class, 'coreForm'], name: 'sites.core.form');
        $router->add('POST', '/weby/{id}/wordpress', [SiteActionController::class, 'updateCore'], Router::AUTH_ONLY, 'sites.core.update');
        $router->add('POST', '/weby/{id}/prihlasit', [SiteActionController::class, 'login'], Router::AUTH_ONLY, 'sites.login');
        $router->add('GET', '/weby/{id}/obsah', [SiteController::class, 'content'], name: 'sites.content');
        $router->add('GET', '/weby/{id}/zabezpeceni', [SiteController::class, 'security'], name: 'sites.security');
        $router->add('POST', '/weby/{id}/zabezpeceni/overit', [SiteController::class, 'securityCheck'], Router::AUTH_ONLY, 'sites.security.check');
        $router->add('GET', '/weby/{id}/historie', [SiteController::class, 'history'], name: 'sites.history');
        $router->add('GET', '/weby/{id}/servis', [ServiceController::class, 'show'], name: 'sites.service');
        $router->add('POST', '/weby/{id}/servis/plan', [ServiceController::class, 'savePlan'], Router::AUTH_ONLY, 'sites.service.plan');
        $router->add('POST', '/weby/{id}/servis/posunout', [ServiceController::class, 'postpone'], Router::AUTH_ONLY, 'sites.service.postpone');
        $router->add('GET', '/weby/{id}/servis/zapsat', [ServiceController::class, 'logForm'], name: 'sites.service.log');
        $router->add('POST', '/weby/{id}/servis/zapsat', [ServiceController::class, 'storeLog'], Router::AUTH_ONLY, 'sites.service.log.store');
        $router->add('GET', '/weby/{id}/servis/{logId}/upravit', [ServiceController::class, 'editForm'], name: 'sites.service.log.edit');
        $router->add('POST', '/weby/{id}/servis/{logId}/upravit', [ServiceController::class, 'updateLog'], Router::AUTH_ONLY, 'sites.service.log.update');
        $router->add('POST', '/weby/{id}/servis/{logId}/smazat', [ServiceController::class, 'removeLog'], Router::AUTH_ONLY, 'sites.service.log.remove');
        $router->add('GET', '/weby/{id}/reporty', [ReportController::class, 'site'], name: 'sites.reports');
        $router->add('POST', '/weby/{id}/reporty/nastaveni', [ReportController::class, 'saveSettings'], Router::AUTH_ONLY, 'sites.reports.settings');
        $router->add('POST', '/weby/{id}/reporty/adresat', [ReportController::class, 'addRecipient'], Router::AUTH_ONLY, 'sites.reports.recipient');
        $router->add('POST', '/weby/{id}/reporty/adresat/smazat', [ReportController::class, 'removeRecipient'], Router::AUTH_ONLY, 'sites.reports.recipient.remove');
        $router->add('GET', '/weby/{id}/reporty/nahled', [ReportController::class, 'previewForSite'], name: 'sites.reports.preview');
        $router->add('POST', '/weby/{id}/reporty/odeslat', [ReportController::class, 'sendNow'], Router::AUTH_ONLY, 'sites.reports.send');

        // Reporty — fronta, náhled a odeslání; sledovací pixel je veřejný.
        $router->add('GET', '/knihovna', [PluginLibraryController::class, 'index'], name: 'library');
        $router->add('POST', '/knihovna', [PluginLibraryController::class, 'upload'], Router::AUTH_ONLY, 'library.upload');
        $router->add('GET', '/knihovna/{slug}/stahnout', [PluginLibraryController::class, 'download'], name: 'library.download');
        $router->add('POST', '/knihovna/{slug}/smazat', [PluginLibraryController::class, 'remove'], Router::AUTH_ONLY, 'library.remove');
        $router->add('GET', '/reporty', [ReportController::class, 'index'], name: 'reports');
        $router->add('POST', '/reporty/odeslat-naplanovane', [ReportController::class, 'sendScheduled'], Router::AUTH_ONLY, 'reports.send-scheduled');
        $router->add('GET', '/reporty/{id}', [ReportController::class, 'show'], name: 'reports.show');
        $router->add('GET', '/reporty/{id}/nahled', [ReportController::class, 'preview'], name: 'reports.preview');
        $router->add('POST', '/reporty/{id}/poznamka', [ReportController::class, 'saveNote'], Router::AUTH_ONLY, 'reports.note');
        $router->add('POST', '/reporty/{id}/sekce', [ReportController::class, 'saveSections'], Router::AUTH_ONLY, 'reports.sections');
        $router->add('POST', '/reporty/{id}/test', [ReportController::class, 'sendTest'], Router::AUTH_ONLY, 'reports.test');
        $router->add('POST', '/reporty/{id}/odeslat', [ReportController::class, 'send'], Router::AUTH_ONLY, 'reports.send');
        $router->add('POST', '/reporty/{id}/smazat', [ReportController::class, 'delete'], Router::AUTH_ONLY, 'reports.delete');
        $router->add('GET', '/r/{file}', [TrackingController::class, 'pixel'], Router::PUBLIC_ACCESS, 'reports.pixel');
        // Trezor přístupů u webu — jen pro účet s dvoufázovým přihlášením
        // (hlídá CredentialController). Heslo jde na stránku jen přes POST
        // na `…/heslo` (oko, kopírování), nikdy ve výpisu.
        $router->add('GET', '/weby/{id}/pristupy', [CredentialController::class, 'index'], name: 'sites.credentials');
        $router->add('GET', '/weby/{id}/pristupy/pridat/{kind}', [CredentialController::class, 'createForm'], name: 'sites.credentials.add');
        $router->add('POST', '/weby/{id}/pristupy/pridat/{kind}', [CredentialController::class, 'store'], Router::AUTH_ONLY, 'sites.credentials.store');
        $router->add('GET', '/weby/{id}/pristupy/{credentialId}/upravit', [CredentialController::class, 'editForm'], name: 'sites.credentials.edit');
        $router->add('POST', '/weby/{id}/pristupy/{credentialId}/upravit', [CredentialController::class, 'update'], Router::AUTH_ONLY, 'sites.credentials.update');
        $router->add('POST', '/weby/{id}/pristupy/{credentialId}/smazat', [CredentialController::class, 'delete'], Router::AUTH_ONLY, 'sites.credentials.delete');
        $router->add('POST', '/weby/{id}/pristupy/{credentialId}/heslo', [CredentialController::class, 'password'], Router::AUTH_ONLY, 'sites.credentials.password');

        $router->add('GET', '/weby/{id}/nastaveni', [SiteController::class, 'settings'], name: 'sites.settings');
        $router->add('POST', '/weby/{id}/nastaveni', [SiteController::class, 'update'], Router::AUTH_ONLY, 'sites.update');
        $router->add('POST', '/weby/{id}/nastaveni/klic', [SiteController::class, 'regenerateKey'], Router::AUTH_ONLY, 'sites.key');
        $router->add('POST', '/weby/{id}/nastaveni/hlidani', [SiteController::class, 'updateWatch'], Router::AUTH_ONLY, 'sites.watch');
        $router->add('GET', '/weby/{id}/ikona', [SiteController::class, 'icon'], name: 'sites.icon');
        $router->add('POST', '/weby/{id}/ikona', [SiteController::class, 'uploadIcon'], Router::AUTH_ONLY, 'sites.icon.upload');
        $router->add('POST', '/weby/{id}/ikona/stahnout', [SiteController::class, 'refreshIcon'], Router::AUTH_ONLY, 'sites.icon.refresh');
        $router->add('POST', '/weby/{id}/ikona/smazat', [SiteController::class, 'removeIcon'], Router::AUTH_ONLY, 'sites.icon.remove');
        $router->add('GET', '/weby/{id}/odebrat', [SiteController::class, 'removeForm'], name: 'sites.remove.form');
        $router->add('POST', '/weby/{id}/odebrat', [SiteController::class, 'remove'], Router::AUTH_ONLY, 'sites.remove');
        $router->add('POST', '/weby/{id}/zkontrolovat', [SiteController::class, 'checkNow'], Router::AUTH_ONLY, 'sites.check');

        // Alerty.
        $router->add('GET', '/alerty', [AlertController::class, 'index'], name: 'alerts');
        $router->add('POST', '/alerty/hromadne', [AlertController::class, 'bulk'], Router::AUTH_ONLY, 'alerts.bulk');
        $router->add('POST', '/alerty/vyresit-nove', [AlertController::class, 'resolveAllOpen'], Router::AUTH_ONLY, 'alerts.resolve-all');
        $router->add('POST', '/alerty/{id}/vyresit', [AlertController::class, 'resolve'], Router::AUTH_ONLY, 'alerts.resolve');
        $router->add('POST', '/alerty/{id}/ignorovat', [AlertController::class, 'ignore'], Router::AUTH_ONLY, 'alerts.ignore');
        $router->add('POST', '/alerty/{id}/otevrit', [AlertController::class, 'reopen'], Router::AUTH_ONLY, 'alerts.reopen');

        // Hostingový cron — chráněný tokenem v adrese, ne session.
        $router->add('GET', '/system/monitor-cron', [MonitorCronController::class, 'run'], Router::PUBLIC_ACCESS, 'system.monitor-cron');

        // Klienti.
        $router->add('GET', '/klienti', [ClientController::class, 'index'], name: 'clients');
        $router->add('GET', '/klienti/pridat', [ClientController::class, 'createForm'], name: 'clients.add');
        $router->add('POST', '/klienti/pridat', [ClientController::class, 'store'], Router::AUTH_ONLY, 'clients.store');
        $router->add('GET', '/klienti/{id}', [ClientController::class, 'detail'], name: 'clients.detail');
        $router->add('GET', '/klienti/{id}/upravit', [ClientController::class, 'editForm'], name: 'clients.edit');
        $router->add('POST', '/klienti/{id}/upravit', [ClientController::class, 'update'], Router::AUTH_ONLY, 'clients.update');
        $router->add('POST', '/klienti/{id}/archivovat', [ClientController::class, 'archive'], Router::AUTH_ONLY, 'clients.archive');
        $router->add('POST', '/klienti/{id}/weby', [ClientController::class, 'assignSites'], Router::AUTH_ONLY, 'clients.sites');
        $router->add('POST', '/klienti/{id}/kontakt', [ClientController::class, 'addContact'], Router::AUTH_ONLY, 'clients.contact.add');
        $router->add('POST', '/klienti/{id}/kontakt/{contactId}/smazat', [ClientController::class, 'removeContact'], Router::AUTH_ONLY, 'clients.contact.remove');

        // Distribuce pluginu na weby klientů — volá WordPress, žádný
        // prohlížeč ani session. Bez výjimky v .htaccess je Basic auth odřízne.
        // Knihovna pluginů pro weby — před obecnou adresou ZIPu Monitoru níže,
        // jinak by `knihovna.json` spolkla `{file}`.
        $router->add('GET', '/plugin/mediagrafik-monitor/knihovna.json', [PluginLibraryController::class, 'manifest'], Router::PUBLIC_ACCESS, 'library.manifest');
        $router->add('GET', '/plugin/mediagrafik-monitor/knihovna/{file}', [PluginLibraryController::class, 'package'], Router::PUBLIC_ACCESS, 'library.package');
        $router->add('GET', '/plugin/mediagrafik-monitor/plugin-info.json', [PluginDistributionController::class, 'info'], Router::PUBLIC_ACCESS, 'plugin.info');
        $router->add('GET', '/plugin/mediagrafik-monitor/{file}', [PluginDistributionController::class, 'download'], Router::PUBLIC_ACCESS, 'plugin.download');

        // Přihlášení a účet.
        $router->add('GET', '/prihlaseni', [AuthController::class, 'show'], Router::PUBLIC_ACCESS, 'auth.show');
        $router->add('POST', '/prihlaseni', [AuthController::class, 'login'], Router::PUBLIC_ACCESS, 'auth.login');
        // Druhý krok přihlášení — kód z aplikace v telefonu (TwoFactor).
        $router->add('GET', '/prihlaseni/overeni', [AuthController::class, 'showSecondFactor'], Router::PUBLIC_ACCESS, 'auth.2fa.show');
        $router->add('POST', '/prihlaseni/overeni', [AuthController::class, 'verifySecondFactor'], Router::PUBLIC_ACCESS, 'auth.2fa');
        $router->add('POST', '/odhlaseni', [AuthController::class, 'logout'], Router::AUTH_ONLY, 'auth.logout');
        $router->add('POST', '/motiv', [AuthController::class, 'theme'], Router::AUTH_ONLY, 'auth.theme');

        // Zapomenuté heslo — e-mailem.
        $router->add('GET', '/zapomenute-heslo', [ForgottenPasswordController::class, 'show'], Router::PUBLIC_ACCESS, 'password.forgot.show');
        $router->add('POST', '/zapomenute-heslo', [ForgottenPasswordController::class, 'send'], Router::PUBLIC_ACCESS, 'password.forgot.send');
        $router->add('GET', '/obnova-hesla/{token}', [ForgottenPasswordController::class, 'showReset'], Router::PUBLIC_ACCESS, 'password.reset.show');
        $router->add('POST', '/obnova-hesla/{token}', [ForgottenPasswordController::class, 'reset'], Router::PUBLIC_ACCESS, 'password.reset.confirm');

        // Nastavení aplikace — každá záložka vlastní stránka (funguje bez JS,
        // dá se odkázat).
        $router->add('GET', '/nastaveni', [SettingsController::class, 'index'], name: 'settings');

        $router->add('GET', '/nastaveni/monitoring', [SettingsController::class, 'monitoring'], name: 'settings.monitoring.show');
        $router->add('POST', '/nastaveni/monitoring', [SettingsController::class, 'saveMonitoring'], Router::AUTH_ONLY, 'settings.monitoring');
        $router->add('POST', '/nastaveni/monitoring/token', [SettingsController::class, 'regenerateCronToken'], Router::AUTH_ONLY, 'settings.monitoring.token');
        $router->add('POST', '/nastaveni/monitoring/spustit', [SettingsController::class, 'runMonitorNow'], Router::AUTH_ONLY, 'settings.monitoring.run');
        $router->add('POST', '/nastaveni/monitoring/verze', [SettingsController::class, 'refreshSupportTables'], Router::AUTH_ONLY, 'settings.monitoring.versions');
        $router->add('GET', '/nastaveni/servis', [SettingsController::class, 'service'], name: 'settings.service.show');
        $router->add('POST', '/nastaveni/servis', [SettingsController::class, 'saveService'], Router::AUTH_ONLY, 'settings.service');
        $router->add('GET', '/nastaveni/reporty', [SettingsController::class, 'reportTemplate'], name: 'settings.reports.show');
        $router->add('POST', '/nastaveni/reporty', [SettingsController::class, 'saveReportTemplate'], Router::AUTH_ONLY, 'settings.reports');
        $router->add('GET', '/nastaveni/alerty', [SettingsController::class, 'alerts'], name: 'settings.alerts.show');
        $router->add('POST', '/nastaveni/alerty', [SettingsController::class, 'saveAlerts'], Router::AUTH_ONLY, 'settings.alerts');

        $router->add('GET', '/nastaveni/email', [SettingsController::class, 'email'], name: 'settings.mail.show');
        $router->add('POST', '/nastaveni/email', [SettingsController::class, 'saveMail'], Router::AUTH_ONLY, 'settings.mail');
        $router->add('POST', '/nastaveni/email/test', [SettingsController::class, 'testMail'], Router::AUTH_ONLY, 'settings.mail.test');

        // Oznámení na telefon (web push). Přihlášení a odhlášení odběru volá
        // fetch z push.js s tokenem v hlavičce, takže je CSRF hlídá stejně.
        $router->add('GET', '/nastaveni/oznameni', [SettingsController::class, 'oznameni'], name: 'settings.push.show');
        $router->add('POST', '/nastaveni/oznameni', [SettingsController::class, 'savePush'], Router::AUTH_ONLY, 'settings.push');
        $router->add('POST', '/nastaveni/oznameni/pripravit', [SettingsController::class, 'preparePush'], Router::AUTH_ONLY, 'settings.push.prepare');
        $router->add('POST', '/nastaveni/oznameni/prihlasit', [SettingsController::class, 'subscribePush'], Router::AUTH_ONLY, 'settings.push.subscribe');
        $router->add('POST', '/nastaveni/oznameni/odhlasit', [SettingsController::class, 'unsubscribePush'], Router::AUTH_ONLY, 'settings.push.unsubscribe');
        $router->add('POST', '/nastaveni/oznameni/zkouska', [SettingsController::class, 'testPush'], Router::AUTH_ONLY, 'settings.push.test');
        $router->add('POST', '/nastaveni/oznameni/{id}/smazat', [SettingsController::class, 'removePushDevice'], Router::AUTH_ONLY, 'settings.push.remove');

        // Uživatelé a vlastní účet.
        $router->add('GET', '/nastaveni/uzivatele', [SettingsController::class, 'uzivatele'], name: 'settings.users.show');
        $router->add('POST', '/nastaveni/uzivatele', [SettingsController::class, 'inviteUser'], Router::AUTH_ONLY, 'settings.users.invite');
        $router->add('POST', '/nastaveni/uzivatele/{id}/pozastavit', [SettingsController::class, 'suspendUser'], Router::AUTH_ONLY, 'settings.users.suspend');
        $router->add('POST', '/nastaveni/uzivatele/{id}/obnovit', [SettingsController::class, 'resumeUser'], Router::AUTH_ONLY, 'settings.users.resume');
        $router->add('GET', '/nastaveni/uzivatele/{id}/upravit', [SettingsController::class, 'editUser'], name: 'settings.users.edit');
        $router->add('POST', '/nastaveni/uzivatele/{id}/upravit', [SettingsController::class, 'updateUser'], Router::AUTH_ONLY, 'settings.users.update');
        $router->add('POST', '/nastaveni/uzivatele/{id}/pozvanka', [SettingsController::class, 'resendInvite'], Router::AUTH_ONLY, 'settings.users.reinvite');
        $router->add('POST', '/nastaveni/uzivatele/{id}/dvoufazove/vypnout', [TwoFactorController::class, 'disableFor'], Router::AUTH_ONLY, 'settings.users.2fa.disable');

        // Dvoufázové přihlášení vlastního účtu.
        $router->add('GET', '/nastaveni/dvoufazove', [TwoFactorController::class, 'setup'], name: 'settings.2fa.show');
        $router->add('POST', '/nastaveni/dvoufazove', [TwoFactorController::class, 'enable'], Router::AUTH_ONLY, 'settings.2fa.enable');
        $router->add('GET', '/nastaveni/dvoufazove/kody', [TwoFactorController::class, 'codes'], name: 'settings.2fa.codes');
        $router->add('POST', '/nastaveni/dvoufazove/kody', [TwoFactorController::class, 'regenerateCodes'], Router::AUTH_ONLY, 'settings.2fa.regenerate');
        $router->add('POST', '/nastaveni/dvoufazove/vypnout', [TwoFactorController::class, 'disable'], Router::AUTH_ONLY, 'settings.2fa.disable');

        $router->add('POST', '/nastaveni/ucet', [SettingsController::class, 'updateAccount'], Router::AUTH_ONLY, 'settings.account');
        $router->add('POST', '/nastaveni/ucet/fotka/smazat', [SettingsController::class, 'removeAvatar'], Router::AUTH_ONLY, 'settings.avatar.remove');
        $router->add('GET', '/avatar/{id}', [SettingsController::class, 'avatar'], name: 'avatar.show');
    }
}
