# Správa webů — SCSS balíček

Cesty v tomto souboru jsou relativní ke kořeni balíčku (`handoff/`).

Statický export designu do produkčně použitelné struktury stylů. Vznikl jednorázově
z prototypu (Design Components); **nesynchronizuje se automaticky** — po změnách
v prototypu je potřeba vygenerovat znovu.

## Struktura

```
scss/
  main.scss                 vstupní bod (@use všech partialů)
  abstracts/
    _tokens.scss            SCSS proměnné + CSS custom properties (light + dark)
    _icons.scss             ikony jako CSS masky (data URI)
    _mixins.scss            card-surface, glass-surface, caps-label, truncate, focus-ring
  base/
    _reset.scss             box-sizing, body, odkazy, focus
    _typography.scss        font-face import, nadpisy, textové utility
  layout/
    _app-shell.scss         .app, .split, .stack, .row
    _sidebar.scss           .sidebar
    _page-header.scss       .page-header, .tabs
  components/
    _button.scss            .btn
    _card.scss              .card, .score-card
    _table.scss             .table, .summary-list, .check-list
    _status.scss            .status, .badge, .alert
    _form.scss              .form, .segmented, .choice, .toggle
    _metric.scss            .metric, .metric-grid, .avatar
    _feedback.scss          .empty, .skeleton, keyframes
    _uptime.scss            .uptime
    _input.scss             .checkbox, .search, .pill, .dot, .panel
  utilities/
    _helpers.scss           .icon, u-* utility

dist/app.css                zkompilovaný výstup (pro rychlý náhled)
html/                       45 statických obrazovek s finálními třídami
  index.html                rozcestník přes celý balíček
  dashboard*.html           Dashboard + 6 stavů
  weby*.html                seznam, seskupení, prázdný filtr
  alerty*.html              nevyřešené, výběr, vyřešené, prázdno
  detail-webu-*.html        7 záložek + stavy, dark a mobil
  klienti / detail-klienta / novy-klient*.html
  reporty-*.html, nahled-reportu*.html
  nastaveni-*.html          4 záložky nastavení aplikace
ZMENY.md                    co je nové proti minulé předávce
STAVY.md                    stav → třídy → kdy nastane
```

## Build

```bash
npm i -D sass
npx sass scss/main.scss dist/app.css --style=compressed --no-source-map
```

## Konvence

**BEM.** `.block`, `.block__element`, `.block--modifier`. Modifikátor se nikdy
nepoužívá bez base třídy: `class="btn btn--primary"`.

**Tokeny přes custom properties.** V komponentách se nepíšou hodnoty, jen
`var(--color-…)`, `var(--spacing-…)`, `var(--border-radius-…)`. Díky tomu funguje
dark mode jediným přepnutím `data-theme="dark"` na `<html>`.

**Tabulky jsou grid.** `.table` nastavuje počet sloupců přes lokální proměnnou
`--table-columns`; modifikátory (`.table--security`, `.table--plugins`, …) jen mění
její hodnotu, včetně breakpointů. Nová tabulka = nový modifikátor, ne nové CSS.

**Ikony jsou masky.** `<span class="icon" style="mask-image:var(--icon-shield)">`
dědí barvu z `background-color` (`currentColor` ve výchozím stavu), takže se barví
stejně jako text vedle. Modifikátory `.icon--subtle`, `.icon--warning`, `.icon--error`.

**Semafor.** Stav se nikdy nesděluje jen barvou — vždy tečka + text
(`.status.status--error` + `<span class="status__dot">` + popisek).

**Breakpointy.** 768 px (mobil) a 1100 px (tablet / úzké okno). Jiné se nepoužívají.

## Co balíček neobsahuje

JS chování (přepínání záložek, filtry, modály), skutečná data a routing. HTML soubory
jsou statické snímky obrazovek — referenční markup, ne aplikace. Záložky jsou v nich
prolinkované jako `<a class="tabs__item">`, aby se balíček dal proklikat; v aplikaci
půjde o `<button>` se stejnými třídami.

Kontextová menu na koncích řádků jsou jen tlačítko `.btn--menu` — rozbalená
nabídka v balíčku není.
