# Změny

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
