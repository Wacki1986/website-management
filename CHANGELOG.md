# Změny

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
