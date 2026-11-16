$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$packageDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ('medivend-staff-update-' + [guid]::NewGuid().ToString('N'))
$null = New-Item -ItemType Directory -Path $packageDirectory
$files = @(
    'staff.php', 'index.html', 'assets/staff.css', 'assets/staff.js',
    'assets/app.js', 'assets/demo.js', 'assets/style.css', 'assets/welcome.css',
    'api/staff-session.php', 'api/staff-stock.php', 'config/staff.php',
    'includes/staff.php', 'includes/stock.php', 'database/migrations/005_staff_stock.sql',
    'scripts/setup-staff.php', 'scripts/check-deployment.php', 'scripts/migrate.php',
    'docs/staff-stock.md', 'README.md', 'deploy/medivend.env.example', 'deploy/update-staff.sh'
)
$manifest = foreach ($file in $files) {
    $source = Join-Path $projectRoot $file
    $destination = Join-Path $packageDirectory $file
    $null = New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination)
    Copy-Item -LiteralPath $source -Destination $destination
    (Get-FileHash -Algorithm SHA256 -LiteralPath $destination).Hash.ToLowerInvariant() + '  ' + $file
}
[System.IO.File]::WriteAllText((Join-Path $packageDirectory 'files.sha256'), ($manifest -join "`n") + "`n", (New-Object System.Text.UTF8Encoding($false)))
$archive = $packageDirectory + '.tar.gz'
& tar -czf $archive -C $packageDirectory .
if ($LASTEXITCODE -ne 0) { throw 'Update archive creation failed.' }
Write-Output $archive
