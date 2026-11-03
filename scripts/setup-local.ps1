$ErrorActionPreference = 'Stop'
$projectDir = Split-Path $PSScriptRoot -Parent
$runtimeDir = Join-Path $env:LOCALAPPDATA 'UniMarket\runtime'
$mysqlDir = Join-Path $runtimeDir 'mysql-8.4.13-winx64'
$dataDir = Join-Path $projectDir 'storage\mysql8'
$adminPath = Join-Path $projectDir 'storage\mysql-admin.json'
$initPath = Join-Path $projectDir 'storage\mysql-init.sql'

Get-Command php -ErrorAction Stop | Out-Null
foreach ($dir in @($runtimeDir, "$projectDir\storage\sessions", "$projectDir\storage\limits")) {
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
}
if (-not (Test-Path -LiteralPath "$mysqlDir\bin\mysqld.exe")) {
    $zipPath = Join-Path $runtimeDir 'mysql-8.4.13-winx64.zip'
    Write-Output 'Загружаем MySQL из официального архива...'
    Invoke-WebRequest 'https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.13-winx64.zip' -OutFile $zipPath
    if ((Get-FileHash -LiteralPath $zipPath -Algorithm MD5).Hash -ne '7b94489d5524dea9b27097068bea25a8') {
        throw 'Контрольная сумма архива MySQL не совпала.'
    }
    Expand-Archive -LiteralPath $zipPath -DestinationPath $runtimeDir
}
if (-not (Test-Path -LiteralPath $adminPath)) {
    if (Test-Path -LiteralPath $dataDir) { throw 'Каталог данных уже существует без реквизитов. Перезапись запрещена.' }
    $bytes = New-Object byte[] 24
    [Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    $password = [Convert]::ToHexString($bytes).ToLowerInvariant()
    @{ password = $password } | ConvertTo-Json | Set-Content -LiteralPath $adminPath -Encoding utf8
    "ALTER USER 'root'@'localhost' IDENTIFIED BY '$password';" | Set-Content -LiteralPath $initPath -Encoding utf8
}
if (-not (Test-Path -LiteralPath "$dataDir\mysql")) {
    & "$mysqlDir\bin\mysqld.exe" '--no-defaults' "--basedir=$mysqlDir" "--datadir=$dataDir" '--initialize-insecure' '--console'
    if ($LASTEXITCODE -ne 0) { throw 'Не удалось создать локальный каталог MySQL.' }
}
# Пароль root задаётся init-файлом до начала приёма подключений.
if (-not (Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue)) {
    $arguments = @('--no-defaults', "--basedir=`"$mysqlDir`"", "--datadir=`"$dataDir`"",
        '--bind-address=127.0.0.1', '--port=3306', '--mysqlx=0', "--log-error=`"$dataDir\server.log`"")
    if (Test-Path -LiteralPath $initPath) { $arguments += "--init-file=`"$initPath`"" }
    $process = Start-Process -FilePath "$mysqlDir\bin\mysqld.exe" -ArgumentList $arguments -WindowStyle Hidden -PassThru
    $process.Id | Set-Content -LiteralPath "$projectDir\storage\mysql.pid"
    for ($attempt = 0; $attempt -lt 100; $attempt++) {
        if (Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue) { break }
        if ($process.HasExited) { throw 'MySQL не запустился. Проверь журнал storage/mysql8/server.log.' }
        Start-Sleep -Milliseconds 200
    }
}
& php "$PSScriptRoot\init-db.php"
if ($LASTEXITCODE -ne 0) { throw 'Не удалось применить схему базы.' }
if (Test-Path -LiteralPath $initPath) { Remove-Item -LiteralPath $initPath }
Write-Output 'MySQL готов на 127.0.0.1:3306. Данные сохраняются в storage/mysql8.'
