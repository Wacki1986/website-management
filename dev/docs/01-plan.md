> **Stav 19. 9. 2026:** všechny etapy P0–P5 implementované (147 testů), aplikace čeká na první nasazení podle `00-provoz.md`.

# Plán: „Správa webů“ — aplikace pro správu WordPress webů + plugin MEDIAGRAFIK Monitor

## Kontext

Studio MEDIAGRAFIK spravuje desítky klientských WordPress webů. Dnes se stav webů sleduje
ručně a plugin `website-summary` posílá klientům e-mailové souhrny z každého webu zvlášť
(vlastní SMTP na každém webu = bezpečnostní dluh, viz jeho `docs/analyza-kodu.md`).
Cíl: jedna aplikace („hub“), která weby eviduje, hlídá dostupnost/SSL/verze/pluginy,
generuje alerty, vede servisní plán a historii a posílá klientům reporty. Grafický návrh
je hotový a kompletní (`dev/design/`, 47 obrazovek, finální BEM třídy).

**Rozhodnutí uživatele (18. 9. 2026):**
- Nový lehký WP plugin **MEDIAGRAFIK Monitor** (v tomto repu), sběr dat převzít z `website-summary`.
- v1 = monitoring + evidence + reporty. **Bez vzdálených akcí** (aktualizace/mazání pluginů) — tlačítka v UI zůstanou jako neaktivní placeholder, API pluginu na ně připravit.
- Hosting stejný jako `sprava-instanci`: sdílený hosting, PHP 8.1+, MySQL, Apache + `.htaccess`, cPanel cron volá HTTP endpoint s tokenem. Bez Composeru.
- Autentifikace a jádro převzít ze `sprava-instanci`.
- Google Fonts povolit v CSP (ne self-hosting).
- Distribuci pluginu (plugin-info.json + ZIP) servíruje sama aplikace.
- SMTP se nastaví až při nasazení v Nastavení → Odchozí pošta (vývoj: transport `log`).
- **Role jen jako štítek** (Správce / Technik / Účty a reporty), všichni mohou vše.
- Schvalování reportů: **volba u každého webu** („Vyžaduje schválení“).

## Zdroje k převzetí

