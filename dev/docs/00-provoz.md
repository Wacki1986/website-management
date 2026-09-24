# Provoz Správy webů

Příručka k nasazení a provozu aplikace na sdíleném hostingu (PHP 8.1+,
MySQL, Apache s `.htaccess`, cPanel cron). Vývojové věci jsou v `README.md`.

## 1. První nasazení

1. Lokálně: `pwsh dev/tools/build.ps1` a `php dev/tests/run.php` — obojí bez chyb.
2. Na server jde **obsah `www/`** (FTPS/SFTP). `config/env.php` a `storage/`
   se nenahrávají; `.vscode/sftp.json` je má ve výjimkách. Adresář `storage/`
   (s podsložkami `logs`, `sessions`, `plugin`) musí být pro PHP zapisovatelný.
3. Na serveru: databáze (utf8mb4) + uživatel; `config/env.php` ze vzoru
   `config/env.sample.php`:
   - `app_url` = veřejná adresa aplikace (bez lomítka na konci) — používá se
     v e-mailech, v cron řádku a v adrese pro aktualizace pluginu;
   - `app_key` z `php dev/tools/generate-tokens.php` (šifruje API klíče webů
     a tajemství v nastavení — **po nasazení se nemění**, jinak se klíče
     nedají přečíst);
   - `environment` = `production`, `mail.transport` = `smtp` (SMTP údaje se
     doplní až v aplikaci).
4. Migrace se spustí samy při prvním požadavku (tabulka `migrations`).
5. První účet: `php dev/tools/create-admin.php <jméno> <heslo>`; bez SSH
   nahrát `dev/tools/zalozeni-spravce.php` do `www/`, do
   `storage/install-token.txt` zapsat token a otevřít skript v prohlížeči
   (po založení se sám smaže).
6. Ověřit: `/` přesměruje na přihlášení přes HTTPS; `/config/env.php`,
   `/app/Core/Kernel.php`, `/storage/logs/` vrací 404; `/assets/css/app.css` 200;
   `/plugin/mediagrafik-monitor/plugin-info.json` vrací JSON (po kroku 4).
7. Nastavení → Odchozí pošta: SMTP účet studia (SPF/DKIM na doméně
   odesílatele), „Poslat zkušební e-mail". Odesílatel a jeho jméno se
   objeví v patičce klientských reportů; telefon do patičky reportu je
   v `settings.report_phone` (zatím jen přes DB, v1 nemá pole v UI).
8. Nastavení → Monitoring: adresy pro alerty, frekvence, **vygenerovat token
   cronu** a zkopírovat řádek do cPanelu (kapitola 3).
9. Nastavení → Alerty a prahy: zkontrolovat prahy (výchozí odpovídají návrhu).

## 2. Vrstva nad přihlášením (HTTP Basic auth)

Aplikace zná API klíče ke všem webům — patří nad ni druhá vrstva. V
`www/.htaccess` je připravená šablona (varianta B) s HTTP Basic auth a
výjimkami, bez kterých by přestaly fungovat:

| Výjimka | Proč |
| --- | --- |
| `sw.js`, `assets/manifest.webmanifest`, `assets/offline.html`, `assets/favicons/` | instalace na plochu a upozornění na telefon |
| `system/monitor-cron` | hostingový cron (chráněný vlastním tokenem) |
| `r/<token>.gif` | sledovací obrázek v reportech — stahuje ho poštovní klient klienta |
| `assets/img/logo-mediagrafik.svg` | logo v hlavičce klientského reportu |
| `plugin/mediagrafik-monitor/` | WordPress na webech klientů si sem sahá pro aktualizace pluginu |

Heslo vyrobí `php dev/tools/generate-htpasswd.php`. IP filtr místo hesla je
neslučitelný s upozorněními na telefon z mobilní sítě.

## 3. Cron

