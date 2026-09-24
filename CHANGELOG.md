# Změny

## 0.6.0 — 24. 9. 2026

- **Opuštěné a z adresáře stažené pluginy** (`PluginDirectory`, data z API
  wordpress.org, stejná jako u Wordfence): správa jednou týdně ověří každý
  plugin z webů (nejvýš 40 za průchod cronu, jeden dotaz pro všechny weby),
  uloží do nové tabulky `plugin_directory` a počty do `site_snapshots`
  (migrace `2026_09_24_000000_plugin_directory`).
  - Záložka Pluginy: sloupec **Vydáno** („před 2 roky", „staženo 1. 4. 2020",
    „mimo adresář"), Stav „Aktivní · opuštěný" / „· stažen — bezpečnost",
    vysvětlení v bublině, počet v metrice.
  - Stav webu: plugin stažený kvůli bezpečnosti = Problém „Nebezpečný
    plugin", jinak stažený / opuštěný = Pozornost.
  - Alert „Opuštěné pluginy" (Nastavení → Alerty, práh 24 měsíců, jde
    vypnout; se staženým pluginem vážný).
  - Servis předvyplní poznámku, report přidá doporučení „Zastaralé doplňky" /
    „Doplněk s bezpečnostní chybou".
- Nastavení → Alerty: klíč hodnoty pravidla přímo u pravidla (`valueKey`).

## 0.5.5 — 24. 9. 2026

- **Šablona reportu se upravuje přímo v náhledu e-mailu** — vzorový report
  se všemi sekcemi na celou šířku, upravitelné texty mají tužku; kliknutí
  otevře pole se značkami a tlačítky pro jejich vložení, náhled se hned
  překreslí se skutečnými údaji. Lišta „Neuložené změny: N · Zahodit ·
  Uložit šablonu", u odchodu se neuloženými změnami varování, „Vrátit
  výchozí texty" v potvrzovacím okně. Barva tužky: zelená = upravený text,
  oranžová = neuložená změna (`report-template.js`, `app/_template-editor.scss`).
- **Pořadí sekcí e-mailu** jde měnit v editoru šipkami (štítek sekce po
  najetí myší): poznámka, aktualizace, servis, obsah, doporučení, graf,
  technická příloha, výzva ke kontaktu. Pořadí je součást šablony a platí
  pro HTML i textovou variantu (`ReportTemplate::order()`).
- Report: odrážky „Co jsme pro vás udělali“ jako zelená fajfka místo
  kolečka (Outlook zaoblení neumí), mezera mezi poslední sekcí a patičkou;
  náhled v editoru bez rámu, jen odsazený.
- CLAUDE.md: JavaScript je vždy zapnutý — řešení se skriptem má přednost
  před záložní cestou bez JS.

## 0.5.4 — 24. 9. 2026

- **Šablona klientského reportu** (Nastavení → Šablona reportu, tlačítko
  „Upravit šablonu" na stránce Reporty podle návrhu): předmět (vše v pořádku /
  ostatní), úvod, popisek poznámky, nadpisy sekcí, tlačítka, výzva ke
  kontaktu, podpis a patička. Značky `{web}`, `{obdobi}`, `{kdy}`,
  `{studio}`; prázdné pole = výchozí text; „Vrátit výchozí texty"; náhled na
  posledním reportu. Platí pro HTML i textovou variantu (`ReportTemplate`).
- **Důvod nepovedené aktualizace pluginu** — web hlásil „už aktuální", když
  placený plugin (Rank Math PRO) mimo wp-admin aktualizaci nenabízel, a
  správa ukázala fajfku. Teď je to selhání s vysvětlením (licence,
  aktualizace ve wp-admin) — funguje i se starším pluginem.
- **Plugin MEDIAGRAFIK Monitor 1.5.2** — když obnovení seznamu aktualizací
  nabídku placeného pluginu zahodí, použije tu, kterou web ukazoval (jako
  tlačítko ve wp-admin); jinak vrátí důvod.
- Budoucí TO DO v `dev/docs/02-todo.md` (bezpečnostní aktualizace, 2FA).

## 0.5.3 — 24. 9. 2026

- **Konce podpory PHP a databází z endoflife.date** (`SupportTables`):
  Nastavení → Monitoring má kartu s tlačítkem „Ověřit teď", cron data
  ověřuje sám jednou za 30 dní. Stažené tabulky se uloží a používají místo
  vestavěných; při výpadku služby platí poslední stažená data. Karta
  ukazuje, kdy se ověřovalo, co se změnilo a verze, které weby používají.
  Oprava vestavěné tabulky: MariaDB 11.8 končí 4. 6. 2028 (ne 2030).
