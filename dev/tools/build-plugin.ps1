# dev/tools/build-plugin.ps1 — balíček pluginu MEDIAGRAFIK Monitor k distribuci.
#
# Spuštění z kořene projektu:  pwsh dev/tools/build-plugin.ps1
#
# Zkontroluje syntaxi PHP, zabalí `wp-plugin/mediagrafik-monitor/` do ZIPu
# `www/storage/plugin/mediagrafik-monitor-<verze>.zip` a přegeneruje
# `www/storage/plugin/plugin-info.json`. Adresu ZIPu do JSONu doplňuje
# aplikace za běhu z `app_url` (PluginDistribution), tady se nepíše.
#
# Verze se čte z hlavičky pluginu (`Version:`). Changelog z readme.txt.
# Složka `www/storage/plugin/` se nasazuje na server spolu s aplikací
# (je mimo git — nahrajte ji ručně po každém buildu).

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$source = Join-Path $root 'wp-plugin/mediagrafik-monitor'
$target = Join-Path $root 'www/storage/plugin'

Write-Host 'Kontrola syntaxe PHP...'
Get-ChildItem -Path $source -Filter *.php -Recurse | ForEach-Object {
    $result = & php -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "Syntaktická chyba: $($_.FullName)`n$result" }
}

$main = Get-Content (Join-Path $source 'mediagrafik-monitor.php') -Raw
if ($main -notmatch '(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)') { throw 'V hlavičce pluginu chybí Version.' }
$version = $Matches[1]
if ($main -notmatch "const VERSION = '$version'") { throw "Konstanta MG_Monitor::VERSION nesedí s hlavičkou ($version)." }

New-Item -ItemType Directory -Force $target | Out-Null
$zip = Join-Path $target "mediagrafik-monitor-$version.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }

# ZIP musí obsahovat složku `mediagrafik-monitor/` v kořeni — WordPress
# podle ní pozná, kam plugin patří.
$staging = Join-Path ([System.IO.Path]::GetTempPath()) "mg-monitor-build-$([guid]::NewGuid().ToString('N'))"
New-Item -ItemType Directory -Force (Join-Path $staging 'mediagrafik-monitor') | Out-Null
Copy-Item -Path (Join-Path $source '*') -Destination (Join-Path $staging 'mediagrafik-monitor') -Recurse
Compress-Archive -Path (Join-Path $staging 'mediagrafik-monitor') -DestinationPath $zip
Remove-Item $staging -Recurse -Force

# Changelog z readme.txt (blok == Changelog ==).
$readme = Get-Content (Join-Path $source 'readme.txt') -Raw
$changelog = ''
if ($readme -match '(?s)== Changelog ==\s*(.*)$') { $changelog = $Matches[1].Trim() }
$changelogHtml = '<pre>' + [System.Net.WebUtility]::HtmlEncode($changelog) + '</pre>'

$info = [ordered]@{
    name = 'MEDIAGRAFIK Monitor'
    slug = 'mediagrafik-monitor'
    version = $version
    author = 'Mediagrafik.cz'
    author_profile = 'https://mediagrafik.cz'
    homepage = 'https://mediagrafik.cz'
    requires = '6.8'
    tested = '6.8'
    requires_php = '7.4'
    last_updated = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    sections = [ordered]@{
        description = 'Napojení webu na Správu webů studia MEDIAGRAFIK. Plugin nic neposílá — hub si data načítá sám přes REST API a API klíč.'
        changelog = $changelogHtml
    }
}

$json = $info | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText((Join-Path $target 'plugin-info.json'), $json, (New-Object System.Text.UTF8Encoding $false))

Write-Host "Hotovo: $zip" -ForegroundColor Green
Write-Host "        $(Join-Path $target 'plugin-info.json')"
