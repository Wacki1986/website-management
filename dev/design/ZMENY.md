# ZMENY.md — co je v balíčku nové

Datum předávky: 18. 9. 2026. Balíček nahrazuje verzi, kde byla jen jedna ukázková
obrazovka (`detail-webu-zabezpeceni.html`).

Rozcestník: **`html/index.html`** — 45 statických obrazovek, boční menu je mezi nimi
prolinkované.

## Nové obrazovky

| Oblast | Soubory |
| --- | --- |
| Dashboard | `dashboard.html` + 6 stavů (`-outage`, `-manyupdates`, `-allgood`, `-loading`, `-empty`, `-apierror`) |
| Weby | `weby.html`, `-seskupeno`, `-prazdno` |
| Alerty | `alerty.html`, `-vyber`, `-vyresene`, `-prazdno` |
| Detail webu | všech 7 záložek `detail-webu-*` + stavy `-allgood`, `-loading`, `-apierror`, `-dark`, `-mobil` |
| Klienti | `klienti.html`, `detail-klienta.html`, `detail-klienta-uprava.html` |
| Nový klient | `novy-klient.html`, `-chyby`, `-ulozeno` |
| Reporty | `reporty-naplanovane`, `-odeslane`, `-problemy` |
| Náhled reportu | `nahled-reportu.html`, `-withnote`, `-allgood`, `-sent`, `-mobil` |
| Nastavení aplikace | `nastaveni-monitoring`, `-alerty`, `-email`, `-uzivatele` |

Varianta vzniká jen tam, kde se mění **struktura** (jiné bloky, prázdný stav,
skeleton, formulář místo čtení), ne barva tečky. Stavy, které se liší jen daty,
jsou u Dashboardu (`outage`, `manyUpdates`) přiloženy navíc, protože je v prototypu
schovává tweak.

Záložky jsou prolinkované jako `<a class="tabs__item">`, aby se balíček dal
proklikat. V aplikaci půjde o `<button>` se stejnými třídami.

## Nové bloky ve stylech

Přidané, nic nepřejmenované:

| Blok | Kde se používá |
| --- | --- |
| `.uptime` (`__grid`, `__day`, `__axis`, `__legend`, `__swatch`) | pásek dostupnosti |
| `.checkbox` (`__mark`, `--checked`, `--indeterminate`) | výběr řádků v tabulkách |
| `.search` | pole hledání v hlavičce karty |
| `.pill`, `.pill-group` (`__note`, `__remove`, `--active`, `--brand`, `--ok`, `--muted`, `--add`) | termíny, adresáti, filtry |
| `.dot` (`--ok`, `--warning`, `--error`, `--muted`) | samostatná stavová tečka |
| `.panel` | zapuštěný blok uvnitř karty |
| `.stepper` (`__btn`, `__value`, `--sm`, `--off`) | číselné pole v Nastavení |
| `.threshold-row` (`__title`) | řádek prahu alertu |
| `.bulk-bar` (`__note`, `__actions`) | hromadné akce nad tabulkou |
| `.hero` (`__top`, `__label`, `__item`, `__calm`, `__footer`) | hlavní karta dashboardu |
| `.metric-list`, `.metric-row` (`__label`, `__sub`, `__value`) | sloupec metrik na dashboardu |
| `.pick` (`__item`, `--active`) | výběr webů u nového klienta |
| `.task-list` (`__item`, `__mark`, `--done`) | kontrolní seznam povinných údajů |
| `.email-paper` (`__sheet`, `--mobile`) | plátno náhledu klientského e-mailu |
| `.hero-grid` | mřížka hero + metriky |

Nové modifikátory existujících bloků: `.table--history`, `--service`, `--sites`,
`--alerts`, `--users`, `--clients`, `--client-sites`, `--report-queue`, `--dashboard`,
`.table__row--error`, `--top`, `.table__group` (+`--caps`), `.btn--inverse`,
`--ghost-inverse`, `--active`, `--icon`, `.btn--menu`, `.score-card--quiet`,
`.card--danger`, `--scroll-x`, `.card__header--filters`, `.card__footer--muted`,
`.alert--inverse`, `--row`, `--ok`, `.alert__dot`, `.alert__actions`,
`.metric--error`, `--compact`, `.form__control--select`, `--mono`, `--inline`,
`--error`, `.form__value`, `.form__label--caps`, `.form__label-optional`,
`.segmented__dot`, `.segmented__count`, `.badge--brand`, `.icon--brand`,
utility `.u-mono`, `.text-faint`, `.text-warning`, `.text-error`.

## Opravy

- `dist/app.css` obsahoval rozbitý komentář před blokem `:root` s ikonami — parser
  kvůli němu celý blok zahodil a **žádná ikona se nevykreslila**. Opraveno.
- V dark módu se invertuje logo v bočním menu
  (`[data-theme="dark"] .sidebar__logo img`), jinak je tmavý wordmark nečitelný.

## Struktura stylů

Nové partialy `components/_uptime`, `_input`, `_list`, `_settings`, `_pick`,
`_hero`, `_email` (zapojené v `main.scss`). Doplňky u stávajících bloků jsou na
konci příslušných partialů označené komentářem `// doplněno`. `dist/app.css` je
dopsaný o stejné rules — po prvním `sass` buildu se přegeneruje celý.

Široké tabulky (Dashboard, Weby, Alerty, Reporty, Klienti) mají nad 768 px
`min-width` a jejich karta `.card--scroll-x`: v úzkém okně se posouvají vodorovně
místo drcení sloupců.

Uvnitř `.email-paper__sheet` jsou **záměrně inline styly a natvrdo psané barvy** —
e-mail se renderuje v cizím klientovi, kde tokeny ani třídy neplatí. Je to jediné
místo v balíčku, kde se konvence neuplatňuje.

## Co balíček neobsahuje

JS chování (přepínání záložek a filtrů, výběr v tabulkách, kontextová menu, modály),
skutečná data a routing. Kontextové menu na konci řádků je jen tlačítko
`.btn--menu`, rozbalená nabídka v balíčku není.
