# ZMENY.md — záložka Služby

Datum předávky: 23. 10. 2026. Navazuje na balíček `handoff/` z 18. 9. 2026.

## Nová obrazovka

- `html/detail-webu-sluzby.html` (+ `-dark`) — nová záložka **Služby** v Detailu webu,
  mezi Reporty a Nastavení.
- Mobil nemá vlastní soubor: struktura je stejná, jen se mřížka služeb pod 768 px
  skládá do jednoho sloupce (řeší CSS).

## Navigace záložek

Pořadí záložek je teď: Přehled · Pluginy · Obsah · Zabezpečení · **SEO** · Servis ·
Reporty · **Služby** · Nastavení. Záložka SEO je v prototypu nová a v hlavním balíčku
zatím nemá vlastní HTML. Ostatní soubory v `handoff/html/` mají v navigaci ještě
původních 7 záložek — při implementaci použít pořadí výše.

## Nové bloky ve stylech (`components/_services.scss`)

Přidané, nic nepřejmenované:

| Blok | Použití |
| --- | --- |
| `.service-grid` (`__group`, `__label`) | skupiny služeb ve 3 sloupcích |
| `.service` (`--on`) | řádek služby: zaškrtávátko + název |
| `.progress` (`__bar`, `__value`, `__count`) | průběh checklistů, šířka přes `--progress` |
| `.launch-list` (`__group`, `__summary`, `__chevron`, `__title`, `__body`, `__item`, `__label`, `__meta`, `__item--done`) | rozbalovací checklisty po spuštění |

Znovu použité: `.card`, `.card__header`, `.card__title`, `.card__note`, `.badge`,
`.badge--ok`, `.checkbox` + `.checkbox--checked` + `.checkbox__mark`, `.stack`.

`.launch-list` ≠ `.check-list`: `.check-list` je seznam zjištění s barevnou tečkou
(Zabezpečení), `.launch-list` jsou odškrtávané úkoly.

## Poznámky k markupu

- Rozbalování je nativní `<details>` / `<summary>`, nepotřebuje JS.
- Řádky služeb a úkolů jsou `<button role="checkbox" aria-checked>`; přepnutí =
  změna `aria-checked` + modifikátorů `--on` / `--done` / `checkbox--checked`.
- Jediný inline styl je `style="--progress:45%"` na `.progress` (hodnota z dat).
