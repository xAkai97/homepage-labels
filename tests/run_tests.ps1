# PowerShell runner for Homepage Labels test harness
$phpCmd = Get-Command "php" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty Source

if (-not $phpCmd) {
    # Check Scoop standard install location
    $scoopPhp = "$env:USERPROFILE\scoop\apps\php\current\php.exe"
    if (Test-Path $scoopPhp) {
        $phpCmd = $scoopPhp
    }
}

if (-not $phpCmd) {
    # Check winget standard install location
    $wingetPhp = Get-ChildItem -Path "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter "php.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
    if ($wingetPhp) {
        $phpCmd = $wingetPhp
    }
}

if (-not $phpCmd) {
    Write-Error "PHP executable not found in PATH or WinGet packages directory. Please ensure PHP is installed."
    exit 1
}

Write-Host "Using PHP: $phpCmd" -ForegroundColor Cyan
& $phpCmd (Join-Path $PSScriptRoot "run_tests.php") @args
exit $LASTEXITCODE