| Co | Odkud |
|---|---|
| Kostra aplikace (Kernel, Router, Controller, Csrf, Request/Response, View, Form, Connection, Migrator, Settings, Secrets, Logger, RateLimiter, AuditLog) | `C:\Users\info\OneDrive\web\dispu\sprava-instanci\www\app\Core\` |
| Auth kompletně (Auth, PasswordPolicy, RememberMe, PasswordReset, LoginRateLimiter, UserRepository, Avatars) + `AuthController`, `ForgottenPasswordController`, šablony `auth/*`, `layout/auth.php` | tamtéž + `www/app/Controllers/`, `www/app/templates/` |
| Mailer bez závislostí (Mailer, SmtpTransport, EmailMessage, MailSettings) + web push (PushNotifier, PushSubscriptions, VapidKeys, WebPush/*) | `Core/Notifications/` |
| Vzor paralelního curl (`HealthClient::checkMany`, curl_multi) a klienta s jednotným výsledkem `['ok','status','data','error']` (`SystemClient`) | `Core/Instances/` |
| Vzor cron endpointu (`?token=` + `hash_equals` + `flock` + zápis posledního běhu do settings) | `Controllers/SubscriptionCronController.php`, `ProvisionCronController.php` |
| Testovací runner + bootstrap (`freshTestDb()`), nástroje (`build.ps1`, `dev-server.php`, `create-admin.php`, `zalozeni-spravce.php`, `generate-htpasswd.php`), `index.php` (CSP + hlavičky), `.htaccess`, `sw.js`, `config/env.sample.php` | `dev/tests/`, `dev/tools/`, `www/` |
| Pravidla psaní kódu (šablona nic nepočítá, `render_*`/`get_*`, žádný `ob_start` v šablonách, české komentáře „proč“) | `sprava-instanci/CLAUDE.md`, `aplikace/dev/docs/11-jak-cist-kod.md` |
| Sběr dat z WP (`WS_Data_Collector`), vlastní updater (`WS_Plugin_Updater`), konvence pluginu (CRLF, `ABSPATH` guard, prázdné `index.php`) | `C:\Users\info\OneDrive\web\plugins\website-summary\` |
| Design: SCSS balíček, HTML obrazovky, `STAVY.md` (stav → třídy), `ZMENY.md` | `dev/design/` |

Ze `sprava-instanci` se **nepřebírá**: `Core/{Instances,Provision,Hosting,Signup,Support}`, tarify, jádra, samoobsluha, cPanel provisioning.

## Struktura repozitáře

```
website-management/
├─ CLAUDE.md, README.md, CHANGELOG.md, .gitignore   (git init v P0; commity jen na vyžádání)
├─ www/                          na server jde OBSAH této složky
│  ├─ index.php                  kopie; CSP rozšířit o fonts.googleapis.com (style-src) a fonts.gstatic.com (font-src)
│  ├─ .htaccess                  kopie; výjimky z Basic auth: sw.js, manifest, favicons, offline.html,
│  │                             system/monitor-cron, r/*.gif, plugin/mediagrafik-monitor/*
│  ├─ sw.js, robots.txt
│  ├─ assets/{css/app.css (build), js/app.js + modules/, img/logo-mediagrafik.svg, favicons/, manifest.webmanifest, offline.html}
│  ├─ app/{bootstrap.php, Core/, Controllers/, helpers/, templates/}
│  ├─ config/env.sample.php
│  ├─ database/migrations/2026_09_20_000000_baseline.php
│  └─ storage/{logs,sessions,plugin}/     plugin/ = ZIP + plugin-info.json k distribuci (mimo git)
├─ dev/
│  ├─ design/                    handoff, nedotýkat se
│  ├─ scss/                      = dev/design/scss přesunuté 1:1 + pages/_auth.scss, components/_toast.scss
│  ├─ tests/                     run.php, bootstrap.php, *Test.php, fixtures/fake-wp-site.php
│  ├─ tools/                     build.ps1, dev-server.php, create-admin.php, zalozeni-spravce.php,
│  │                             generate-htpasswd.php, monitor-cron.php (CLI), build-plugin.ps1
│  └─ docs/00-provoz.md          nasazení, cron, Basic auth, distribuce pluginu
└─ wp-plugin/mediagrafik-monitor/   zdroj pluginu (build-plugin.ps1 z něj dělá ZIP do www/storage/plugin/)
```

## Jádro aplikace (Kernel)

`APP_NAME = 'Správa webů'`, session `SPRAVA_WEBU_SESSION`. Ponechané služby: `db, logger, secrets, csrf, view, migrator, limiter, loginRateLimiter, users, avatars, rememberMe, auth, audit, settings, mailSettings, mailer, passwordReset, vapidKeys, pushSubscriptions, pushNotifier` + `handle()/boot()/flash()/url()/asset()/scriptNonce()/jsImportMap()`.

Nové služby (každá = jedna čitelná třída, žádné rozhraní navíc):

```
Core/Clients/ClientRepository, Core/Clients/Ares            (ARES REST: https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico})
Core/Sites/{SiteRepository, SiteStatus, ApiKey}             SiteStatus::of(array $site): 'problem'|'attention'|'ok' + popisek („SSL vypršel“, „Zastaralé WP“, „PHP 7.4 EOL“, „Neaktivní pluginy“…)
Core/Events/EventLog                                        „Historie změn“ + „Poslední komunikace“ u klienta
Core/Monitor/{UptimeClient, UptimeRepository, SslChecker, DomainChecker (RDAP nic.cz), PluginClient,
              SnapshotImporter, SecurityAudit, OutsideProbe, PhpSupport, AlertEngine, AlertRepository,
              Notifier, MonitorRun}
Core/Service/{ServicePlanRepository, ServiceLogRepository, ServiceSchedule}
Core/Reports/{ReportSettingsRepository, ReportSchedule, ReportBuilder, ReportRenderer, ReportSender, ReportRepository}
Core/Plugin/PluginDistribution                              vydává plugin-info.json + ZIP ze storage/plugin
```

`shareViewGlobals()` sdílí navíc `siteCount`, `openAlertCount`, `monitorStatus` (poslední/další běh pro patičku sidebaru), každé v try/catch.

Helpery v `helpers/markup.php` přepsat pro nové BEM třídy: `get_icon($name, $mod='')` (masky `--icon-*`), `render_status($tone, $text)`, `get_badge()`, `get_avatar($name, $seed)`, `render_tabs()`, `render_empty()`, `get_pill()`; `url.php`, `time.php`, `forms.php` kopie.

**Záložky = odkazy na samostatné URL** (žádný JS na přepínání), stejné třídy `.tabs__item`. JS jen progresivně: toast, motiv, kopírování klíče, výběr řádků + bulk-bar, stepper, přepínač náhledu Počítač/Mobil, push, PWA.

## Databáze (jedna baseline migrace, utf8mb4_czech_ci)

Kopie: `users` (+ sloupec `role` VARCHAR(20) DEFAULT 'admin', `invited_at`), `remember_tokens`, `password_resets`, `rate_limits`, `settings`, `push_subscriptions`, `audit_log` (instance_* → site_*).

```
clients            id, name, company_id (IČO), vat_id, address, billing_note, since (DATE), email, phone, note, archived_at, created_at, updated_at
client_contacts    id, client_id FK CASCADE, first_name, last_name, role, email, phone, is_primary, created_at
sites              id, client_id FK SET NULL, name, url UNIQUE, admin_url, api_key (šifrovaný app_key), api_key_hint,
                   check_interval_min, timeout_s NULL (přepis globálu), watch_uptime/updates/ssl, hosting_note, backup_note,
                   status ok|down|unknown, consecutive_failures, last_check_at, last_status_code, last_response_ms, last_ok_at, last_error,
                   api_status ok|no_plugin|bad_key|error|unknown, api_failures, last_snapshot_at, snapshot_error,
                   ssl_valid_to, ssl_issuer, ssl_checked_at, ssl_error, domain_expires_on, domain_checked_at,
                   removed_at (soft delete, 12 měsíců archiv), created_at, updated_at
site_snapshots     site_id PK, fetched_at, plugin_version, payload JSON, wp_version, wp_update_version, php_version, db_type, db_version, db_size_mb,
                   theme_name, theme_version, theme_is_child, plugins_total/active/updates, security_updates, last_backup_at,
                   security_json, security_checked_at, security_missing, security_partial
site_plugins       id, site_id, file, name, author, version, new_version, is_active, has_update, first_seen_at, last_seen_at, version_changed_at; UNIQUE(site_id,file)
uptime_checks      id, site_id, checked_at, ok, status_code, response_ms, error, source cron|manual        (retence dle nastavení, default 12 měsíců)
uptime_days        site_id, day, checks, failed, downtime_min, sum_ms; PK(site_id,day)                   (navždy — zdroj pásku i reportů)
alerts             id, site_id, type down|ssl_expiring|ssl_expired|php_eol|api_error|updates|service_overdue|backup_old|domain_expiring,
                   severity error|warning|info, title, body, rule_label, status open|resolved|ignored, seen_at, opened_at, resolved_at,
                   resolved_by, resolve_note, notified_at, occurrences_30d, detail JSON
events             id, site_id, kind uptime|alert|plugin|core|report|service|system|security|settings|client, tone, message, detail JSON, user_name, created_at
service_plans      site_id PK, is_active, kind small|medium|large, frequency monthly|quarterly|halfyearly|once, first_date, next_date, updated_at
service_logs       id, site_id, performed_on, kind, description, minutes, status done|skipped, user_name, created_at
report_settings    site_id PK, is_active, frequency weekly|monthly|quarterly|manual, send_day, send_hour, requires_approval,
                   sections JSON (uptime_chart, updates, services, recommendations, cta, technical), next_send_at, last_sent_at, updated_at
report_recipients  id, site_id, email, label klient|kopie, created_at; UNIQUE(site_id,email)
reports            id, site_id, token CHAR(32) UNIQUE, kind scheduled|manual|test, period_from, period_to, period_label, note (poznámka pro klienta),
                   recipients JSON, summary_json, html MEDIUMTEXT, status draft|pending_approval|sent|partial|failed, error,
                   scheduled_for, sent_at, opened_at, open_count, created_at, created_by
```

Retence (v denním bloku cronu): `uptime_checks` starší než `settings.history_months`; weby s `removed_at` > 12 měsíců smazat natvrdo (CASCADE); `events` > 24 měsíců; `audit_log` nikdy.

## Protokol plugin ↔ hub

**Pull** (hub volá web): WP-Cron je nespolehlivý, hub řídí čas i zátěž, web nezná adresu hubu, z odpovědi hub pozná stav pluginu, „Zkontrolovat teď“ je synchronní. Plugin je **bez cronu a bez e-mailů**.

REST namespace `mediagrafik-monitor/v1`, vše GET, hlavička `X-MG-Key: mg_live_<32 znaků>`:

| Endpoint | Vrací |
|---|---|
| `/ping` | `{ok, plugin_version, site_url, wp_version, time}` — ověření klíče při uložení nastavení |
| `/summary` | `{site, wordpress, server, theme, plugins, content, security, backup}` — plánované stažení + „Zkontrolovat teď“ |
| `/security` | jen `{security}` — „Ověřit znovu“ |
| `/actions/*` (POST) | **v2**; v1 registruje jen prázdný stub `register_action_routes()` |

Obálka: `{"ok":true,"plugin_version":"1.0.0","generated_at":"…","data":{…}}` / `{"ok":false,"error":{"code":"invalid_key","message":"…"}}` (401). Vždy `Cache-Control: no-store`.

Klíč generuje hub (`ApiKey::generate()`), ukládá šifrovaně (`Secrets::encrypt`) + poslední 4 znaky; zobrazí se celý jen jednou po vygenerování. Plugin ukládá jen `sha256` hash + hint, porovnává `hash_equals`, per-IP throttle 10 chyb / 10 min (transient). Hub odmítá `http://` weby (kromě `env['allow_insecure_sites']`). Pro v2 mutace je připraveno `MG_Api_Key::verify_signature()` (HMAC timestamp+nonce). Plugin hookuje `rest_authentication_errors` (prio 5) a pro vlastní namespace s platným klíčem vrací `true` (obchází pluginy blokující REST).

Rozpoznání stavu pluginu (`api_status`): curl chyba → beze změny, `api_failures++`; 404 s `rest_no_route` → `no_plugin`; HTML 404 → jeden pokus `?rest_route=/mediagrafik-monitor/v1/summary`, pak `error`; 401/403 → `bad_key`; JSON ok → `ok`. Po 3 selháních hlavička webu „Neznámý stav · API neodpovídá“ + `.alert--row` (varianta `-apierror`), data z poslední snapshoty označená jako zastaralá.

## Monitorovací engine

Cron: `GET /system/monitor-cron?token=…` (`PUBLIC_ACCESS`, token v `settings` přes `setSecret('monitor_cron_token')`, `hash_equals`). Řádek pro cPanel zobrazí Nastavení → Monitoring ke zkopírování: `*/5 * * * * wget -qO- "https://…/system/monitor-cron?token=…" >/dev/null 2>&1` (běží každých 5 min, aby šla nastavit frekvence 5 min; každý web se kontroluje podle svého `check_interval_min`). CLI alternativa `dev/tools/monitor-cron.php`.

`MonitorRun::run(?int $now, int $budgetSeconds)` — `flock` na `storage/monitor.lock`, `ignore_user_abort(true)`, každý krok vlastní try/catch a kontrola časového rozpočtu (`min(50, max_execution_time − 10)`):
1. **Uptime**: `SiteRepository::dueForUptime()` → `UptimeClient::checkMany()` (curl_multi, GET, discard body, follow ≤ 3, timeout ze settings, UA `MEDIAGRAFIK-Monitor/1.0`, dávky po 30, ok = 200–399, 401 = ok) → `UptimeRepository::record()` (INSERT + UPSERT `uptime_days`) → `AlertEngine::afterUptime()`.
2. **SSL**: `dueForSsl(limit 20)` 1× denně/web, `stream_socket_client` + `openssl_x509_parse`, `verify_peer=false` (aby šel přečíst i prošlý certifikát).
3. **Doména**: `dueForDomain(limit 10)` 1× týdně/web, RDAP `https://rdap.nic.cz/domain/{d}` (jiné TLD: `https://rdap.org/domain/{d}`), pravidlo ve výchozím stavu vypnuté (dle návrhu).
4. **Snapshot z pluginu**: `dueForSnapshot(limit 8)` (interval ze settings, default 6 h) → `PluginClient::summaryMany()` (4 souběžně, 25 s) → `SnapshotImporter::import()` (upsert snapshot + diff `site_plugins` → události `plugin/updated|deactivated|removed`, `core/updated`) → `AlertEngine::afterSnapshot()`.
5. **Denní blok** (první běh po 07:00, `settings.monitor_daily_done`): souhrn aktualizací e-mailem, uzavření `uptime_days` za včerejšek, servis po termínu, retence, `RateLimiter::purge()`.
6. **Reporty**: `ReportSender::sendDue()` + příprava `pending_approval` den před termínem.
7. Zápis `settings.monitor_last_run` (at, ok, checked, down, pulled, ssl, reports, seconds) pro sidebar a Nastavení.

