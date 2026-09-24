# Budoucí TO DO

Věci, které se dělat budou, ale teď ne. Nahoře to, co je nejblíž.
Hotové položky se odsud mažou (záznam zůstává v CHANGELOGu).

## Bezpečnostní aktualizace pluginů

Záložka Pluginy ukazuje „0 bezpečnostní" vždycky — WordPress u aktualizace
neřekne, jestli opravuje zranitelnost. Zjistit se to dá jen porovnáním
verzí pluginů s databází známých zranitelností:

- **WPScan** (wpscan.com/api) — API klíč, zdarma omezený počet dotazů denně.
- **Patchstack** (patchstack.com) — API pro partnery/agentury.
- **Wordfence Intelligence** (wordfence.com/threat-intel) — veřejný feed
  zranitelností ke stažení (bez dotazu po jednom pluginu).

Co by to dalo: štítek „bezpečnostní" u aktualizace, číslo v metrice,
alert „plugin X má známou zranitelnost — aktualizujte hned" a řádek
v reportu. Kde navázat: `site_snapshots.security_updates` (dnes vždy 0),
`SnapshotImporter::recountUpdates()`, pravidla v `AlertEngine`.

Nejdřív: vybrat službu (limity, cena, podmínky pro agenturu).

## Dvoufázové přihlášení do Správy webů

Zatím nahrazeno Basic auth na hostingu (kapitola 2 v `00-provoz.md`).
Kdyby se správa otevírala dalším lidem, TOTP (aplikace Authenticator)
u účtu v Nastavení → Uživatelé.
