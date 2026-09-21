# STAVY.md — stav → třídy → kdy nastane

Stav se nikdy nesděluje jen barvou: vždy tečka **a** text.

## Stav webu (hlavička obrazovky)

| Stav | Třídy | Kdy |
| --- | --- | --- |
| V pořádku | `.status.status--ok` + `.status__dot` | poslední kontrola OK |
| Nedostupný | `.status.status--error` | 3 kontroly za sebou selhaly |
| Neznámý | `.status.status--error` + `.alert.alert--row` nad obsahem | plugin neodpovídá (apiError) |

## Stav obrazovky (v prototypu tweak `screenState`)

| Stav | Co se mění ve struktuře | Soubor |
| --- | --- | --- |
| `default` | `.alert.alert--inverse` v bočním panelu | `detail-webu-prehled.html` |
| `allGood` | místo alertu `.score-card--ok.score-card--quiet`; sidebar bez `.sidebar__count--alert` | `…-prehled-allgood.html` |
| `loading` | `.skeleton` bloky místo `.metric` a `.uptime` | `…-prehled-loading.html` |
| `apiError` | `.alert.alert--row` nad obsahem, `.dot--error` u monitoru v sidebaru | `…-prehled-apierror.html` |

## Řádek tabulky

| Stav | Třídy | Kdy |
| --- | --- | --- |
| běžný | `.table__row` | výchozí |
| rizikový | `.table__row.table__row--error` | neaktivní plugin (doporučeno smazat) |
| vybraný | `.checkbox.checkbox--checked` v prvním sloupci | řádek je ve výběru |
| částečný výběr | `.checkbox.checkbox--indeterminate` v `.table__head` | vybraná část řádků |

## Zabezpečení

| Stav | Třídy | Kdy |
| --- | --- | --- |
| Nasazeno | `.status--ok`, `.icon--subtle` | opatření platí |
| S výhradou | `.status--warning`, `.icon--warning`, `.check-list__item--warning` | platí, ale zastaralé / částečné |
| Chybí | `.status--error`, `.icon--error`, `.check-list__item--error` | opatření není nasazeno |
| Skóre | `.score-card--error` / `--warning` / `--ok` | podle počtu chybějících a částečných |
| Nic k dořešení | `.empty` místo `.check-list` | žádné chybějící ani částečné opatření |

## Přepínače a volby

| Stav | Třídy |
| --- | --- |
| zapnuto / vypnuto | `.toggle.toggle--on` / `.toggle` |
| aktivní segment | `.segmented__item.segmented__item--active` |
| zvolený druh servisu | `.choice__item.choice__item--active` |
| nejbližší termín | `.pill.pill--active`; po termínu `.pill--error` |
| neuložené změny | text `.text-subtle` → `color:var(--color-status-warning-text)`, tlačítko `.btn--primary` |

## Uptime pásek

| Den | Třída |
| --- | --- |
| 100 % | `.uptime__day` |
| výpadek do 30 min | `.uptime__day--warning` |
| výpadek nad 30 min | `.uptime__day--error` |

## Seznamy a fronty

| Stav | Třídy | Kdy |
| --- | --- | --- |
| skupina v tabulce | `.table__group` (Weby), `.table__group--caps` (Alerty, Reporty) | seskupení podle klienta / dne / fáze |
| kritický řádek | `.table__row.table__row--error` | web mimo provoz, nedoručený report, otevřený kritický alert |
| ignorovaný alert | `.alert-row--muted` | alert byl ignorován |
| vybrané řádky | `.bulk-bar` nad tabulkou + `.checkbox--checked` | uživatel označil alespoň jeden řádek |
| prázdný výsledek | `.empty` místo řádků | filtr nic nevrátil |
| načítání | `.skeleton` řádky | data ještě nedorazila |

## Formuláře

| Stav | Třídy | Kdy |
| --- | --- | --- |
| čtení | `.form__value` | detail klienta mimo režim úprav |
| úpravy | `.form__control` | po kliknutí na Upravit údaje |
| chyba pole | `.form__control--error` + `.form__error` | po odeslání s nevyplněným povinným polem |
| souhrnná chyba | `.alert.alert--row` nad formulářem | jedna a více chyb |
| úspěch | `.alert.alert--row.alert--ok` | po uložení |
| splněný krok | `.task-list__item--done` | povinný údaj je vyplněn |
| vypnuté pravidlo | `.stepper--off` + `.toggle` bez `--on` | práh alertu je vypnutý |

## Motiv

`data-theme="light"` / `data-theme="dark"` na `<html>`. Komponenty nemají natvrdo
psané barvy, mění se jen hodnoty custom properties.