Monitor běží jako HTTP endpoint volaný každých 5 minut (každý web se
kontroluje podle svého intervalu; 5 minut je nejjemnější nastavitelná
frekvence). Řádek pro cPanel ukáže Nastavení → Monitoring po vygenerování
tokenu:

```
*/5 * * * * wget -qO- "https://<aplikace>/system/monitor-cron?token=<token>" >/dev/null 2>&1
```

Co jeden průchod dělá (v tomto pořadí, s časovým rozpočtem podle
`max_execution_time`): dostupnost → SSL (1× denně na web) → doména (1×
týdně, RDAP) → data z pluginu (interval v nastavení, výchozí 6 h) → denní
blok (souhrn aktualizací e-mailem, servis po termínu, retence) → reporty
(den před termínem příprava ke schválení, v termínu odeslání) → ikony webů
→ konce podpory PHP a databází (1× za 30 dní, viz kapitola 7).

Stav posledního běhu je v patičce bočního menu a v Nastavení → Monitoring;
když cron neběží déle než 3× interval, dashboard ukáže pruh „Monitor
neběží" a e-mail dostanou adresy z nastavení alertů. Bez SSH jde průchod
spustit ručně tlačítkem „Spustit průchod teď"; z příkazové řádky
`php dev/tools/monitor-cron.php`.

Zámek `storage/monitor.lock` brání souběhu dvou průchodů. Pokud by zůstal
viset po pádu PHP, další běh ho převezme (flock se uvolní s procesem).

## 4. Plugin MEDIAGRAFIK Monitor

- Build: `pwsh dev/tools/build-plugin.ps1` → `www/storage/plugin/mediagrafik-monitor-<verze>.zip`
  a `plugin-info.json`. Obojí se nahrává na server spolu s aplikací
  (`storage/plugin/` je jediná část `storage/`, která se nahrává).
- Nová verze pluginu = zvýšit `VERSION` v `wp-plugin/mediagrafik-monitor/mediagrafik-monitor.php`
  a v `readme.txt`, znovu build, nahrát. WordPress na webech nabídne
  aktualizaci do 12 hodin (transient) — plugin si čte
  `https://<aplikace>/plugin/mediagrafik-monitor/plugin-info.json`.
- Instalace na web: Pluginy → Nahrát plugin → ZIP → Aktivovat → Nastavení →
  MEDIAGRAFIK Monitor → vložit API klíč z detailu webu (Nastavení → Připojení).
  Klíč se ukazuje jen jednou po vygenerování.
- Plugin nic neposílá sám: aplikace se ho ptá (`/wp-json/mediagrafik-monitor/v1/…`
  s hlavičkou `X-MG-Key`). Když web REST API blokuje, plugin zkouší
  `?rest_route=`; když ani to neprojde, web má stav „Plugin neodpovídá".
