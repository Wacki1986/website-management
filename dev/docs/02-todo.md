# Budoucí TO DO

Věci, které se dělat budou, ale teď ne. Nahoře to, co je nejblíž.
Hotové položky se odsud mažou (záznam zůstává v CHANGELOGu).

## Modul Rychlost (PageSpeed Insights)

Další modul do `Modules::REGISTRY` (vzor: modul SEO, 0.8.0). Google
PageSpeed Insights API v5 je zdarma, stačí API klíč z Google Cloud
(limit 25 000 měření denně) — uložit přes `Settings::setSecret()`
v Nastavení → Moduly.

- Jedno měření vrátí skóre výkonu 0–100 (mobil i počítač), laboratorní
  metriky (LCP, CLS, TBT, odezva serveru) a zdarma i Lighthouse skóre
  SEO, přístupnosti a osvědčených postupů. Skutečná data návštěvníků
  (Core Web Vitals z Chrome) jen u webů s dostatečnou návštěvností —
  u malých webů chybí, počítat s tím v UI.
- Měření trvá 10–30 s → krok cronu po jednom webu, jednou týdně
  (`MonitorRun::addStep`), plus tlačítko „Změřit teď". Měří hub, ne plugin.
- Historie po týdnech (vzor `seo_days`), sloupec v seznamu webů, sekce
  v reportu („rychlost webu 92/100, o 5 lépe"), alert při propadu.

## Modul Search Console

Kliky, zobrazení a průměrná pozice z Google Search Console. Vyžaduje
OAuth (propojení Google účtu studia) a weby přidané v Search Console.
Nejvíc práce ze všech modulů — až po modulu Rychlost.

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