`AlertEngine` (čisté pravidlo → test bez sítě), jeden otevřený alert na (site, type):
- `down`: `consecutive_failures >= práh` → open (error) + notifikace; první OK → auto-resolve, událost „Výpadek N min“.
- `ssl_expiring` (warning, `days_left <= práh`), `ssl_expired` (error, `< 0`); vyřeší se po obnově.
- `php_eol`: `PhpSupport::TABLE` (ručně udržovaná tabulka konců podpory).
- `api_error`: `api_failures >= 3`.
- `updates` (warning): `plugins_updates + core > práh` (default 10), jen při `watch_updates`.
- `service_overdue` (warning): `service_plans.next_date + N h` bez zápisu v `service_logs`.
- `backup_old` (warning): `last_backup_at` starší než práh; jen pokud plugin datum zálohy umí zjistit (UpdraftPlus `updraft_last_backup`, BackWPup), jinak se pravidlo pro web přeskočí.
- `domain_expiring` (info): default vypnuto.
- Prahy + zapnutí každého pravidla v `settings` (Nastavení → Alerty a prahy).

`Notifier`: e-mail na `settings.alert_emails` přes `EmailMessage` + push (`PushNotifier` skupiny `sites, ssl, updates, ops`, `onceKey: 'alert-'.$id`).

