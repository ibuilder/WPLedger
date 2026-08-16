# build.ps1 — Build a clean WordPress.org submission zip.
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
$zipPath  = Join-Path $PSScriptRoot $zipName

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

# ---- Files and directories to include ----
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

# ---- Strip items that must not ship in a WP.org zip ----
# Hidden files / VCS / tooling
$globalStrip = @(
    '.git',
    '.gitignore',
    '.gitattributes',
    '.gitkeep',
    '.gitmodules',
    '.github',
    '.travis.yml',
    '.editorconfig',
    '.php-cs-fixer.php',
    '.phpcs.xml',
    'phpcs.xml',
    'phpunit.xml',
    'phpunit.xml.dist',
    '.claude',
    'node_modules',
    'tests',
    'build.ps1',
    'composer.json',
    'composer.lock',
    'Makefile',
    'Gruntfile.js',
    'webpack.config.js',
    'package.json',
    'package-lock.json',
    'yarn.lock',
    '*.zip',
    'bin'
)

# Vendor-specific paths that are dev/demo only
$vendorStrip = @(
    'dompdf\dompdf\www',
    'dompdf\dompdf\tests',
    'dompdf\dompdf\.github',
    'dompdf\php-font-lib\tests',
    'dompdf\php-svg-lib\tests',
    'masterminds\html5\tests',
    'masterminds\html5\benchmarks',
    'masterminds\html5\bin',
    'sabberworm\php-css-parser\tests',
    'thecodingmachine\safe\tests',
    'thecodingmachine\safe\generator',
    # Strip thecodingmachine/safe generated wrappers for system/exec/error functions.
    # Dompdf only uses the math, strings, and array safe-wrappers at runtime;
    # the exec/filesystem/errorfunc stubs are never called and trigger WP.org checks.
    'thecodingmachine\safe\generated\8.1\exec.php',
    'thecodingmachine\safe\generated\8.1\filesystem.php',
    'thecodingmachine\safe\generated\8.1\errorfunc.php',
    'thecodingmachine\safe\generated\8.2\exec.php',
    'thecodingmachine\safe\generated\8.2\filesystem.php',
    'thecodingmachine\safe\generated\8.2\errorfunc.php',
    'thecodingmachine\safe\lib\special_cases.php'
)

function Remove-IfExists ( [string]$path ) {
    if ( Test-Path $path ) {
        Remove-Item $path -Recurse -Force
        Write-Host "  stripped: $($path.Replace($buildDir + '\', ''))" -ForegroundColor DarkGray
    }
}

Write-Host "Stripping dev/hidden files from build..."

# Strip top-level items by name (recurse through entire build tree)
foreach ( $pattern in $globalStrip ) {
    Get-ChildItem -Path $buildDir -Filter $pattern -Recurse -Force -ErrorAction SilentlyContinue |
        ForEach-Object { Remove-IfExists $_.FullName }
}

# Strip vendor-specific paths
$vendorDest = Join-Path $buildDir 'vendor'
foreach ( $rel in $vendorStrip ) {
    Remove-IfExists ( Join-Path $vendorDest $rel )
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
