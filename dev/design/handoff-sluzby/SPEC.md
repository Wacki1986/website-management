# SPEC.md — záložka Služby (Detail webu)

## Účel

Na jednom místě vidět, které služby na webu běží, a hlídat jednorázové kroky po
spuštění webu.

## Obsah obrazovky

**1. Nasazené služby** — jedna karta, tři skupiny vedle sebe (na mobilu pod sebou):

| Skupina | Služby |
| --- | --- |
| Obecná nastavení | Google Tag Manager, Google Search Console, Vlastní SMTP |
| Statistiky | Google Analytics, Matomo, Analytics WP |
| SEO | Colabim, Rank Tracker |

Každá služba je jen zaškrtávátko s názvem. V hlavičce karty počet „N z M nasazeno“.

**2. Procesy po spuštění** — rozbalovací skupiny úkolů:

| Skupina | Úkoly |
| --- | --- |
| SEO základ | Rank Math nasazen a nastaven · Sitemap odeslána do Search Console · Přesměrování ze staré verze webu · Titulky a meta popisky stránek · Kontrola zapnutí indexace webu · Kontrola funkčnosti formulářů |
| Právní minimum | Cookie lišta nasazena · Stránka Zásady ochrany osobních údajů · Odkaz na zásady v patičce a u formulářů |
| Optimalizace | WP Rocket |
| Technika | Website Auditor – kontroly |

V hlavičce karty celkový průběh (pruh + „X / Y“) a datum spuštění webu.

## Data

```
Site          … + launchedAt
SiteService   siteId, key, enabled
              key ∈ gtm | gsc | smtp | ga | matomo | analyticswp | colabim | ranktracker
LaunchTask    siteId, key, group, done, doneBy (userId), doneAt
              group ∈ seo | pravni | optimalizace | technika
```

Seznam služeb a úkolů (názvy, skupiny, pořadí) je zatím pevný číselník v aplikaci,
ne nastavení per web. Nový web dostane všechny služby `enabled=false` a všechny
úkoly `done=false`.

## Chování

- Klik kamkoli na řádek služby / úkolu přepne stav a **hned uloží** (bez tlačítka Uložit).
- Odškrtnutí úkolu zapíše `doneBy` = přihlášený uživatel, `doneAt` = dnes; zobrazí se
  jako „Jan N. · 14. 10. 2026“. Zrušení zaškrtnutí `doneBy` / `doneAt` smaže.
- Skupina ukazuje „X / Y“; když je vše hotovo, „Hotovo“ zeleně.
- Rozbalení skupin se neukládá. V náhledu je rozbalené Právní minimum.
- Stav služeb může řídit, co se ukazuje v jiných záložkách (např. data ze Search
  Console jen při `gsc=true`).

## Mimo rozsah

Ověření, že služba na webu opravdu běží (detekce přes plugin), správa číselníku služeb
a úkolů v Nastavení aplikace, historie změn.