„Zkontrolovat teď“ (`POST /weby/{id}/zkontrolovat`) i „Zkontrolovat vše“ (`POST /weby/zkontrolovat-vse`, jen uptime všech webů) volají stejné metody `MonitorRun::checkOne()/checkAll()` se `source='manual'`, rate limit 1/min na web.

## Bezpečnostní kontrola (5 opatření)

| Opatření | Kde | Jak |
|---|---|---|
| Bezpečnostní plugin | plugin | seznam známých (Wordfence, Solid Security, Sucuri, AIOS, Cerber, NinjaFirewall, Shield), filtr `mg_monitor_security_plugins`; aktivní + bez update = ok, s update = warning, žádný = error |
| Přihlašovací adresa | hub (`OutsideProbe::loginPage`) | GET `/wp-login.php`: 200 + `id="loginform"` → error; 404/403/401/redirect → ok; plugin doplní slug z WPS Hide Login / Solid Security |
| Dvoufázové ověření | plugin (heuristika) | admini × user meta známých 2FA pluginů (Two Factor, WP 2FA, Solid, Wordfence Login Security, miniOrange), filtr `mg_monitor_2fa_meta_keys`; všichni = ok, část = warning, nikdo = error |
| Složka /wp-content | plugin | `basename(WP_CONTENT_DIR) !== 'wp-content'` → ok |
| Basic AUTH | hub (`OutsideProbe::adminBasicAuth`) | GET `/wp-admin/` bez follow: 401 + `WWW-Authenticate: Basic` → ok, jinak error |

