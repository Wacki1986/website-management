<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit\AuditLog;
use App\Core\Auth\Avatars;
use App\Core\Auth\PasswordPolicy;
use App\Core\Auth\UserRepository;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Kernel;
use App\Core\Monitor\DbSupport;
use App\Core\Monitor\MonitorRun;
use App\Core\Monitor\MonitorSettings;
use App\Core\Monitor\PhpSupport;
use App\Core\Monitor\SupportTables;
use App\Core\Notifications\EmailMessage;
use App\Core\Notifications\MailSettings;
use App\Core\Notifications\PushSubscriptions;
use App\Core\Service\ServiceChecklists;
use App\Core\Service\ServiceSchedule;
use App\Core\Sites\SiteActions;

/**
 * Nastavení aplikace (návrh `nastaveni-*.html`) — každá záložka své URL:
 * Monitoring · Alerty a prahy · Servis · Odchozí pošta · Oznámení · Uživatelé.
 * Vlastní stránka na záložku, ne JS přepínání panelů — funguje to i bez
 * JavaScriptu a jde to odkázat.
 *
 */
final class SettingsController extends Controller
{
    /** Kořen `/nastaveni` vede na první existující záložku. */
    public function index(): Response
    {
        return $this->redirect('nastaveni/monitoring');
    }

    // -----------------------------------------------------------------
    // Monitoring a prahy alertů
    // -----------------------------------------------------------------

    public function monitoring(): Response
    {
        $settings = $this->kernel->monitorSettings();
        $values = [];

        foreach (array_keys(MonitorSettings::DEFAULTS) as $key) {
            $values[$key] = $settings->int($key);
        }

        $token = $this->kernel->settings()->secret(MonitorCronController::TOKEN_KEY);

        return $this->view('settings/monitoring', [
            'title' => 'Nastavení',
            'activeTab' => 'monitoring',
            'values' => $values,
            'alertEmails' => $this->kernel->settings()->get('alert_emails'),
            'wpLoginUser' => $this->kernel->settings()->get(SiteActions::LOGIN_USER_SETTING),
            'cronToken' => $token,
            'cronLine' => $token !== null ? MonitorCronController::cronLine($this->kernel->appUrl(), $token) : '',
            'lastRun' => MonitorRun::lastRun($this->kernel->settings()),
            'siteCount' => $this->kernel->sites()->countActive(),
            'checksToday' => $this->kernel->uptime()->countToday(),
            'appVersion' => Kernel::VERSION,
            'support' => $this->supportSummary(),
        ]);
    }

    public function saveMonitoring(): Response
    {
        $request = $this->request();
        $this->kernel->monitorSettings()->save($request->body);

        // Adresy: neplatné se tiše vyhodí; kdyby nezůstala žádná, řekne se to.
        $raw = $request->string('alert_emails');
        $this->kernel->settings()->set('alert_emails', $raw);
        $this->kernel->settings()->set(SiteActions::LOGIN_USER_SETTING, mb_substr($request->string('wp_login_user'), 0, 100));
        $valid = $this->kernel->monitorSettings()->alertEmails();
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_SETTINGS, true, 'Uloženo nastavení monitoringu');

        if ($raw !== '' && $valid === []) {
            return $this->redirectWithFlash('nastaveni/monitoring', 'Nastavení uloženo, ale žádná z adres pro alerty nemá platný tvar.', 'warning');
        }

