# Správa webů — MEDIAGRAFIK

Interní aplikace studia pro správu klientských WordPress webů: eviduje weby
a klienty, hlídá dostupnost, SSL a verze, sbírá data z pluginu **MEDIAGRAFIK
Monitor** na straně webu, generuje alerty, vede servisní plán a historii
a posílá klientům reporty.

| Dokument | K čemu |
|---|---|
| [`dev/docs/01-plan.md`](dev/docs/01-plan.md) | Zadání, architektura, schéma, etapy |
| [`dev/docs/00-provoz.md`](dev/docs/00-provoz.md) | Nasazení, cron, zabezpečení |
| [`dev/design/README.md`](dev/design/README.md) | Design systém (handoff balíček) |
| [`CLAUDE.md`](CLAUDE.md) | Pravidla psaní kódu |

## Požadavky

- PHP 8.1+ s `pdo_mysql`, `mbstring`, `openssl`, `curl`
- MySQL 5.7+ / MariaDB 10.4+
- Apache s `mod_rewrite` a respektovaným `.htaccess`
- Node.js — jen pro build CSS (`npx sass`)
- Hosting s cronem (cPanel), který umí volat adresu přes `wget`

## Struktura

```
repozitář
├─ www/             na FTP jde OBSAH této složky
│  ├─ index.php     vstupní bod (CSP a bezpečnostní hlavičky)
│  ├─ .htaccess     whitelistové routování + šablona IP filtru a Basic auth
│  ├─ assets/       veřejný adresář (css/ je výstup buildu, mimo git)
│  ├─ app/          kód aplikace (Core, Controllers, templates, helpers)
│  ├─ config/       env.php ručně na server, v gitu jen vzor
│  ├─ database/     migrace
│  └─ storage/      logy, relace, ZIP pluginu k distribuci
├─ dev/             NENASAZUJE SE
│  ├─ design/       handoff z Claude Design — nedotýkat se
│  ├─ scss/         design balíček (sync-design.php) + app/ doplňky → www/assets/css/app.css
│  ├─ tools/        build, vývojový server, založení správce, sync designu
│  ├─ tests/        testy (bez závislostí)
│  └─ docs/         plán a provozní příručka
└─ wp-plugin/       plugin MEDIAGRAFIK Monitor pro WordPress
```

## Vývoj

```powershell
# build (SCSS + kontrola syntaxe PHP)
pwsh dev/tools/build.ps1

# testy (databáze sprava_webu_test, root bez hesla; jinak DB_* proměnné)
php dev/tests/run.php

# vývojový server
php -S 127.0.0.1:8099 -t www dev/tools/serve.php

# první účet
php dev/tools/create-admin.php admin 'Silne-heslo-123'

# hromadné přidání webů z CSV (nazev;url;klient;interval;hosting;zalohy), --klice vygeneruje API klíče
php dev/tools/import-sites.php weby.csv --klice

# jeden průchod monitoru z příkazové řádky (jinak HTTP endpoint z cronu)
php dev/tools/monitor-cron.php
```

Konfigurace: zkopírovat `www/config/env.sample.php` na `www/config/env.php`,
ve vývoji nastavit `'environment' => 'development'` a `'mail' => ['transport' => 'log']`
(e-maily se ukládají do `www/storage/logs/*.eml`).

Po nové předávce designu: `php dev/tools/sync-design.php` přenese
`dev/design/scss` do `dev/scss` (a doplní `@use` mixinů, které balíček nemá).

Dokud aplikace není nasazená, přibývají tabulky do baseline migrace. Vývojová
databáze ji má už zapsanou — po doplnění tabulek smažte záznam a aplikace ji
při dalším požadavku spustí znovu (je idempotentní):

```powershell
php -r '$c=new PDO("mysql:host=127.0.0.1;dbname=sprava_webu","root",""); $c->exec("DELETE FROM migrations WHERE name LIKE \"2026_09_20%\"");'
```

Plugin pro WordPress: `pwsh dev/tools/build-plugin.ps1` vyrobí ZIP a
`plugin-info.json` do `www/storage/plugin/` (nasazuje se ručně spolu s aplikací).
Při vývoji nahradí web s pluginem `dev/tests/fixtures/fake-wp-site.php`
(`php -S 127.0.0.1:8250 dev/tests/fixtures/fake-wp-site.php` s `FAKE_WP_KEY`).

## Zásady, které se neporušují

- **Tajemství nejdou do gitu.** `config/env.php` ani `.vscode/sftp.json`.
- **Migrace jen přibývají**, schéma se nikdy nemění mimo ně.
- **API klíče webů se ukládají šifrovaně** (`app_key`), plugin na webu má jen hash.
- **Z auditního logu se nemaže.**