`SecurityAudit::run()` sloučí obě strany, uloží `security_json`, skóre dle `STAVY.md` (chybí → error, jen částečné → warning, vše → ok → `.empty`).

## Klientské reporty

- `ReportBuilder::build($site, $from, $to)` → `summary_json`: uptime (%, výpadky, kontroly, dny z `uptime_days`), aktualizace (z událostí `plugin/core updated`, počet bezpečnostních), servisy v období, příští servis, doporučení (PHP EOL, SSL, chybějící opatření — jen souhrn), obsah, technická příloha (verze) volitelně.
- `ReportRenderer::html()` renderuje `templates/emails/client-report.php` — tabulkový layout s inline styly (jediné místo bez tokenů, dle `ZMENY.md`), tracking pixel `GET /r/{token}.gif` (`TrackingController`, vždy 200 GIF, `no-store`). `Mailer::sendHtml()` = jediná úprava Maileru.
- `ReportSchedule::nextSendAt()` / `period()` (týdně/měsíčně/čtvrtletně/ručně; „Srpen 2026“, „3. čtvrtletí 2026“).
- Fronta: `requires_approval` → den před termínem vznikne `reports` řádek `pending_approval` (segment „Čeká na schválení“), jinak cron odešle sám. Náhled = `GET /reporty/{id}/nahled` (draft se vytvoří on-the-fly pro „Náhled“ u naplánovaných) s poznámkou pro klienta (≤ 400 znaků, rychlé pilulky), přepínači sekcí, „Poslat sobě na zkoušku“ (na e-mail přihlášeného, `kind=test`), „Odeslat klientovi“.
- Stavy v seznamu: Naplánováno / Čeká na schválení / Odesláno (+ otevřen kdy) / Selhalo; „Odeslat naplánované“ odešle vše ve frontě do týdne. „Upravit šablonu“ = **v2** (v1 tlačítko není).