        return $this->redirectWithFlash('nastaveni/monitoring', 'Nastavení monitoringu je uložené.');
    }

    /** Nový token cronu — starý přestane platit, řádek v cPanelu je potřeba vyměnit. */
    public function regenerateCronToken(): Response
    {
        $token = bin2hex(random_bytes(24));
        $this->kernel->settings()->setSecret(MonitorCronController::TOKEN_KEY, $token);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_SETTINGS, true, 'Vygenerován token cronu');

        return $this->redirectWithFlash('nastaveni/monitoring', 'Token cronu je vygenerovaný. Zkopírujte řádek do cPanelu.');
    }

    /** Ruční průchod monitoru z prohlížeče — na ověření, že všechno běží. */
    public function runMonitorNow(): Response
    {
        $summary = $this->kernel->monitor()->run();

        if (($summary['status'] ?? '') === 'busy') {
            return $this->redirectWithFlash('nastaveni/monitoring', 'Monitor právě běží (cron) — počkejte na jeho dokončení.', 'warning');
        }

        if (($summary['status'] ?? '') === 'error') {
            return $this->redirectWithFlash('nastaveni/monitoring', 'Průchod skončil chybou: ' . (string) $summary['error'], 'error');
        }

        return $this->redirectWithFlash('nastaveni/monitoring', sprintf(
            'Průchod hotový za %s s: %d kontrol dostupnosti, %d nedostupných, %d SSL, %d načtení dat z pluginu.',
            (string) $summary['seconds'], (int) $summary['checked'], (int) $summary['down'], (int) $summary['ssl'], (int) $summary['pulled'],
        ));
    }

    /** Ověřit konce podpory PHP a databází teď (jinak to cron udělá jednou za měsíc). */
    public function refreshSupportTables(): Response
    {
        $result = $this->kernel->supportTables()->refresh();
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_SETTINGS, $result['ok'], 'Ověřeny konce podpory PHP a databází'
            . ($result['changes'] !== [] ? ' (' . get_count(count($result['changes']), 'změna', 'změny', 'změn') . ')' : ''));

        if (!$result['ok'] && $result['changes'] === []) {
            return $this->redirectWithFlash('nastaveni/monitoring', (string) $result['error'] . ' Platí dál poslední známá data.', 'error');
        }

        return $this->redirectWithFlash('nastaveni/monitoring', ($result['changes'] === []
            ? 'Konce podpory jsou ověřené — beze změn.'
            : 'Konce podpory jsou ověřené: ' . get_count(count($result['changes']), 'změna', 'změny', 'změn') . ', seznam je v kartě „Konce podpory“.')
            . ($result['error'] !== null ? ' ' . $result['error'] : ''), $result['ok'] ? 'success' : 'warning');
    }

    /**
     * Karta „Konce podpory PHP a databází“: odkud data jsou, kdy se
     * ověřovala a verze, které se na webech právě používají.
     *
     * @return array{source: string, checkedAt: string, due: bool, error: ?string, changes: array<int, string>, rows: array<int, array{label: string, end: string, tone: string, state: string, text: string}>}
     */
    private function supportSummary(): array
    {
        $tables = $this->kernel->supportTables();
        $stored = $tables->stored();
        $rows = [];

        // Jen verze, které weby opravdu mají — celá tabulka by byla dlouhá.
        foreach ($this->kernel->db()->select("SELECT DISTINCT php_version, db_type, db_version FROM site_snapshots") as $row) {
            $php = PhpSupport::minor((string) $row['php_version']);

            if ($php !== '') {
                $rows['PHP ' . $php] = ['label' => 'PHP ' . $php, 'end' => (string) (PhpSupport::endOfLife($php) ?? ''), 'tone' => PhpSupport::tone($php)];
            }

            $db = DbSupport::label((string) $row['db_type'], (string) $row['db_version']);

            if ((string) $row['db_version'] !== '') {
                $rows[$db] = ['label' => $db, 'end' => (string) (DbSupport::endOfLife((string) $row['db_type'], (string) $row['db_version']) ?? ''), 'tone' => DbSupport::tone((string) $row['db_type'], (string) $row['db_version'])];
            }
        }

        ksort($rows, SORT_NATURAL);

        foreach ($rows as $key => $row) {
            $rows[$key]['end'] = match ($row['end']) {
                '' => 'neznámý',
                '0000-00-00' => 'už skončila',
                default => SupportTables::endLabel($row['end']),
            };
            $rows[$key]['state'] = match ($row['tone']) {
                'error' => 'bez podpory',
                'warning' => 'končí do roka',
                default => 'podporovaná',
            };
            $rows[$key]['tone'] = $row['tone'] !== '' ? $row['tone'] : 'ok';
            $rows[$key]['text'] = $rows[$key]['state'] . ' · konec ' . $rows[$key]['end'];
        }

        return [
            'source' => $stored !== null ? 'endoflife.date' : 'vestavěná tabulka (zatím neověřeno)',
            'checkedAt' => $stored !== null ? get_when($stored['checked_at']) : '',
            'due' => $tables->isDue(),
            'error' => $stored['error'] ?? null,
            'changes' => $stored['changes'] ?? [],
            'rows' => array_values($rows),
        ];
    }

    /** Pravidla alertů — pořadí a texty podle návrhu. */
    private const RULES = [
        ['key' => 'rule_updates', 'label' => 'Čekající aktualizace', 'text' => 'Alert, když web překročí tento počet nenainstalovaných aktualizací.', 'unit' => '', 'min' => 1, 'max' => 100, 'note' => ''],
        ['key' => 'rule_ssl', 'label' => 'Platnost SSL', 'text' => 'Upozornit, kolik dní před vypršením certifikátu.', 'unit' => 'dní', 'min' => 1, 'max' => 90, 'note' => 'Prošlý certifikát se hlásí vždy.'],
        ['key' => 'rule_backup', 'label' => 'Stáří zálohy', 'text' => 'Alert, když poslední úspěšná záloha je starší.', 'unit' => 'h', 'min' => 6, 'max' => 720, 'note' => 'Jen u webů, kde plugin datum zálohy zjistí (UpdraftPlus, BackWPup).'],
        ['key' => 'rule_service', 'label' => 'Nezapsaný servis', 'text' => 'Alert, když se naplánovaný servis nezapíše do historie.', 'unit' => 'h', 'min' => 1, 'max' => 720, 'note' => ''],
        ['key' => 'rule_domain', 'label' => 'Expirace domény', 'text' => 'Upozornit, kolik dní před koncem registrace domény.', 'unit' => 'dní', 'min' => 7, 'max' => 365, 'note' => 'Zjišťuje se přes RDAP jednou týdně.'],
    ];

    public function alerts(): Response
    {
        $settings = $this->kernel->monitorSettings();
        $rules = [];

        foreach (self::RULES as $rule) {
            $valueKey = $rule['key'] === 'rule_updates' ? 'rule_updates_max' : ($rule['key'] === 'rule_ssl' || $rule['key'] === 'rule_domain' ? $rule['key'] . '_days' : $rule['key'] . '_hours');
            $rules[] = $rule + ['value' => $settings->int($valueKey), 'on' => $settings->bool($rule['key'] . '_on')];
        }

        return $this->view('settings/alerts', [
            'title' => 'Nastavení',
            'activeTab' => 'alerty',
            'rules' => $rules,
            'lastRun' => MonitorRun::lastRun($this->kernel->settings()),
            'appVersion' => Kernel::VERSION,
            'checksToday' => $this->kernel->uptime()->countToday(),
        ]);
    }

    public function saveAlerts(): Response
    {
        $body = $this->request()->body;
        $values = [];

        foreach (self::RULES as $rule) {
            $valueKey = $rule['key'] === 'rule_updates' ? 'rule_updates_max' : ($rule['key'] === 'rule_ssl' || $rule['key'] === 'rule_domain' ? $rule['key'] . '_days' : $rule['key'] . '_hours');
            $values[$valueKey] = $body[$rule['key'] . '_max'] ?? MonitorSettings::DEFAULTS[$valueKey];
            // Neodeslaný přepínač = vypnuto.
            $values[$rule['key'] . '_on'] = isset($body[$rule['key'] . '_on']) ? '1' : '0';
        }

        $this->kernel->monitorSettings()->save($values);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_SETTINGS, true, 'Uloženy prahy alertů');

        return $this->redirectWithFlash('nastaveni/alerty', 'Prahy alertů jsou uložené.');
    }

    // -----------------------------------------------------------------
    // Servis — seznamy úkolů k druhům servisu
    // -----------------------------------------------------------------

    public function service(): Response
    {
        $kinds = [];

        foreach ($this->kernel->serviceChecklists()->all() as $code => $items) {
            $kinds[] = ServiceSchedule::KINDS[$code] + ['code' => $code, 'text' => implode("\n", $items), 'count' => count($items)];
        }

        return $this->view('settings/service', [
            'title' => 'Nastavení',
            'activeTab' => 'servis',
            'kinds' => $kinds,
            'maxItems' => ServiceChecklists::MAX_ITEMS,
        ]);
    }

    public function saveService(): Response
    {
        $checklists = $this->kernel->serviceChecklists();
        $counts = [];

        foreach (array_keys(ServiceSchedule::KINDS) as $code) {
            $counts[] = ServiceSchedule::KINDS[$code]['label'] . ' ' . count($checklists->save($code, $this->request()->string('checklist_' . $code)));
        }

        $this->kernel->audit()->record(null, '', AuditLog::ACTION_SETTINGS, true, 'Uloženy úkoly servisu (' . implode(', ', $counts) . ')');

        return $this->redirectWithFlash('nastaveni/servis', 'Seznamy úkolů jsou uložené. Platí pro nové zápisy servisu, zapsané zůstávají, jak byly.');
    }

    // -----------------------------------------------------------------
    // Odchozí pošta
    // -----------------------------------------------------------------

    public function email(): Response
    {
        return $this->view('settings/email', [
            'title' => 'Nastavení',
            'activeTab' => 'email',
            'mail' => $this->kernel->mailSettings()->forForm(),
            'mailProblems' => $this->kernel->mailSettings()->problems(),
            'mailTransports' => MailSettings::TRANSPORTS,
            'mailSecurities' => MailSettings::SECURITIES,
            'mailLogoUrl' => $this->kernel->settings()->get('mail_logo_url'),
            'lastTest' => $this->kernel->settings()->get('mail_last_test'),
        ]);
    }

    public function saveMail(): Response
    {
        $request = $this->request();

        $this->kernel->mailSettings()->save([
            'transport' => $request->string('transport'),
            'from_address' => $request->string('from_address'),
            'from_name' => $request->string('from_name'),
            'host' => $request->string('host'),
            'port' => $request->int('port', 587),
            'security' => $request->string('security'),
            'username' => $request->string('username'),
            'password' => $request->string('password'),
        ]);

        // Logo do hlavičky e-mailů — neplatná adresa se odmítne hned, tichý
        // překlep by znamenal rozbitý obrázek v každém e-mailu.
        $logoUrl = trim($request->string('mail_logo_url'));

        if ($logoUrl !== '' && (!filter_var($logoUrl, FILTER_VALIDATE_URL) || !str_starts_with($logoUrl, 'http'))) {
            return $this->redirectWithFlash('nastaveni/email', 'Adresa loga musí být absolutní URL (https://…), nebo prázdná.', 'error');
        }

        $this->kernel->settings()->set('mail_logo_url', $logoUrl);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_SETTINGS, true, 'Uloženo nastavení odchozí pošty');

        return $this->redirectWithFlash('nastaveni/email', 'Nastavení odchozí pošty je uložené.');
    }

    /**
     * Zkušební e-mail — bez něj se na špatné SMTP nastavení přijde až ve
     * chvíli, kdy má odejít klientský report, tedy v nejhorší možný okamžik.
     */
    public function testMail(): Response
    {
        $to = trim($this->request()->string('test_to'));

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirectWithFlash('nastaveni/email', 'Zadejte platnou adresu pro zkoušku.', 'error');
        }

        $mailer = $this->kernel->mailer();
        $started = microtime(true);
        $sent = $mailer->sendMessage(
            $to,
            'Zkušební e-mail — ' . Kernel::APP_NAME,
            EmailMessage::make('Zkušební e-mail')
                ->paragraph('Tohle je zkušební zpráva. Když vám dorazila, je odesílání pošty nastavené správně — '
                    . 'takhle budou vypadat alerty ze správy webů.')
                ->button('Otevřít správu webů', $this->kernel->appUrl()),
        );

        if (!$sent) {
            return $this->redirectWithFlash(
                'nastaveni/email',
                'Zkušební e-mail se nepodařilo odeslat: ' . ($mailer->lastError() ?? 'neznámá chyba.'),
                'error',
            );
        }

        // „Poslední test 8. 9. v 14:02 — doručeno za 2 s" v patičce karty.
        $this->kernel->settings()->set('mail_last_test', sprintf(
            'Poslední test %s — odesláno za %d s',
            get_when(date('Y-m-d H:i:s')),
            (int) round(microtime(true) - $started),
        ));

        return $this->redirectWithFlash('nastaveni/email', sprintf('Zkušební e-mail odešel na %s. Zkontrolujte i spam.', $to));
    }

    // -----------------------------------------------------------------
    // Oznámení na telefon (web push)
    // -----------------------------------------------------------------

    /** Přihlášený účet — odběry upozornění i profilová fotka patří k němu. */
    private function currentUserId(): int
    {
        return (int) ($this->kernel->auth()->current()['id'] ?? 0);
    }

    /**
     * Skupiny událostí, které umí pípnout na telefon.
     *
     * Jeden seznam pro zaškrtávátka i pro `PushNotifier` — klíč je zároveň
     * konec názvu nastavení (`push_on_sites`) a hodnota `event` v kódu,
     * který upozornění posílá.
     */
    private const PUSH_EVENTS = [
        ['key' => 'sites', 'label' => 'Web nedostupný',
            'hint' => 'Tři kontroly po sobě selhaly, nebo se web zase ozval. Odkaz vede rovnou na web.'],
        ['key' => 'ssl', 'label' => 'Certifikát nebo doména',
            'hint' => 'SSL certifikát brzy vyprší nebo už vypršel; expirace domény (pokud je pravidlo zapnuté).'],
        ['key' => 'updates', 'label' => 'Ranní souhrn aktualizací',
            'hint' => 'Jednou denně: kolik webů má čekající aktualizace. Nejvýš třikrát za hodinu.'],
        ['key' => 'reports', 'label' => 'Reporty klientům',
            'hint' => 'Report čeká na schválení, nebo se ho nepodařilo doručit.'],
        ['key' => 'error', 'label' => 'Chyba aplikace',
            'hint' => 'Neošetřená chyba (pětistovka) se značkou, kterou pak najdete v logu. Nejvýš třikrát za hodinu.'],
        ['key' => 'ops', 'label' => 'Selhání provozní úlohy',
            'hint' => 'Migrace databáze, cron monitoru nebo odchozí pošta.'],
    ];

    public function oznameni(): Response
    {
        $settings = $this->kernel->settings();
        $keys = $this->kernel->vapidKeys();

        $events = [];
        foreach (self::PUSH_EVENTS as $event) {
            $events[] = $event + ['on' => $settings->get('push_on_' . $event['key'], '1') !== '0'];
        }

        return $this->view('settings/oznameni', [
            'title' => 'Nastavení',
            'activeTab' => 'oznameni',
            'pushReady' => $keys->ready(),
            // Použitelnost se ptá zvlášť: veřejný klíč může být v nastavení
            // a soukromý přitom nečitelný (vyměněný `app_key`).
            'pushUsable' => $keys->usable(),
            'pushPublicKey' => $keys->publicKey(),
            'pushKeyHint' => $settings->secretHint('vapid_private_key'),
            'pushDeviceCount' => $this->kernel->pushSubscriptions()->count(),
            'pushDevices' => $this->pushDevices(),
            'pushEvents' => $events,
            'ipRestricted' => (array) $this->kernel->env('allowed_ips', []) !== [],
        ]);
    }

    /**
     * Seznam zařízení pro šablonu — hotové řádky, žádné počítání v šabloně.
     *
     * @return array<int, array{id: int, hash: string, name: string, note: string}>
     */
    private function pushDevices(): array
    {
        $rows = $this->kernel->pushSubscriptions()->forUser($this->currentUserId());
        $devices = [];

        foreach ($rows as $row) {
            $failed = (int) $row['failed_count'];

            $devices[] = [
                'id' => (int) $row['id'],
                // Server zná jen otisk endpointu; push.js si ho v prohlížeči
                // spočítá znovu a podle něj označí „toto zařízení".
                'hash' => (string) $row['endpoint_hash'],
                'name' => PushSubscriptions::deviceLabel((string) $row['user_agent']),
                'note' => 'přidáno ' . get_czech_date((string) $row['created_at'])
                    . ($failed > 0 ? ' · ' . $failed . '× nedoručeno' : ''),
            ];
        }

        return $devices;
    }

    /** Uložení zaškrtávátek „co má pípat". */
    public function savePush(): Response
    {
        $request = $this->request();
        $settings = $this->kernel->settings();

        foreach (self::PUSH_EVENTS as $event) {
            // Neodeslané zaškrtávátko = vypnuto; ukládá se obojí, ať se
            // výchozí „zapnuto" nedostane do sporu s vědomým vypnutím.
            $settings->set('push_on_' . $event['key'], $request->bool('push_on_' . $event['key']) ? '1' : '0');
        }

        return $this->redirectWithFlash('nastaveni/oznameni', 'Uloženo.');
    }

    /**
     * Vyrobení podpisového páru (VAPID). Dělá se jednou za život instalace —
     * výměna páru by odhlásila všechny telefony, proto tu není „znovu".
     */
    public function preparePush(): Response
    {
        try {
            $created = $this->kernel->vapidKeys()->ensure();
        } catch (\Throwable $e) {
            $this->kernel->logger()->error('Klíče pro upozornění se nepodařilo vyrobit', ['error' => $e->getMessage()]);

            return $this->redirectWithFlash('nastaveni/oznameni',
                'Klíče se nepodařilo vyrobit — hosting nejspíš nemá rozšíření openssl. ' . $e->getMessage(), 'error');
        }

        return $this->redirectWithFlash('nastaveni/oznameni',
            $created ? 'Upozornění jsou připravená. Teď je zapněte na tomhle zařízení.' : 'Upozornění už připravená byla.');
    }

    /**
     * Přihlášení tohoto prohlížeče k upozorněním (web push). Volá
     * `assets/js/modules/push.js` po `pushManager.subscribe()`.
     */
    public function subscribePush(): Response
    {
        $request = $this->request();
        $endpoint = trim((string) $request->input('endpoint', ''));
        $keys = $request->input('keys', []);
        $p256dh = is_array($keys) ? trim((string) ($keys['p256dh'] ?? '')) : '';
        $auth = is_array($keys) ? trim((string) ($keys['auth'] ?? '')) : '';

        if (
            !str_starts_with($endpoint, 'https://')
            || strlen($endpoint) > 2000
            || filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || !self::isBase64Url($p256dh, 65)
            || !self::isBase64Url($auth, 16)
        ) {
            return $this->json(['status' => 'error', 'message' => 'Prohlížeč poslal neúplný odběr.'], 422);
        }

        if (!$this->kernel->vapidKeys()->ready()) {
            return $this->json(['status' => 'error', 'message' => 'Aplikace nemá vyrobené klíče pro upozornění.'], 503);
        }

        $id = $this->kernel->pushSubscriptions()->save(
            $this->currentUserId(),
            $endpoint,
            $p256dh,
            $auth,
            $request->header('user-agent'),
        );

        return $this->json(['status' => 'success', 'id' => $id]);
    }

    /** Odhlášení tohoto prohlížeče — JS zná jen endpoint, ne ID řádku. */
    public function unsubscribePush(): Response
    {
        $endpoint = trim((string) $this->request()->input('endpoint', ''));

        if ($endpoint !== '') {
            $this->kernel->pushSubscriptions()->removeByEndpoint($this->currentUserId(), $endpoint);
        }

        return $this->json(['status' => 'success']);
    }

    /** Odebrání zařízení ze seznamu (obyčejný formulář, funguje i bez JS). */
    public function removePushDevice(string $id): Response
    {
        $removed = $this->kernel->pushSubscriptions()->remove($this->currentUserId(), (int) $id);

        return $this->redirectWithFlash(
            'nastaveni/oznameni',
            $removed ? 'Zařízení už upozornění dostávat nebude.' : 'Zařízení v seznamu není.',
            $removed ? 'success' : 'error',
        );
    }

    /** Zkušební upozornění — obdoba zkušebního e-mailu, jde mimo skupiny i tlumení. */
    public function testPush(): Response
    {
        $result = $this->kernel->pushNotifier()->test();

        if ($result['sent'] > 0) {
            return $this->redirectWithFlash('nastaveni/oznameni', sprintf(
                'Zkušební upozornění odešlo na %d zařízení. Když se na telefonu neukáže, '
                . 'má vypnutá oznámení sám telefon — zkontrolujte je v systémovém nastavení aplikace.',
                $result['sent'],
            ));
        }

        $reason = match (true) {
            $result['devices'] === 0 => 'Není přihlášené žádné zařízení. Zapněte upozornění tlačítkem v kartě '
                . '„Toto zařízení" — a na telefonu to udělejte v aplikaci spuštěné z plochy, ne v prohlížeči.',
            $result['error'] !== '' => 'Push služba zprávu nepřijala: ' . $result['error'],
            default => 'Zprávu se nepodařilo doručit ani na jedno z přihlášených zařízení.',
        };

        return $this->redirectWithFlash('nastaveni/oznameni', 'Zkušební upozornění neodešlo. ' . $reason, 'error');
    }

    /** base64url bez paddingu, po dekódování přesně `$bytes` bajtů. */
    private static function isBase64Url(string $value, int $bytes): bool
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return false;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded !== false && strlen($decoded) === $bytes;
    }

    // -----------------------------------------------------------------
    // Uživatelé a vlastní účet
    // -----------------------------------------------------------------

    public function uzivatele(): Response
    {
        $users = [];

        // Řádky tabulky připravené v controlleru — šablona jen vypisuje.
        foreach ($this->kernel->users()->all() as $user) {
            $isActive = (int) $user['is_active'] === 1;
            $invited = ($user['invited_at'] ?? null) !== null;

            $users[] = $user + [
                'displayName' => UserRepository::displayName($user),
                'roleLabel' => UserRepository::roleLabel((string) ($user['role'] ?? 'admin')),
                'lastLogin' => $user['last_login_at'] !== null ? get_when((string) $user['last_login_at']) : 'nikdy',
                'stateTone' => !$isActive ? 'muted' : ($invited ? 'warning' : 'ok'),
                'stateLabel' => !$isActive ? 'Pozastavený' : ($invited ? 'Čeká na pozvánku' : 'Aktivní'),
                'isInvited' => $invited,
                'isActiveFlag' => $isActive,
            ];
        }

        $active = count(array_filter($users, static fn (array $u): bool => $u['isActiveFlag'] && !$u['isInvited']));
        $invited = count(array_filter($users, static fn (array $u): bool => $u['isInvited']));

        return $this->view('settings/uzivatele', [
            'title' => 'Nastavení',
            'activeTab' => 'uzivatele',
            'users' => $users,
            'usersNote' => get_count($active, 'aktivní', 'aktivní', 'aktivních')
                . ($invited > 0 ? ' · ' . $invited . ' čeká na přijetí pozvánky' : ''),
            'roles' => UserRepository::ROLES,
            'currentUserId' => $this->currentUserId(),
            'firstUserId' => $this->kernel->users()->firstUserId(),
            'me' => $this->kernel->auth()->current() ?? [],
        ]);
    }

    /**
     * Jméno a e-mail vlastního účtu. Jméno je jen kosmetika; e-mail je
     * potřeba, aby fungovala obnova zapomenutého hesla.
     */
    public function updateAccount(): Response
    {
        $request = $this->request();
        $user = $this->kernel->auth()->current();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $name = mb_substr(trim($request->string('name')), 0, 190);
        $email = mb_strtolower(trim($request->string('email')));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Zadejte platný e-mail, nebo pole nechte prázdné.', 'error');
        }

        // Mezi jmény i e-maily (`findByLogin()`) — přihlásit se dá obojím.
        $existing = $email !== '' ? $this->kernel->users()->findByLogin($email) : null;

        if ($existing !== null && (int) $existing['id'] !== (int) $user['id']) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Tenhle e-mail už patří jinému účtu — jako adresa, nebo jako přihlašovací jméno.', 'error');
        }

        $this->kernel->users()->update((int) $user['id'], [
            'name' => $name !== '' ? $name : null,
            'email' => $email !== '' ? $email : null,
        ]);

        return $this->redirectWithFlash('nastaveni/uzivatele', 'Účet je uložený.');
    }

    /** Nahrání profilové fotky — vždy jen k vlastnímu účtu. */
    public function uploadAvatar(): Response
    {
        $user = $this->kernel->auth()->current();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        try {
            $this->kernel->avatars()->replace((int) $user['id'], $this->request()->files['avatar'] ?? null);
        } catch (HttpException $e) {
            return $this->redirectWithFlash('nastaveni/uzivatele', $e->getMessage(), 'error');
        }

        return $this->redirectWithFlash('nastaveni/uzivatele', 'Fotka je uložená.');
    }

    /** Odebrání fotky — kolečko se vrátí k iniciálám. */
    public function removeAvatar(): Response
    {
        $user = $this->kernel->auth()->current();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $this->kernel->avatars()->remove((int) $user['id']);

        return $this->redirectWithFlash('nastaveni/uzivatele', 'Fotka je odebraná.');
    }

    /**
     * Výdej fotky prohlížeči. Adresa nese id uživatele a verzi v `?v=`,
     * takže po výměně fotky se změní URL a dlouhá cache nikomu neukáže starou.
     */
    public function avatar(string $id): Response
    {
        $user = $this->kernel->users()->find((int) $id);
        $fileName = (string) ($user['avatar'] ?? '');

        if ($fileName === '') {
            throw HttpException::notFound('Fotka neexistuje.');
        }

        $path = $this->kernel->avatars()->absolutePath($fileName);

        if (!is_file($path)) {
            throw HttpException::notFound('Soubor fotky na disku chybí.');
        }

        return Response::stream(
            static function () use ($path): void {
                readfile($path);
            },
            [
                'Content-Type' => Avatars::mimeOf($fileName),
                'Content-Length' => (string) filesize($path),
                'Cache-Control' => 'private, max-age=604800',
            ],
        );
    }

    /**
     * Jen nové heslo + potvrzení — bez „současného hesla": kdo se dostal
     * do Nastavení, je přihlášený, takže heslo už jednou prokázal.
     */
    public function changePassword(): Response
    {
        $request = $this->request();
        $user = $this->kernel->auth()->current();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $error = PasswordPolicy::validate(
            (string) $request->input('password', ''),
            (string) $request->input('password_confirm', ''),
        );

        if ($error !== null) {
            return $this->redirectWithFlash('nastaveni/uzivatele', $error, 'error');
        }

        $this->kernel->users()->update((int) $user['id'], [
            'password_hash' => PasswordPolicy::hash((string) $request->input('password', '')),
        ]);

        // Auth hash v session přestal sedět — nové přihlášení je záměr:
        // změna hesla odhlašuje všechny relace včetně téhle.
        return $this->redirectWithFlash('prihlaseni', 'Heslo je změněné, přihlaste se znovu.');
    }

    /**
     * Pozvání kolegy (návrh: „Pozvat uživatele").
     *
     * Heslo se nezadává ručně: účet dostane náhodné, které nikam nejde,
     * a rovnou e-mail „nastavte si heslo" — stejný mechanismus jako obnova
     * zapomenutého hesla. Dokud odkaz nepoužije, svítí ve výpisu
     * „Čeká na pozvánku".
     */
    public function inviteUser(): Response
    {
        $request = $this->request();
        $username = mb_strtolower(trim($request->string('new_username')));
        $email = mb_strtolower(trim($request->string('new_email')));
        $name = mb_substr(trim($request->string('new_name')), 0, 190);
        $role = $request->string('new_role');

        if ($username === '' || $email === '') {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Vyplňte přihlašovací jméno i e-mail nového uživatele.', 'error');
        }

        if (preg_match('/^[a-z0-9._-]{3,50}$/', $username) !== 1) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Přihlašovací jméno: 3–50 znaků, jen písmena bez diakritiky, číslice, tečka, pomlčka a podtržítko.', 'error');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Zadejte platný e-mail.', 'error');
        }

        if (!isset(UserRepository::ROLES[$role])) {
            $role = 'technician';
        }

        // Obojí se hledá přes `findByLogin()`, tedy mezi jmény i e-maily:
        // přihlásit se dá obojím.
        if ($this->kernel->users()->findByLogin($username) !== null) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Tohle přihlašovací jméno už patří jinému účtu — jako jméno, nebo jako e-mail.', 'error');
        }

        if ($this->kernel->users()->findByLogin($email) !== null) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Tenhle e-mail už patří jinému účtu — jako adresa, nebo jako přihlašovací jméno.', 'error');
        }

        $id = $this->kernel->users()->create($username, PasswordPolicy::hash(bin2hex(random_bytes(32))), $email);
        $this->kernel->users()->update($id, [
            'name' => $name !== '' ? $name : null,
            'role' => $role,
            'invited_at' => date('Y-m-d H:i:s'),
        ]);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_USER, true, 'Pozván uživatel ' . $username, ['role' => $role]);

        return $this->sendInvite($id, $username, $email, 'Účet „%s“ je založený, na e-mail odešel odkaz pro nastavení hesla.');
    }

    /** Nový odkaz pro účet, který pozvánku ještě nepřijal (nebo mu propadla). */
    public function resendInvite(string $id): Response
    {
        $user = $this->kernel->users()->find((int) $id);

        if ($user === null || ($user['invited_at'] ?? null) === null || (string) ($user['email'] ?? '') === '') {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Pozvánku jde poslat jen účtu, který ji ještě nepřijal a má e-mail.', 'error');
        }

        return $this->sendInvite((int) $user['id'], (string) $user['username'], (string) $user['email'], 'Pozvánka pro „%s“ odešla znovu.');
    }

    private function sendInvite(int $userId, string $username, string $email, string $successMessage): Response
    {
        $token = $this->kernel->passwordReset()->issue($userId);
        $url = $this->kernel->appUrl('obnova-hesla/' . $token);

        $sent = $this->kernel->mailer()->sendMessage(
            $email,
            'Pozvánka — ' . Kernel::APP_NAME,
            EmailMessage::make('Vítejte — nastavte si heslo')
                ->paragraph("Založili vám účet do správy webů studia MEDIAGRAFIK (přihlašovací jméno „{$username}“). "
                    . 'Zbývá jediné — nastavit si heslo.')
                ->button('Nastavit heslo', $url)
                ->smallprint('Odkaz platí hodinu a lze ho použít jen jednou. Když propadne, kolega vám pošle nový z Nastavení → Uživatelé.')
                ->footerReason('Tento e-mail přišel, protože vám byl založen účet. Pokud ho nečekáte, napište studiu.'),
        );

        if ($sent) {
            return $this->redirectWithFlash('nastaveni/uzivatele', sprintf($successMessage, $username));
        }

        return $this->redirectWithFlash(
            'nastaveni/uzivatele',
            sprintf(
                'Účet „%s“ existuje, ale e-mail se nepodařilo odeslat (%s). Pošlete odkaz ručně: %s',
                $username,
                $this->kernel->mailer()->lastError() ?? 'neznámá chyba',
                $url,
            ),
            'error',
        );
    }

    /** Změna štítku role — nic víc než štítek (viz `UserRepository::ROLES`). */
    public function setRole(string $id): Response
    {
        $role = $this->request()->string('role');

        if (!isset(UserRepository::ROLES[$role])) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Neznámá role.', 'error');
        }

        $user = $this->kernel->users()->find((int) $id);

        if ($user === null) {
            throw HttpException::notFound();
        }

        $this->kernel->users()->update((int) $id, ['role' => $role]);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_USER, true,
            'Role uživatele ' . $user['username'] . ': ' . UserRepository::roleLabel($role));

        return $this->redirectWithFlash('nastaveni/uzivatele', 'Role je změněná.');
    }

    public function suspendUser(string $id): Response
    {
        $current = $this->kernel->auth()->current();

        if ($current !== null && (int) $current['id'] === (int) $id) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Svůj vlastní účet nemůžete pozastavit.', 'error');
        }

        // Zakládající účet (nejnižší id) nejde pozastavit nikým — jinak by ho
        // mohl zamknout druhý přidaný účet.
        if ($this->kernel->users()->firstUserId() === (int) $id) {
            return $this->redirectWithFlash('nastaveni/uzivatele', 'Zakládající účet nejde pozastavit.', 'error');
        }

        $this->kernel->users()->update((int) $id, ['is_active' => 0]);
        $this->kernel->rememberMe()->forgetAll((int) $id);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_USER, true, 'Pozastaven účet #' . (int) $id);

        return $this->redirectWithFlash('nastaveni/uzivatele', 'Účet je pozastavený.');
    }

    public function resumeUser(string $id): Response
    {
        $this->kernel->users()->update((int) $id, ['is_active' => 1]);
        $this->kernel->audit()->record(null, '', AuditLog::ACTION_USER, true, 'Obnoven účet #' . (int) $id);

        return $this->redirectWithFlash('nastaveni/uzivatele', 'Účet je znovu aktivní.');
    }
}
