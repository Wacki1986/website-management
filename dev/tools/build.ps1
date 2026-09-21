# dev/tools/build.ps1 — lokální build Správy webů.
#
# Dělá totéž co v klientské aplikaci: zkompiluje SCSS do www/assets/css/app.css
# a zkontroluje syntaxi PHP. Spuštění z kořene projektu:  pwsh dev/tools/build.ps1
#
# JS a fonty se nekopírují — leží rovnou ve www/assets a jsou ve verzování.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)

Write-Host 'Kompiluji SCSS...'
npx --yes sass "$root/dev/scss/app.scss" "$root/www/assets/css/app.css" --style=compressed --no-source-map
if ($LASTEXITCODE -ne 0) { throw 'Kompilace SCSS selhala.' }

Write-Host 'Kontrola syntaxe PHP...'
$paths = @(
    "$root/www/app",
    "$root/www/database",
    "$root/www/config",
    "$root/dev/tests",
    "$root/dev/tools",
    "$root/www/index.php"
)

Get-ChildItem -Path $paths -Filter *.php -Recurse |
    ForEach-Object {
        $result = & php -l $_.FullName
        if ($LASTEXITCODE -ne 0) { throw "Syntaktická chyba: $($_.FullName)`n$result" }
    }

Write-Host 'Hotovo.' -ForegroundColor Green