## Routy (Kernel::registerRoutes)

```
GET  /                                  Dashboard::index
GET/POST /prihlaseni, POST /odhlaseni, POST /motiv, GET/POST /zapomenute-heslo, GET/POST /obnova-hesla/{token}   (kopie)

GET  /weby  ?q&stav&klient&seskupit&razeni      Site::index
GET/POST /weby/pridat                            Site::createForm/store (vygeneruje klíč, ping pluginu)
POST /weby/zkontrolovat-vse                      Site::checkAll
GET  /weby/{id}[/prehled]                        Site::overview      (varianty default/allGood/loading/apiError)
GET  /weby/{id}/pluginy ?q                       Site::plugins       („Aktualizovat vybrané“ disabled, title „Připravujeme“)
GET  /weby/{id}/obsah                            Site::content
GET  /weby/{id}/zabezpeceni, POST …/overit       Site::security/securityCheck
GET  /weby/{id}/servis, POST …/plan, POST …/posunout, GET/POST …/zapsat, POST …/{logId}/smazat     Service::*
GET  /weby/{id}/reporty, POST …/nastaveni, POST …/adresat, POST …/adresat/smazat, POST …/odeslat  Report::site/*
GET  /weby/{id}/nastaveni, POST …, POST …/klic, GET/POST /weby/{id}/odebrat, POST /weby/{id}/zkontrolovat, GET /weby/{id}/historie

GET  /alerty ?zavaznost&stav&web                 Alert::index
POST /alerty/{id}/vyresit|ignorovat|otevrit      Alert::resolve/ignore/reopen
POST /alerty/hromadne                            Alert::bulk (vyřešit/ignorovat vybrané), POST /alerty/vyresit-nove

GET  /klienti ?q, GET/POST /klienti/pridat, GET /klienti/{id}, GET/POST /klienti/{id}/upravit,
POST /klienti/{id}/archivovat, POST /klienti/{id}/weby (přiřadit), POST /klienti/ares (JSON, IČO → údaje)

GET  /reporty ?stav&q&obdobi                     Report::index (fronta + historie)
GET  /reporty/{id}/nahled, POST /reporty/{id}/poznamka, POST /reporty/{id}/sekce, POST /reporty/{id}/test, POST /reporty/{id}/odeslat
GET  /reporty/{id}                               Report::show (uložené HTML)
POST /reporty/odeslat-naplanovane
GET  /r/{file}                                   Tracking::pixel   PUBLIC

GET  /nastaveni → /nastaveni/monitoring; GET/POST /nastaveni/monitoring (+ POST …/token); GET/POST /nastaveni/alerty;
GET/POST /nastaveni/email (+ POST …/test) (kopie); GET /nastaveni/uzivatele, POST …/pozvat, POST …/{id}/pozastavit|obnovit|role;
POST /nastaveni/ucet, /nastaveni/heslo, fotka (kopie); GET /nastaveni/oznameni + push routy (kopie)

GET  /system/monitor-cron                        MonitorCron::run   PUBLIC (token)
GET  /plugin/mediagrafik-monitor/plugin-info.json, GET /plugin/mediagrafik-monitor/{file}.zip   PluginDistribution   PUBLIC
```

## Šablony ↔ návrh

`layout/shell.php` = `.app > .sidebar + .app__main` (logo, nav s počty, patička monitor + uživatel, burger přes `<details>`), `layout/auth.php` přestylovaný. Partialy: `site-header` (breadcrumb, stav, akce, tabs s badgi), `alert-row`, `uptime-bar`, `history-table`, `settings-tabs`, `pagination`, `empty`.

| Obrazovka | Šablona | Referenční HTML |
|---|---|---|
| Dashboard | `dashboard/index.php` (hero, metric-list, tabulka „Vyžaduje řešení“ se segmenty, empty/loading/apiError) | `dashboard*.html` |
| Weby | `sites/index.php` (filtry, seskupení `.table__group`, prázdno), `sites/form.php`, `sites/remove.php` | `weby*.html` |
| Detail webu 7 záložek | `sites/{overview,plugins,content,security,service,reports,settings}.php` + `sites/service-log-form.php`, `sites/history.php` | `detail-webu-*.html` |
| Alerty | `alerts/index.php` (metriky, segmenty, pilulky stavu, skupiny Dnes/Včera, bulk-bar) | `alerty*.html` |
| Klienti | `clients/{index,detail,form}.php` (detail čtení `.form__value` / úprava `.form__control`, pick webů, task-list) | `klienti.html`, `detail-klienta*.html`, `novy-klient*.html` |
| Reporty | `reports/index.php`, `reports/preview.php` (`.email-paper`, boční panel), `reports/show.php` (raw HTML) | `reporty-*.html`, `nahled-reportu*.html` |
| Nastavení | `settings/{monitoring,alerts,email,users}.php` (+ `oznameni`, účet) | `nastaveni-*.html` |
| E-maily | `emails/client-report.php`, alerty a souhrn přes `EmailMessage` | `nahled-reportu.html` (obsah sheetu) |

