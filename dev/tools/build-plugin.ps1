# dev/tools/build-plugin.ps1 — balíček našeho pluginu (Monitor) k distribuci.
#
# Spuštění z kořene projektu:  powershell -ExecutionPolicy Bypass -File dev/tools/build-plugin.ps1
#
# Zkontroluje syntaxi PHP, zabalí `wp-plugin/monitor/` do neutrálního ZIPu
# `www/storage/plugin/monitor-<verze>.zip` a přegeneruje
# `www/storage/plugin/plugin-info.json`.
#
# Název pluginu, autora, složku a adresu správy do balíčku NEPÍŠE —
# doplní je každá instance Správy webů až při stažení (PluginDistribution,
# „razítko“ podle Nastavení → Základní nastavení, včetně složky). Stejně tak
# adresu ZIPu v JSONu.
#
# Verze se čte z hlavičky pluginu (`Version:`) a musí sedět s konstantou
# `MG_Monitor::VERSION`. Changelog z readme.txt.
# Složka `www/storage/plugin/` se nasazuje na server spolu s aplikací
# (je mimo git — nahrajte ji ručně po každém buildu, na každou instanci).
#
# POZOR: s otevřeným VS Code ji SFTP watcher nahraje na produkci hned —
# build do `www/storage/plugin/` = vydání nové verze všem webům (plugin má
# automatické aktualizace). Na zkoušku balte jinam:
#   ... -File dev/tools/build-plugin.ps1 -OutDir C:\cesta\mimo\www

param([string]$OutDir = '')

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$source = Join-Path $root 'wp-plugin/monitor'
$target = if ($OutDir -ne '') { $OutDir } else { Join-Path $root 'www/storage/plugin' }
$base = 'monitor'

Write-Host 'Kontrola syntaxe PHP...'
Get-ChildItem -Path $source -Filter *.php -Recurse | ForEach-Object {
    $result = & php -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "Syntaktická chyba: $($_.FullName)`n$result" }
}

# -Encoding UTF8 povinně: Windows PowerShell 5.1 jinak čte soubor jako
# cp1250 a čeština v changelogu (plugin-info.json → okno „Zobrazit
# podrobnosti" ve WordPressu) dopadla jako „PrvnĂ­ verze".
$main = Get-Content (Join-Path $source 'monitor.php') -Raw -Encoding UTF8
if ($main -notmatch '(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)') { throw 'V hlavičce pluginu chybí Version.' }
$version = $Matches[1]
$class = Get-Content (Join-Path $source 'includes/class-mg-monitor.php') -Raw -Encoding UTF8
if ($class -notmatch "const VERSION = '$version'") { throw "Konstanta MG_Monitor::VERSION nesedí s hlavičkou ($version)." }

New-Item -ItemType Directory -Force $target | Out-Null
$zip = Join-Path $target "$base-$version.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }

# ZIP musí obsahovat složku v kořeni — WordPress podle ní pozná, kam plugin
# patří (správa ji při výdeji přejmenuje na slug instance). Záznamy se
# skládají ručně, protože Compress-Archive ve Windows PowerShellu 5.1 píše
# cesty se zpětným lomítkem: Linux na serveru je pak nebere jako složky
# a WordPress plugin rozbalí o úroveň hlouběji („Plugin neexistuje").
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$sourceFull = (Get-Item $source).FullName
$archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
try {
    Get-ChildItem -Path $sourceFull -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($sourceFull.Length).TrimStart('\', '/') -replace '\\', '/'
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, "$base/$relative", 'Optimal') | Out-Null
    }
} finally {
    $archive.Dispose()
}

# Changelog z readme.txt (blok == Changelog ==).
$readme = Get-Content (Join-Path $source 'readme.txt') -Raw -Encoding UTF8
$changelog = ''
if ($readme -match '(?s)== Changelog ==\s*(.*)$') { $changelog = $Matches[1].Trim() }
$changelogHtml = '<pre>' + [System.Net.WebUtility]::HtmlEncode($changelog) + '</pre>'

# Název, autora, web a popis doplní instance při výdeji (PluginDistribution::info()).
$info = [ordered]@{
    version = $version
    requires = '6.8'
    tested = '6.8'
    requires_php = '7.4'
    last_updated = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    sections = [ordered]@{
        changelog = $changelogHtml
    }
}

$json = $info | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText((Join-Path $target 'plugin-info.json'), $json, (New-Object System.Text.UTF8Encoding $false))

Write-Host "Hotovo: $zip" -ForegroundColor Green
Write-Host "        $(Join-Path $target 'plugin-info.json')"
