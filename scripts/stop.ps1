$ErrorActionPreference = 'Stop'
$projectDir = Split-Path $PSScriptRoot -Parent
$pidPath = Join-Path $projectDir 'storage\php.pid'
if (Test-Path -LiteralPath $pidPath) {
    $serverId = [int](Get-Content -LiteralPath $pidPath)
    $server = Get-CimInstance Win32_Process -Filter "ProcessId=$serverId"
    if ($server.Name -eq 'php.exe' -and $server.CommandLine.Contains("$projectDir\router.php")) {
        Stop-Process -Id $serverId
    }
}
$connection = Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue
if ($connection) {
    $dbProcess = Get-Process -Id $connection[0].OwningProcess -ErrorAction SilentlyContinue
    $env:UNIMARKET_SHUTDOWN = '1'
    try {
        & php "$PSScriptRoot\shutdown-db.php"
        if ($LASTEXITCODE -ne 0) { throw 'Не удалось штатно остановить MySQL.' }
        if ($dbProcess -and -not $dbProcess.WaitForExit(20000)) { throw 'MySQL ещё завершает работу.' }
    }
    finally { Remove-Item Env:\UNIMARKET_SHUTDOWN }
}
Write-Output 'Локальные серверы остановлены; данные сохранены.'
