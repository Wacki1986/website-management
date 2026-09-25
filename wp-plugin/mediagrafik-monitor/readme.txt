=== MEDIAGRAFIK Monitor ===
Contributors: mediagrafik
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPLv2 or later

Napojení webu na Správu webů studia MEDIAGRAFIK.

== Description ==

Plugin vystavuje REST endpointy `/wp-json/mediagrafik-monitor/v1/{ping,summary,security}`
chráněné API klíčem v hlavičce `X-MG-Key`. Klíč vydává Správa webů; plugin ukládá jen jeho
SHA-256 hash. Plugin nic neposílá, nemá cron ani e-maily — hub se ptá sám.

Na pokyn Správy webů (podepsaný požadavek) umí aktualizovat pluginy, smazat neaktivní pluginy
a aktualizovat WordPress — stejnou cestou jako wp-admin. Umí i přihlášení do administrace
jedním klikem ze Správy webů (jednorázový odkaz, bez hesla; jde vypnout v nastavení pluginu).

Když má Správa webů u webu zapnutý modul SEO, posílá plugin navíc skóre stránek z Rank Math
nebo Yoast SEO a základní SEO kontroly (viditelnost pro vyhledávače, mapa webu).

== Installation ==

1. Pluginy → Nahrát plugin → ZIP → Aktivovat.
2. Po aktivaci se otevře Nastavení → MEDIAGRAFIK Monitor → vložit API klíč ze Správy webů (detail webu → Nastavení).
3. Ve Správě webů kliknout na „Zkontrolovat teď".

== Changelog ==

= 1.7.0 =
* Modul SEO: pokrytí metadat (klíčové slovo, meta popis, obrázek pro sdílení, alt text obrázků v knihovně médií), až 200 slabě hodnocených stránek s klíčovým slovem a tím, co jim chybí (klíčové slovo, meta popis, krátký text, alt u obrázků v textu).
* Nefunkční odkazy za 7 dní a počet aktivních přesměrování — z modulů 404 Monitor a Přesměrování v Rank Math, nebo z pluginu Redirection.

= 1.6.0 =
* Modul SEO pro Správu webů: na vyžádání posílá skóre stránek z Rank Math nebo Yoast SEO (průměr; dobré, průměrné a špatné; nejhůř hodnocené stránky), stránky bez klíčového slova, bez meta popisu a s noindex, jestli web není skrytý před vyhledávači a jestli má mapu webu.

= 1.5.5 =
* Ve výpisu pluginů odkaz „Nastavení" (vedle Deaktivovat); po první aktivaci (bez uloženého klíče) přesměruje rovnou do nastavení.
* Oprava: hláška „Klíč je uložený" se po uložení ukazovala dvakrát.

= 1.5.4 =
* U nabízené aktualizace posílá, jestli k ní WordPress má balíček ke stažení — správa pak nenabízí aktualizaci placeného pluginu bez licence.

= 1.5.3 =
* U každého pluginu posílá, odkud se aktualizuje (wordpress.org / vlastní updater autora / Update URI) — správa pak nehodnotí placené pluginy se stejným slugem jako stažený plugin z adresáře (WPML).

= 1.5.2 =
* Aktualizace placených pluginů: když WordPress po obnovení seznamu nabídku „zapomene“ (Rank Math PRO a spol. mimo wp-admin), použije se nabídka, kterou web ukazoval — jako tlačítko ve wp-admin. Místo falešného „aktuální“ vrací důvod selhání.

= 1.5.1 =
* Typy obsahu: kromě veřejných i vlastní typy s vlastní položkou v menu wp-admin (např. Reference, Kurzy registrované jako neveřejné).

= 1.5.0 =
* Aktivace a deaktivace pluginů ze Správy webů (MEDIAGRAFIK Monitor sám sebe vypnout nedovolí).

= 1.4.0 =
* Aktualizace placených a vlastních pluginů z knihovny Správy webů (web se prokazuje otiskem API klíče, ZIP má podpis jen pro daný web).

= 1.3.1 =
* Oprava: po aktualizaci pluginu se nabízela „aktualizace" na právě nainstalovanou verzi (porovnávala se verze starého kódu v paměti, ne verze na disku).
* Souhrn pro Správu webů nehlásí aktualizaci na stejnou nebo starší verzi.

= 1.3.0 =
* Přihlášení do administrace jedním klikem ze Správy webů (jednorázový odkaz na minutu, jen správcovský účet, jde vypnout).

= 1.2.0 =
* Smazání neaktivních pluginů a aktualizace WordPressu ze Správy webů.

= 1.1.0 =
* Aktualizace pluginů ze Správy webů (podepsaný požadavek, nejvýš 10 pluginů najednou).

= 1.0.0 =
* První verze: ping, summary (verze, pluginy, obsah, záloha), security (bezpečnostní plugin, 2FA, wp-content, slug přihlášení), aktualizace z hubu.
