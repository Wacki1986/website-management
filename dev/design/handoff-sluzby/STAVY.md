# STAVY.md — záložka Služby

Stav se nesděluje jen barvou: zaškrtávátko má vždy i tvar (fajfka).

## Služba

| Stav | Třídy | Kdy |
| --- | --- | --- |
| nasazeno | `.service.service--on` + `.checkbox--checked`, `aria-checked="true"` | služba na webu běží |
| nenasazeno | `.service` + `.checkbox`, `aria-checked="false"` | služba na webu není; název je šedivější, bez podpisku |

Počet v hlavičce karty: `.badge` „N z M nasazeno“.

## Checklist po spuštění

| Stav | Třídy | Kdy |
| --- | --- | --- |
| úkol hotový | `.launch-list__item--done` + `.checkbox--checked` + `.launch-list__meta` („Jméno · datum“) | někdo úkol odškrtl |
| úkol otevřený | `.launch-list__item` bez `--done`, bez `__meta` | výchozí |
| skupina rozpracovaná | `.badge` „X / Y“ v `__summary` | aspoň jeden úkol skupiny není hotový |
| skupina hotová | `.badge.badge--ok` „Hotovo“ | všechny úkoly skupiny hotové |
| rozbaleno / sbaleno | `<details open>` / bez `open`; šipka `__chevron` se otočí | klik na `__summary` |

## Průběh celkem

| Stav | Co se mění | Kdy |
| --- | --- | --- |
| rozpracováno | `--progress` = hotové / všechny, `__count` „X / Y“, poznámka „Zbývá N kroků · web spuštěn D. M. RRRR“ | výchozí |
| vše hotovo | `--progress:100%`, poznámka „Vše po spuštění je hotové.“ | všechny úkoly hotové |

## Motiv

`data-theme="light"` / `"dark"` na `<html>`; ukázka v `detail-webu-sluzby-dark.html`.
