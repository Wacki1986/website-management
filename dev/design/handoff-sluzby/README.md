# Správa webů — záložka Služby (samostatný balíček)

Cesty jsou relativní ke kořeni balíčku (`handoff-sluzby/`). Balíček je jednorázový
snímek prototypu, nesynchronizuje se.

Obsahuje **celé** `scss/` a `dist/app.css` z hlavního balíčku `handoff/`
rozšířené o jeden nový partial. Lze ho nasadit samostatně, nebo z něj převzít jen
`scss/components/_services.scss` + řádek `@use "components/services";` v `main.scss`.

```
html/detail-webu-sluzby.html        záložka Služby (light)
html/detail-webu-sluzby-dark.html   totéž s data-theme="dark"
scss/components/_services.scss      nové bloky této záložky
scss/main.scss                      + @use "components/services"
dist/app.css                        zkompilovaný výstup vč. nových rules
SPEC.md                             co záložka dělá, data, chování
STAVY.md                            stav → třídy → kdy nastane
ZMENY.md                            co je nové proti handoff/
```

Build: `npx sass scss/main.scss dist/app.css --style=compressed --no-source-map`

Konvence jsou stejné jako v `handoff/README.md`: BEM, tokeny přes custom properties,
ikony jako masky, breakpointy jen 768 px a 1100 px. Odkazy v bočním menu a záložkách
vedou na `#` — ostatní obrazovky jsou v hlavním balíčku.
