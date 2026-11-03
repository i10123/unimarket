$ErrorActionPreference = 'Stop'
$projectDir = Split-Path $PSScriptRoot -Parent
& "$PSScriptRoot\setup-local.ps1"
if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue) {
    Write-Output 'Port 8000 is already in use. Open http://127.0.0.1:8000 if this is UniMarket.'
    exit
}
$php = (Get-Command php -ErrorAction Stop).Source
$process = Start-Process -FilePath $php -ArgumentList @(
    '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', '127.0.0.1:8000',
    '-t', "`"$projectDir`"", "`"$projectDir\router.php`""
) -WorkingDirectory $projectDir -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput "$projectDir\storage\php-output.log" `
    -RedirectStandardError "$projectDir\storage\php-error.log"
$process.Id | Set-Content -LiteralPath "$projectDir\storage\php.pid"
Write-Output 'UniMarket: http://127.0.0.1:8000'
