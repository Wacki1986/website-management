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

# -Encoding UTF8 povinně: Windows PowerShell 5.1 jinak čte soubor jako
# cp1250 a čeština v changelogu (plugin-info.json → okno „Zobrazit
# podrobnosti" ve WordPressu) dopadla jako „PrvnĂ­ verze".
$main = Get-Content (Join-Path $source 'mediagrafik-monitor.php') -Raw -Encoding UTF8
if ($main -notmatch '(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)') { throw 'V hlavičce pluginu chybí Version.' }
$version = $Matches[1]
if ($main -notmatch "const VERSION = '$version'") { throw "Konstanta MG_Monitor::VERSION nesedí s hlavičkou ($version)." }

New-Item -ItemType Directory -Force $target | Out-Null
$zip = Join-Path $target "mediagrafik-monitor-$version.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }

# ZIP musí obsahovat složku `mediagrafik-monitor/` v kořeni — WordPress
# podle ní pozná, kam plugin patří. Záznamy se skládají ručně, protože
# Compress-Archive ve Windows PowerShellu 5.1 píše cesty se zpětným
# lomítkem: Linux na serveru je pak nebere jako složky a WordPress plugin
# rozbalí o úroveň hlouběji („Plugin neexistuje").
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$sourceFull = (Get-Item $source).FullName
$archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
try {
    Get-ChildItem -Path $sourceFull -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($sourceFull.Length).TrimStart('\', '/') -replace '\\', '/'
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, "mediagrafik-monitor/$relative", 'Optimal') | Out-Null
    }
} finally {
    $archive.Dispose()
}

# Changelog z readme.txt (blok == Changelog ==).
$readme = Get-Content (Join-Path $source 'readme.txt') -Raw -Encoding UTF8
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
