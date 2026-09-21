=== MEDIAGRAFIK Monitor ===
Contributors: mediagrafik
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Napojení webu na Správu webů studia MEDIAGRAFIK.

== Description ==

Plugin vystavuje REST endpointy `/wp-json/mediagrafik-monitor/v1/{ping,summary,security}`
chráněné API klíčem v hlavičce `X-MG-Key`. Klíč vydává Správa webů; plugin ukládá jen jeho
SHA-256 hash. Plugin nic neposílá, nemá cron ani e-maily — hub se ptá sám.

== Installation ==

1. Pluginy → Nahrát plugin → ZIP → Aktivovat.
2. Nastavení → MEDIAGRAFIK Monitor → vložit API klíč ze Správy webů (detail webu → Nastavení).
3. Ve Správě webů kliknout na „Zkontrolovat teď".

== Changelog ==

= 1.0.0 =
* První verze: ping, summary (verze, pluginy, obsah, záloha), security (bezpečnostní plugin, 2FA, wp-content, slug přihlášení), aktualizace z hubu.