- **Aktualizace pluginů ze správy** (plugin 1.1.0+): detail webu → Pluginy →
  zaškrtnout a „Aktualizovat vybrané" (nejvýš 10 najednou), nebo
  „Aktualizovat" v řádku. Požadavek je navíc podepsaný časem (okno ±5 minut
  — rozejdou-li se hodiny serveru správy a webu, plugin odpoví „Podpis
  nesouhlasí"). Web aktualizuje stejně jako hromadná aktualizace ve
  wp-admin: aktivní pluginy zůstanou aktivní, při selhání rozbalení vrátí
  WordPress původní verzi. Nejde to na webu s `DISALLOW_FILE_MODS` nebo bez
  přímého zápisu na disk (FTP údaje ve `wp-config.php`) a u placených
  pluginů bez licence — správa ukáže důvod. Výsledek je v Historii webu
  a v auditu. Weby s pluginem 1.0.0 mají tlačítko neaktivní, dokud si
  plugin samy neaktualizují (viz předchozí bod) — přechod 1.0.0 → 1.1.0 je
  potřeba jednou udělat ve wp-admin (nahrát ZIP, „Nahradit stávající").
- Od 1.1.0 jde i MEDIAGRAFIK Monitor aktualizovat ze správy: jakmile je na
  serveru správy novější ZIP, záložka Pluginy ho nabídne hned (správa verzi
  zná z `plugin-info.json`), web si při aktualizaci novou verzi načte
  znovu a nečeká na svou 12hodinovou cache.
- **Smazání neaktivního pluginu** (plugin 1.2.0+): Pluginy → ikona koše u
  neaktivního pluginu → potvrzení v okně nad tabulkou (bez JavaScriptu
  samostatná potvrzovací stránka). Web plugin smaže jako wp-admin
  včetně odinstalace (plugin tím obvykle smaže i svá data). Aktivní plugin
  ani MEDIAGRAFIK Monitor smazat nejde.
- **Deaktivace a aktivace pluginu** (plugin 1.5.0+): Pluginy → ikona
  vypínače v řádku. Aktivní plugin se tím dá odebrat ve dvou krocích —
  deaktivovat, zkontrolovat web, pak smazat ikonou koše; neaktivní plugin
  jde stejnou ikonou znovu zapnout. MEDIAGRAFIK Monitor vypnout nejde.
  Po kliknutí se ikona točí a řádek ztlumí, dokud web neodpoví.
- **Typy obsahu** (záložka Obsah): veřejné typy a od pluginu 1.5.1 i vlastní
  typy s vlastní položkou v menu wp-admin (šablony je často registrují jako
  neveřejné — Reference, Kurzy). Interní typy pluginů se nepočítají.
- **Aktualizace WordPressu** (plugin 1.2.0+): Přehled → „Aktualizovat" u
  verze WordPressu → potvrzovací stránka. Web aktualizuje jako wp-admin
  (stránka údržby, převod databáze). Potvrzuje se konkrétní verze; když
  web mezitím nabízí jinou, nebo nová verze potřebuje vyšší PHP/MySQL,
  aktualizace se odmítne s důvodem. Před hlavní verzí (6.8 → 6.9) web
  zazálohujte — potvrzovací stránka ukazuje stáří poslední zálohy.
- Která akce je na webu k dispozici, rozhoduje verze pluginu v posledních
  datech webu. Tlačítko akce, kterou plugin ještě nezná, je neaktivní a
  v bublině řekne, na jakou verzi plugin aktualizovat.
- **Knihovna pluginů** (menu → Knihovna pluginů): placené a vlastní pluginy,
  které WordPress sám neaktualizuje. Nahrát ZIP od autora (jedna složka
  pluginu v kořeni, hlavička s `Version:`); novější verze nahradí starší,
  nižší nahrát nejde. Weby s MEDIAGRAFIK Monitorem 1.4.0+ si ji nabídnou
  k aktualizaci samy (do 12 hodin, ve správě hned na záložce Pluginy).
  ZIPy leží ve `storage/plugin-library/` (zálohovat, nenahrávat z počítače
  — `sftp.json` je má ve výjimkách) a ven jdou jen s podpisem pro konkrétní
  web. Velikost ZIPu omezuje `upload_max_filesize` hostingu.

## 5. Hromadné přidání webů

Na start (desítky webů) je `dev/tools/import-sites.php`:

```
php dev/tools/import-sites.php weby.csv --klice
```

CSV (UTF-8, `;` nebo `,`, hlavička `nazev;url;klient;interval;hosting;zalohy`).
S `--klice` se každému webu vygeneruje API klíč a vypíše do
`www/storage/import-klice.txt` — jediné místo, kde jdou klíče přečíst;
po vložení do pluginů soubor smazat. Existující adresy se přeskočí, skript
jde spouštět opakovaně.

## 6. Klientské reporty

- Zapínají se u každého webu (Nastavení webu → Klientské reporty): frekvence,
  den a hodina, adresáti (klient / kopie), volba „Vyžaduje schválení".
- Bez schválení report odchází sám v termínu. Se schválením vznikne den
  předem ve frontě Reporty jako „Čeká na schválení" (push + e-mail), po
  kontrole se odešle tlačítkem — nebo hromadně „Odeslat naplánované".
- Náhled ukáže přesně to, co klient dostane; poznámka pro klienta (≤ 400
  znaků) a přepínače sekcí se ukládají k reportu, sekce i jako výchozí pro
  příští reporty webu. „Poslat sobě na zkoušku" jde na e-mail přihlášeného.
- Otevření reportu klientem hlídá 1×1 obrázek `/r/<token>.gif` (musí být
  mimo Basic auth, viz kapitola 2). Kopie odeslaného HTML zůstává uložená.

## 7. Servis a verze PHP a databází

- **Zápis servisu** (detail webu → Servis → „Zapsat servis"): druh, seznam
  úkolů k odškrtnutí (seznamy po druzích v Nastavení → Servis), pod ním
  „Přidat další úkol" pro úkol jen tohoto zápisu (přepsat i odebrat jde
  při úpravě), čas v minutách a nepovinná **Poznámka k servisu**. Uložit
  jde, když je odškrtnutý aspoň jeden úkol nebo vyplněná poznámka.
- V **reportu** klient uvidí hotové úkoly pod sebou s fajfkou a pod nimi
  poznámku. Úprava zápisu se projeví v reportu, který ještě neodešel.
- **Předvyplněná poznámka:** když web běží na PHP nebo databázi, které
  podpora končí do roka nebo už skončila, nový zápis servisu má v poznámce
  větu pro klienta s doporučením přechodu. Nehodí-li se, smaže se.
- **Výpis webů** má sloupce WP, PHP a Databáze: oranžově WP s čekající
  aktualizací a PHP/databáze, kterým podpora skončí do roka; červeně verze
  bez bezpečnostní podpory; vysvětlení v bublině. Web s takovou verzí je
  ve stavu **Pozornost** („PHP 8.2 končí", „MariaDB 10.6 EOL").
- **Konce podpory** (Nastavení → Monitoring → Konce podpory PHP a databází):
  data jsou z endoflife.date, cron je ověřuje jednou za 30 dní, ručně
  tlačítko „Ověřit teď". Karta ukáže, kdy se ověřovalo, co se při tom
  změnilo a verze, které weby právě používají. Když služba neodpoví,
  platí poslední stažená data, a bez nich vestavěné tabulky v
  `PhpSupport` / `DbSupport` (ty stačí jednou za čas srovnat se staženými).

## 8. Zálohy a retence

- Zálohovat: databázi a `config/env.php` (bez `app_key` jsou API klíče
  nečitelné). `storage/logs` a `storage/sessions` netřeba.
- Retence (denní blok cronu): kontroly dostupnosti podle „Historie" v
  Nastavení → Monitoring (výchozí 12 měsíců; denní součty zůstávají),
  události 24 měsíců, odebrané weby 12 měsíců, `audit_log` nikdy.
- Logy: `storage/logs/app-*.log` (chyby cronu, odmítnuté klíče), s
  transportem `log` i `*.eml`.

## 9. Když něco nejde

| Příznak | Kde hledat |
| --- | --- |
| Patička „Monitor neběží" | cron v cPanelu, token (Nastavení → Monitoring → „Vygenerovat nový" mění adresu), Basic auth výjimka |
| Web „Plugin neodpovídá" | klíč v pluginu, bezpečnostní plugin blokující REST, detail webu → „Zkontrolovat teď" ukáže konkrétní chybu |
| Report „Nedoručeno" | Nastavení → Odchozí pošta (zkušební e-mail), adresa příjemce u webu, `storage/logs/app-*.log` |
| Upozornění na telefon nechodí | Nastavení → Oznámení (VAPID klíče, zařízení), Basic auth výjimky pro `sw.js` a manifest |
| Po změně hesla Basic auth přestal cron | cron endpoint musí zůstat ve výjimkách |
