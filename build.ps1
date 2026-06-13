# build.ps1 — Build a distributable zip for WordPress.org submission.
#
# Usage:
#   .\build.ps1
#   .\build.ps1 -Version 0.2.0
#
# Output: wpledger-<version>.zip in the project root.

param(
    [string]$Version = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ---- Resolve version from plugin header if not supplied ----
if ( -not $Version ) {
    $header = Get-Content "$PSScriptRoot\wpledger.php" -Raw
    if ( $header -match "Version:\s+([\d.]+)" ) {
        $Version = $Matches[1]
    } else {
        Write-Error "Could not determine plugin version from wpledger.php"
        exit 1
    }
}

$slug    = 'wpledger'
$zipName = "$slug-$Version.zip"
$buildDir = Join-Path $PSScriptRoot "build\$slug"
$zipPath = Join-Path $PSScriptRoot $zipName

Write-Host "Building $zipName ..." -ForegroundColor Cyan

# ---- Clean previous build ----
if ( Test-Path "$PSScriptRoot\build" ) {
    Remove-Item "$PSScriptRoot\build" -Recurse -Force
}
New-Item -ItemType Directory -Path $buildDir | Out-Null

# ---- Install production dependencies only ----
Write-Host "Running composer install --no-dev ..."
Push-Location $PSScriptRoot
try {
    & composer install --no-dev --optimize-autoloader --quiet
    if ( $LASTEXITCODE -ne 0 ) {
        Write-Error "composer install failed (exit $LASTEXITCODE)"
        exit 1
    }
} finally {
    Pop-Location
}

# ---- Files and directories to include in the zip ----
$include = @(
    'includes',
    'assets',
    'languages',
    'vendor',
    'wpledger.php',
    'uninstall.php',
    'readme.txt',
    'LICENSE'
)

foreach ( $item in $include ) {
    $src = Join-Path $PSScriptRoot $item
    if ( -not ( Test-Path $src ) ) {
        Write-Warning "Skipping missing item: $item"
        continue
    }
    $dest = Join-Path $buildDir $item
    if ( Test-Path $src -PathType Container ) {
        Copy-Item $src $dest -Recurse -Force
    } else {
        $destParent = Split-Path $dest -Parent
        if ( -not ( Test-Path $destParent ) ) {
            New-Item -ItemType Directory -Path $destParent | Out-Null
        }
        Copy-Item $src $dest -Force
    }
}

# ---- Remove dev-only files from the vendor copy ----
$vendorDest = Join-Path $buildDir 'vendor'
$devPaths = @(
    'bin',
    'dompdf\dompdf\www'
)
foreach ( $rel in $devPaths ) {
    $p = Join-Path $vendorDest $rel
    if ( Test-Path $p ) {
        Remove-Item $p -Recurse -Force
    }
}

# ---- Remove any test / dev artefacts that might have been copied ----
$stripFromBuild = @(
    'tests',
    'phpcs.xml',
    'phpunit.xml',
    '.claude',
    'build.ps1',
    'composer.json',
    'composer.lock'
)
foreach ( $rel in $stripFromBuild ) {
    $p = Join-Path $buildDir $rel
    if ( Test-Path $p ) {
        Remove-Item $p -Recurse -Force
    }
}

# ---- Create zip ----
if ( Test-Path $zipPath ) {
    Remove-Item $zipPath -Force
}

Write-Host "Creating $zipPath ..."
Compress-Archive -Path "$PSScriptRoot\build\*" -DestinationPath $zipPath

# ---- Clean up build folder ----
Remove-Item "$PSScriptRoot\build" -Recurse -Force

$size = [math]::Round( ( Get-Item $zipPath ).Length / 1KB, 1 )
Write-Host "Done: $zipName  ($size KB)" -ForegroundColor Green