## WP plugin `wp-plugin/mediagrafik-monitor/`

```
mediagrafik-monitor.php        final class MG_Monitor (singleton): VERSION, options mg_monitor_key_hash/_hint/_last_seen, UPDATE_URL = https://<hub>/plugin/mediagrafik-monitor/plugin-info.json
includes/class-mg-api-key.php  store(), verify(), hint(), verify_signature() (v2)
includes/class-mg-rate-limit.php
includes/class-mg-rest-controller.php   register_routes(), permission(), ping(), summary(), security(), respond(), error(), allow_keyed_request(), register_action_routes() (stub)
includes/class-mg-collector.php         port WS_Data_Collector: site(), wordpress(), server(), theme(), plugins(), content() (veřejné post types), backup() (UpdraftPlus/BackWPup), all()
includes/class-mg-security-checks.php   security_plugin(), two_factor(), content_dir(), login_slug()
includes/class-mg-updater.php           port WS_Plugin_Updater (transient mg_monitor_update_*)
admin/class-mg-admin-page.php           Nastavení → MEDIAGRAFIK Monitor: vložení klíče (uloží hash), „Hub naposledy načetl data“, „Otestovat sběr dat“
uninstall.php, index.php (silence), readme.txt
```
Konvence z `website-summary/CLAUDE.md`: CRLF, `ABSPATH` guard, prefix `MG_`/`mg_monitor_`, escapování, české komentáře. Min. WP 6.8 / PHP 7.4. `dev/tools/build-plugin.ps1` = `php -l`, ZIP do `www/storage/plugin/mediagrafik-monitor-<ver>.zip`, přegenerování `plugin-info.json`.

## Etapy (každá samostatně nasaditelná a testovatelná)

**P0 — kostra + auth + design system.** Kopie jádra, `Kernel` ořezaný, migrace jen s převzatými tabulkami (+ `users.role`), `layout/shell.php` a `auth` v novém designu, `dev/scss` + build, `index.php` s CSP pro Google Fonts, `dev-server.php`, `create-admin.php`, `git init`, `CLAUDE.md`. Testy: kopie Router/Csrf/Auth/RememberMe/PasswordReset/Mailer/EmailMessage/Push/Kernel + `HtaccessGuardTest`. Ověření: `pwsh dev/tools/build.ps1`, `php dev/tests/run.php`, `php -S 127.0.0.1:8099 -t www dev/tools/dev-server.php` → login, motiv, Nastavení → Uživatelé/E-mail/Oznámení, 404, žádné CSP chyby v konzoli.

**P1 — klienti + weby + plugin + snapshot.** Tabulky clients/contacts/sites/snapshots/plugins/events; `Clients`, `Sites`, `Events`, `Monitor/{PluginClient,SnapshotImporter,SecurityAudit,OutsideProbe,PhpSupport}`, `Plugin/PluginDistribution`; `ClientController` (vč. ARES), `SiteController` (index, přidat, 4 čtecí záložky, nastavení, klíč, odebrání, historie); **celý WP plugin** + `build-plugin.ps1`. Testy: `ApiKeyTest`, `SnapshotImporterTest` (fixture JSON → sloupce; druhý import → události), `PluginClientTest` proti `fixtures/fake-wp-site.php` (režimy ok/bad_key/no_plugin/html/no_pretty), `SecurityAuditTest`, `PhpSupportTest`, `SiteRepositoryTest`, `ClientsTest`, `AresTest` (parsování fixture). Ověření: plugin na lokálním WP, přidat web, vložit klíč, „Zkontrolovat teď“ → Přehled/Pluginy/Obsah/Zabezpečení naplněné; špatný klíč → „Neznámý stav“; deaktivace pluginu → `no_plugin`; `/plugin/mediagrafik-monitor/plugin-info.json` vrací JSON a WP nabídne aktualizaci.