- **Stav webu „Pozornost" i pro končící podporu** — „PHP 8.2 končí",
  „MariaDB 10.11 končí" (do roka), databáze bez podpory „MariaDB 10.6 EOL".
  Pořadí: bez podpory → zastaralé WP → SSL brzy vyprší → končí do roka →
  neaktivní pluginy.
- Provozní dokumentace: nová kapitola 7 (servis, verze PHP a databází),
  potvrzení smazání v okně, typy obsahu.

## 0.5.2 — 24. 9. 2026

- **Deaktivace a aktivace pluginu ze správy** — ikona vypínače v řádku
  pluginu na záložce Pluginy. Odebrání pluginu je tím dvoukrokové:
  deaktivovat → zkontrolovat, že web funguje → smazat ikonou koše
  (nebo vrátit aktivací). Výsledek je v Historii webu a v auditu
  („Aktivace pluginu"). MEDIAGRAFIK Monitor vypnout nejde (správa by
  k webu ztratila přístup) — hlídá hub i plugin.
- **Plugin MEDIAGRAFIK Monitor 1.5.0** — nová podepsaná akce
  `plugin-activation` (`MG_Site_Actions::set_active()`); weby se starším
  pluginem ikonu vypínače nemají.
- **Průběh u vypínače a oka** — po kliknutí se ikona změní na točící se
  kolečko, řádek se ztlumí, pruh nad tabulkou řekne, co se děje
  („Deaktivuji WooCommerce na webu…"), a další kliknutí se do návratu
  stránky zahodí.
- **Smazání pluginu v modálním okně** — koš na záložce Pluginy otevře
  potvrzení v okně nad tabulkou (nativní `<dialog>`, `confirm-dialog.js`)
  místo přechodu na novou stránku; bez JavaScriptu zůstává potvrzovací
  stránka.
- **Servis bez povinného popisu** — stačí odškrtnutý seznam úkolů; bez
  popisu uvidí klient v reportu (i historie servisů) odškrtnuté úkoly.
  Musí být vyplněné aspoň jedno z obou. Odhady času u druhů servisu
  („45–60 min" apod.) a předvyplněné minuty jsou pryč, skutečný čas se
  zapisuje dál.
- **Akce v řádku tabulky vpravo jako ikony** (nové pravidlo v CLAUDE.md,
  třída `.row-actions`): historie servisů má sloupec Akce s tužkou
  a košem, knihovna pluginů ikonu stažení místo odkazu „Stáhnout".

- **Úkoly servisu** — zaškrtávátka jako v návrhu v rámečku s řádky;
  pod seznamem „Přidat další úkol“ (vlastní úkol jen pro tento zápis, jde
  přepsat i odebrat křížkem; bez skriptu prázdný řádek na konci).
  „Co jsme udělali“ je teď „Poznámka k servisu“. Report vypíše hotové
  úkoly pod sebou s fajfkou a poznámku pod nimi (uložené starší reporty
  beze změny).
- **Plugin MEDIAGRAFIK Monitor 1.5.1** — typy obsahu berou i vlastní
  typy s vlastní položkou v menu wp-admin, i když je šablona registruje
  jako neveřejné (Reference, Kurzy u mamavkondici.cz).

- **Výpis webů: WP, PHP a Databáze ve vlastních sloupcích** (návrh měl
  jeden „WP / PHP“). Červeně verze bez bezpečnostní podpory, oranžově
  verze, které podpora skončí do roka (PHP 8.2 → 31. 12. 2026), vysvětlení
  v bublině. Nová tabulka konců podpory MySQL a MariaDB (`DbSupport`,
  jen LTS verze; krátkodobé starší než nejnovější LTS = bez podpory).
  Doporučené PHP v textech posunuto z 8.3 na 8.4.
- **Servis předvyplní poznámku** větou pro klienta, když web běží na
  zastaralém PHP nebo databázi (smazat ji jde jako běžný text).

## 0.5.1 — 24. 9. 2026

- **Akce v řádku pluginu jako ikony** — aktualizovat (kolečko se šipkou),
  nesledovat / sledovat (přeškrtnuté / otevřené oko, ikony doplněné do
  `app/_extras.scss`), smazat (koš); popis v `title` a `aria-label`.
- **Průběh aktualizace pluginů** — skript (`plugin-update.js`) posílá pluginy
  po jednom: pruh „Aktualizuji 2 z 5…" nad tabulkou, točící se ikona
  u aktualizovaného pluginu, ✓ a nová verze u hotového, × s důvodem
  u neúspěšného. Data z webu se načtou jen po posledním pluginu. Bez
  JavaScriptu odejde formulář najednou jako dřív.
- `SiteActionController::updatePlugins()` odpovídá skriptu JSONem
  (`X-Requested-With`), jinak přesměruje jako dřív.
- Doplněná třída `icon--warning` (používaná v textu pravidel, v CSS chyběla).

## 0.5.0 — 23. 9. 2026

- **Knihovna pluginů** (nová položka menu) — placené a vlastní pluginy mimo
  wordpress.org. Nahraje se ZIP, správa z hlavičky přečte název a verzi;
  novější verze nahradí starší (na serveru zůstává jen jeden ZIP, nižší
  verzi nahrát nejde). Přehled, na kterých webech plugin je a kde čeká
  aktualizace; ZIP ke stažení pro ruční instalaci na nový web.
- **Plugin MEDIAGRAFIK Monitor 1.4.0** — nabízí WordPressu aktualizace
  pluginů z knihovny (`MG_Library`, stejně jako vlastní `MG_Updater`):
  aktualizace je vidět ve wp-admin i ve správě a spouští se stávajícím
  „Aktualizovat". Web se prokazuje otiskem API klíče (`knihovna.json`),
  ZIP stáhne jen s podpisem pro sebe — placené pluginy nejsou veřejné.
  Adresy jsou pod `/plugin/mediagrafik-monitor/`, výjimka z Basic auth
  platí beze změny.
- Migrace `2026_09_23_000003_plugin_library` (tabulka `plugin_library`).

## 0.4.1 — 23. 9. 2026

- **Plugin MEDIAGRAFIK Monitor 1.3.1** — oprava: po aktualizaci pluginu
  (z wp-admin i ze správy) se nabízela „aktualizace" na právě nainstalovanou
  verzi. WordPress hned po aktualizaci kontroluje aktualizace ve stejném
  požadavku, kdy běží ještě starý kód se starou konstantou `VERSION`;
  plugin teď porovnává verzi na disku (`checked`) a falešnou nabídku smaže.
  Souhrn pro správu nehlásí aktualizaci na stejnou ani starší verzi.
- Správa nenabízí „Aktualizovat", když nabízená verze není novější než
  nainstalovaná (i u webů se starším pluginem).
- **„Nesledovat" aktualizace pluginu** — záložka Pluginy, u pluginu s čekající
  aktualizací (typicky placený bez licence). Plugin zůstává v seznamu,
  ale nepočítá se do čekajících aktualizací (záložka, seznam webů,
  dashboard, alert, ranní souhrn, report) a nenabízí se k aktualizaci;
  „Sledovat" to vrátí. Příznak přežije načítání dat z webu; MEDIAGRAFIK
  Monitor přestat sledovat nejde. Počet čekajících aktualizací v datech
  webu se teď počítá ve správě (`SnapshotImporter::recountUpdates()`).
- Migrace `2026_09_23_000002_plugin_updates_ignored` (`site_plugins.updates_ignored`).

## 0.4.0 — 23. 9. 2026

- **Přihlášení do wp-admin jedním klikem** — tlačítko „wp-admin" v hlavičce
  webu si od pluginu vyžádá jednorázový odkaz (platí minutu, jednou) a
  přihlásí správcovský účet studia bez hesla. Hesla se nikde neukládají.
  Účet studia se nastaví v Nastavení → Monitoring, u webu jde přepsat
  („Přihlašovat jako"). Bez účtu nebo se starším pluginem tlačítko dál
  jen otevře přihlášení.
- **Plugin MEDIAGRAFIK Monitor 1.3.0** — `POST /actions/login-link`,
  přihlášení přes `?mg_login=<token>` (token jen jako SHA-256 otisk,
  jen účet s `manage_options`). Na webu jde vypnout v Nastavení →
  MEDIAGRAFIK Monitor; výchozí stav je zapnuto.
- **Ikona webu v seznamech** místo koleček s iniciálami — favicona stažená
  z webu (`<link rel="icon">` / `apple-touch-icon` z úvodní stránky, jinak
  `/favicon.ico`; výchozí logo WordPressu se nebere), obnova jednou týdně
  v cronu po malých dávkách. V Nastavení webu jde nahrát vlastní logo
  (má přednost), stáhnout faviconu znovu nebo ikonu odebrat. Bez ikony
  zůstávají iniciály. SVG se nebere (bezpečnost).
- **Sekce reportu „Obsah webu"** — typy obsahu (příspěvky, stránky…) s počtem
  publikovaných a tím, kdy naposledy něco přibylo (tečka zelená / žlutá /
  červená, stejné měřítko jako záložka Obsah: 30 a 90 dní, `ContentFreshness`).
  Když nejčerstvější obsah zežloutne nebo zčervená, přidá se výzva ke
  spolupráci s tlačítkem „Ozvěte se nám". Výchozí zapnutá; u webů s už
  uloženým výběrem sekcí je potřeba ji jednou zapnout v náhledu reportu.
- Rozpracovaný report (koncept, čeká na schválení) si při uložení sekcí
  nebo poznámky přepočítá souhrn z aktuálních dat — ukáže i servis
  a aktualizace zapsané po jeho vzniku a nové sekce. Odeslaný report se
  nemění.
- Sekce v náhledu reportu se ukládají tlačítkem, přepnutí stránku nenačítá,
  ale sekci v náhledu hned ukáže/schová (náhled rozpracovaného reportu má
  vykreslené všechny sekce, vypnuté skryté; e-mail klientovi jen zapnuté).
  Po změně se tlačítko zvýrazní („Uložit změny sekcí").
- Oprava: názvy příspěvků z WordPressu se v reportu i na záložce Obsah
  vypisovaly s HTML entitami (`&#8222;`, `&nbsp;`) — dekódují se při
  výpočtu souhrnu i při vykreslení (i u dřív uložených souhrnů).
- Náhled rozpracovaného reportu se počítá vždy z aktuálních dat a se stejným
  souhrnem report i odchází — co je v náhledu, to klient dostane.
- Přepínače sekcí v náhledu jsou seřazené stejně jako sekce v e-mailu.
- Krokovací pole (Nastavení → Monitoring, Alerty a prahy) mají jednotku za
  číslem jako v návrhu („30 dní", „48 h"; počet aktualizací bez jednotky,
  je zřejmý); jeden helper `get_stepper()` místo dvou zápisů v šablonách.
- Segmenty (filtry, přepínače) se řídí délkou obsahu a nezalamují text;
  filtry s tečkou a počtem mají víc místa po stranách.
- Oprava: odkaz stylovaný jako tlačítko (`a.btn`) při najetí myší měnil
  barvu textu na barvu odkazu (nečitelné na modrém tlačítku) a podtrhl se.
- Oprava: atribut `hidden` nepřebíjely třídy s vlastním `display` (`.btn`).
- Oprava: po přepnutí segmentů (frekvence, stav, zabezpečení pošty…),
  druhu servisu nebo výběru webů u klienta svítily dvě položky a vypnutý
  přepínač (toggle) dál vypadal zapnutě — stav teď kreslí jen CSS podle
  zaškrtnutí. Přepínač motivu v nabídce účtu zvýraznění přesouvá.
- Oprava: odkazy filtrů (segmenty) na dashboardu, v seznamu webů, alertech
  a reportech nic nefiltrovaly — skládání adresy nechávalo původní stav
  (`[…] + $override` místo `array_merge`).
- Oprava: přepínače sekcí v náhledu reportu posílaly všechny hodnotu „1",
  takže se sekce uložily jako vypnuté (i jako výchozí pro další reporty
  webu). `get_toggle()` má nově parametr `$value`; sekce se čtou z požadavku.
- Oprava testů: přihlášení v testech nezmizí, když test běží jako první
  se session.
- Migrace `2026_09_23_000000_site_login_user` (sloupec `wp_login_user` v `sites`)
  a `2026_09_23_000001_site_icon` (`icon`, `icon_source`, `icon_checked_at`).

## 0.3.0 — 22. 9. 2026

- **Aktualizace WordPressu ze správy** — Přehled webu → „Aktualizovat" u verze
  WordPressu → potvrzovací stránka (hlavní / opravná verze, stáří poslední
  zálohy). Potvrzuje se konkrétní verze; nabízí-li web mezitím jinou,
  aktualizace se odmítne.
- **Mazání neaktivních pluginů ze správy** — záložka Pluginy → „Smazat" →
  potvrzovací stránka. Aktivní pluginy a MEDIAGRAFIK Monitor smazat nejde.
- **Plugin MEDIAGRAFIK Monitor 1.2.0** — `POST /actions/plugin-delete`
  (`delete_plugins()` včetně odinstalace) a `POST /actions/core-update`
  (`Core_Upgrader`, kontrola PHP a MySQL). Jeden zámek pro všechny akce.
- Akce na webu mají vlastní `SiteActionController`; co je kdy dovolené,
  rozhoduje `SiteActions` (verze pluginu na webu pro každou akci zvlášť).
- **Servis: checklist úkolů** — Nastavení → Servis: seznam úkolů ke každému
  druhu servisu (úkol na řádek). V zápisu servisu se z něj stane checklist
  k odškrtání; zápis si nese kopii seznamu, pozdější změna ho nepřepíše.
  Historie servisů ukazuje „3 z 4 úkolů".
- **Úprava zapsaného servisu** — odkaz „Upravit" v historii servisů: text,
  checklist, datum, čas, druh i stav. Termín plánu se úpravou neposouvá.
- **Změna adresy webu** — Nastavení webu → „Adresa webu" (web na www, stěhování
  na jinou doménu). Web zůstává týž záznam: historie, uptime, servis,
  reporty i API klíč zůstanou; certifikát a registrace domény se ověří
  znovu. Změna se zapíše do historie webu i auditu.
- Testy s přihlášeným uživatelem sdílejí `dev/tests/fixtures/logged-in-kernel.php`.
- Migrace `2026_09_22_000000_service_checklist` (sloupce `checklist`
  a `updated_at` v `service_logs`) — spustí se sama při prvním požadavku.

## 0.2.0 — 22. 9. 2026

- **Aktualizace pluginů ze správy** — záložka Pluginy: výběr zaškrtávátky
  a „Aktualizovat vybrané", nebo „Aktualizovat" v řádku (funguje i bez
  JavaScriptu). Po akci se hned načtou čerstvá data; výsledek v Historii
  webu i v auditu. Posílají se jen pluginy, u kterých web hlásí novou verzi.
- **Plugin MEDIAGRAFIK Monitor 1.1.0** — `POST /actions/plugin-update`
  s podpisem (HMAC, okno ±5 minut), aktualizace přes `Plugin_Upgrader`
  jako ve wp-admin, zámek proti souběhu, nejvýš 10 pluginů najednou.
  Sám sebe umí aktualizovat hned na pokyn správy (bez 12hodinové cache).
- Oprava: ZIP pluginu z Windows PowerShellu 5.1 měl cesty se zpětným
  lomítkem a WordPress plugin rozbalil o úroveň hlouběji.

## 0.1.0 — 19. 9. 2026 (první verze, zatím nenasazená)

Aplikace „Správa webů" pro studio MEDIAGRAFIK: evidence klientských
WordPress webů, monitoring, alerty, servisní plán a klientské reporty.
Jádro a přihlašování převzaté ze `sprava-instanci`.

- **Weby a klienti** — evidence, detail se sedmi záložkami (Přehled,
  Pluginy, Obsah, Zabezpečení, Servis, Reporty, Nastavení), klienti s
  kontakty a načtením údajů z ARES.
- **Plugin MEDIAGRAFIK Monitor 1.0.0** (`wp-plugin/`) — REST endpointy
  ping/summary/security s API klíčem, sběr dat o WordPressu, pluginech,
  obsahu, zálohách a zabezpečení; aktualizace pluginu servíruje aplikace.
- **Monitoring** — dostupnost (curl_multi), SSL, doména (RDAP), data z
  pluginu; cron endpoint s tokenem, časový rozpočet, zámek.
- **Alerty** — pravidla výpadek / SSL / PHP EOL / plugin neodpovídá /
  aktualizace / stará záloha / servis po termínu / doména; e-mail + push,
  stránka Alerty s hromadnými akcemi, prahy v Nastavení.
- **Servis** — plán (druh, opakování, první termín), nadcházející termíny,
  historie provedených servisů, posun o týden, badge v záložce.
- **Reporty** — nastavení per web (frekvence, den, hodina, adresáti,
  schvalování), fronta napříč weby, náhled s poznámkou a sekcemi, zkušební
  odeslání, sledovací pixel, uložené HTML; e-mail podle návrhu.
- **Dashboard** — hero s weby vyžadujícími zásah, metriky, tabulka
  „Vyžaduje řešení" se segmenty, klientem a hledáním, stavy prázdný /
  načítání / monitor neběží.
- Nástroje: `build.ps1`, `build-plugin.ps1`, `import-sites.php`,
  `monitor-cron.php`, `create-admin.php`, `sync-design.php`.
