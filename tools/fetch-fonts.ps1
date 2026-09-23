# KitsuPlay — fetch the latin woff2 subsets the site actually renders and
# install them under assets/fonts/. Run once (or after adding a face):
#   powershell -File tools/fetch-fonts.ps1
# Kept out of minify.php because it needs network access.

$ErrorActionPreference = 'Stop'
$dest = Join-Path $PSScriptRoot '..\assets\fonts'
New-Item -ItemType Directory -Force -Path $dest | Out-Null
Remove-Item (Join-Path $dest '*.woff2') -Force -ErrorAction SilentlyContinue

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36'
$cssUrl = 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Fira+Code:wght@400&family=Tangerine:wght@400;700&display=swap'
$css = (New-Object System.Net.WebClient).Headers.Add('User-Agent', $ua) -or $null
$css = curl.exe -sL -A $ua $cssUrl
$css = $css -join "`n"

# Google returns one @font-face per subset; we keep only /* latin */ (the UI
# is English) — 5 files, ~67 KB total, versus ~150 KB for every subset.
$blocks = [regex]::Matches($css, '/\* latin \*/\s*@font-face\s*\{[^}]+\}')
if ($blocks.Count -eq 0) { throw 'No latin @font-face blocks found — Google changed the CSS shape.' }

foreach ($b in $blocks) {
    $t    = $b.Value
    $fam  = [regex]::Match($t, "font-family:\s*'([^']+)'").Groups[1].Value
    $wght = [regex]::Match($t, 'font-weight:\s*(\d+)').Groups[1].Value
    $url  = [regex]::Match($t, 'url\((https://[^)]+)\)').Groups[1].Value
    if (!$fam -or !$wght -or !$url) { Write-Warning "skip unparseable block"; continue }

    $slug = ($fam.ToLower() -replace ' ', '') + '-' + $wght
    $out  = Join-Path $dest ($slug + '.woff2')
    curl.exe -sL -A $ua $url -o $out
    Write-Host ('{0}.woff2 = {1} bytes' -f $slug, (Get-Item $out).Length)
}