**P2 — monitoring + alerty + cron.** Tabulky uptime_checks/uptime_days/alerts; `UptimeClient, UptimeRepository, SslChecker, DomainChecker, AlertEngine, AlertRepository, Notifier, MonitorRun`; `MonitorCronController`, `AlertController` (segmenty, pilulky, hromadné akce, vyřešit vše nové), `SettingsController::monitoring/alerts` (frekvence, timeout, práh selhání, historie; prahy pravidel s přepínači), sidebar patička, `.htaccess` výjimka, `dev/tools/monitor-cron.php`. Testy: `UptimeClientTest` (fake server 200/503/timeout/redirect), `UptimeRepositoryTest` (upsert, 30 dní, %), `AlertEngineTest` (3 selhání → 1 alert, obnova → resolve + délka výpadku; SSL; PHP EOL; api_error; updates práh; vypnuté pravidlo), `SslCheckerTest` (fixture PEM), `MonitorRunTest` (lock → busy, rozpočet, zápis last_run, selhání kroku neshodí běh), `MonitorCronTest` (403/JSON). Ověření: token, dvakrát zavolat URL, vypnout lokální WP → po 3 bězích alert + `.eml` v `storage/logs`, zapnout → resolve + událost, pásek uptime, Alerty stránka.

**P3 — servis.** Tabulky service_plans/logs; `Service/*`; `ServiceController`; šablona Servis, badge „za N dní / po termínu“, pravidlo `service_overdue`, Dashboard sloupec Servis. Testy: `ServiceScheduleTest` (měsíčně/čtvrtletně/pololetně/jednorázově, `upcoming(3)`, posun +7 d, 31. den v měsíci), `ServiceLogsTest`.

**P4 — reporty.** Tabulky report_settings/recipients/reports; `Reports/*`, `Mailer::sendHtml()`, `ReportController` (web, fronta, náhled, poznámka, sekce, test, odeslat), `TrackingController`, e-mail šablona, krok cronu, `.htaccess` výjimka pro pixel. Testy: `ReportScheduleTest` (přelomy měsíců, DST, štítky), `ReportBuilderTest`, `ReportRendererTest` (pixel, bez `<script>`, tabulky), `ReportSenderTest` (log transport → `.eml`, stavy, selhání → failed + událost, `pending_approval` den před termínem), `TrackingPixelTest`. Ověření: měsíčně + adresát → Náhled → poznámka → „Poslat sobě na zkoušku“ → `.eml`; otevřít pixel URL → „otevřen …“.

**P5 — dashboard + dolaďování + provoz.** `DashboardController` (hero, metriky, tabulka s filtry, „Zkontrolovat vše“, stavy empty/loading/apiError), seskupení a řazení Webů, pozvánky uživatelů (reset-link e-mail, stav „Čeká na pozvánku“), `dev/docs/00-provoz.md` (nasazení, cron, Basic auth s výjimkami, distribuce pluginu, hromadné přidání 84 webů přes `dev/tools/import-sites.php` z CSV), `README.md`, favicons/manifest, kontrola všech obrazovek proti `dev/design/html/*` ve světlém i tmavém motivu a při 390 px. Testy: `DashboardTest`, `SiteListFilterTest`, `PwaTest`.

## Ověření celku

1. `pwsh dev/tools/build.ps1` (SCSS + `php -l`) a `php dev/tests/run.php` bez chyb (DB `sprava_webu_test`).
2. `php -S 127.0.0.1:8099 -t www dev/tools/dev-server.php`, projít index návrhu obrazovka po obrazovce vedle běžící aplikace.
3. Lokální WordPress s pluginem: přidání webu → klíč → kontrola → alert při vypnutí → report na zkoušku → pixel.
4. `env.mail.transport = log` ve vývoji; e-maily v `storage/logs/*.eml`.

## Rizika a poznámky

- `max_execution_time` na cílovém hostingu neznám; `MonitorRun` má rozpočet a rozkládá stahování snapshotů, ale při 30 s bude potřeba cron každých 5 min (počítá se s tím).
- Detekce 2FA a záloh je heuristika podle známých pluginů — v UI označit „nezjištěno“, ne „chybí“, když plugin nic nenajde.
- Weby celé za Basic auth vrací 401 → počítá se jako dostupné.
- Aktualizace v reportu se odvozují z rozdílu snapshotů (interval 6 h) — dvě aktualizace téhož pluginu v jednom dni se sloučí.
- `PhpSupport::TABLE` je ruční tabulka (jedna úprava ročně).
