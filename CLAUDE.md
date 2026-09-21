# Pravidla projektu — Správa webů (MEDIAGRAFIK)

Správce projektu je poučený laik (15 let zkušeností s WordPressem) — kód se
píše tak, aby byl čitelný na první pohled, ne jen správný. Architektura je
převzatá ze správy instancí dispu (`../dispu/sprava-instanci`): Kernel →
Router → Controller → View, žádný framework, žádný Composer. Průvodce čtením
kódu: `../dispu/aplikace/dev/docs/11-jak-cist-kod.md`. Provozní dokumentace:
`dev/docs/00-provoz.md`. Zadání a plán: `dev/docs/01-plan.md`.

## Struktura

- `www/` — jde na server (obsah složky). `app/Core` jádro, `app/Controllers`,
  `app/templates`, `app/helpers` (globální funkce), `database/migrations`.
- `dev/` — nenasazuje se: `scss/` (design balíček + `app/` doplňky), `tests/`,
  `tools/`, `docs/`, `design/` (handoff z Claude Design, nedotýkat se).
- `wp-plugin/mediagrafik-monitor/` — plugin na straně WordPress webu.

## Design

- BEM třídy z `dev/design` jsou **finální**. `dev/scss` = balíček designu
  přenesený 1:1 + `dev/scss/app/*` pro to, co návrh nemá (přihlášení, toasty,
  skutečné formulářové prvky). Do partialů designu se nepíše — po nové
  předávce se přepíšou celé.
- Stav se nikdy nesděluje jen barvou: tečka + text (`get_status()`),
  viz `dev/design/STAVY.md`.
- Ikony: `get_icon('shield', 'icon--sm icon--warning')`, ne inline masky.
- Záložky jsou odkazy na vlastní URL, ne JS přepínání.

## Šablony (`www/app/templates/`)

- Kostra každé šablony: docblock s `@var` seznamem všech proměnných, pak
  `$this->extend(...)`, pak už jen markup.
- **Šablona nic nepočítá.** Třídění, seskupování a příprava dat patří do
  controlleru; šablona dostává hotové struktury a jen je vypisuje.
- **Žádný `ob_start()`/`ob_get_clean()` v šablonách.**
- **Slučování PHP bloků:** tři a víc čistě-PHP řádků za sebou patří do
  jednoho `<?php ... ?>` bloku.
- Helpery: `render_*` tiskne a vrací void, `get_*` vrací řetězec — nikdy
  obojí. `<?=` jen pro výrazy vracející text; `render_*` se volá v `<?php ?>`.
- Escapování dělají helpery uvnitř; parametry přijímající hotové HTML to
  mají napsané v docblocku.

## JavaScript

- Všechno funguje bez skriptu (obyčejné GET/POST, `<details>`, odkazy);
  skript přidává pohodlí. Moduly v `www/assets/js/modules/`, import mapu
  verzuje `Kernel::jsImportMap()`.

## Styl

- Komentáře česky; vysvětlují **proč**, ne co dělá další řádek.
- Proměnné a metody camelCase, globální helpery snake_case — záměrné.
- Migrace jen přibývají (do prvního nasazení se smí doplňovat baseline).
- Tajemství (API klíče webů, SMTP heslo, token cronu) vždy přes
  `Secrets`/`Settings::setSecret()`, nikdy v otevřené podobě.

## Vývoj

```powershell
pwsh dev/tools/build.ps1                                  # SCSS + php -l
php dev/tests/run.php                                     # testy (DB sprava_webu_test)
php -S 127.0.0.1:8099 -t www dev/tools/serve.php     # vývojový server
```
