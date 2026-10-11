<#
  RetailPOS first-run setup / upgrade. Run by the installer as Administrator.

  Data layout (never inside the program folder):
    <DataDir>\.env                 configuration + secrets (Admins/SYSTEM full, Users read)
    <DataDir>\pgdata\              PostgreSQL cluster (service account + Admins only)
    <DataDir>\storage\             logs, sessions, cache, product images
    <DataDir>\backups\             database backups
    <DataDir>\secrets\             PostgreSQL superuser password (Admins/SYSTEM only)
    <DataDir>\first-login.txt      first admin login (fresh install only)

  Safety rules:
    - An existing .env, pgdata or database is NEVER deleted, re-initialized or overwritten.
    - Existing databases are upgraded by `artisan desktop:setup`, which takes a
      verified backup before applying migrations and never runs migrate:fresh.
    - Secrets are never written to the setup log or passed on command lines.

  Exit codes: 0 ok; 10 PostgreSQL init failed; 11 service failed; 12 database
  creation failed; 13 app setup failed (see log); 14 bad arguments.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$InstallDir,
    [Parameter(Mandatory = $true)][string]$DataDir,
    [string]$ServiceName = 'RetailPOS-PostgreSQL'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Resources = Join-Path $InstallDir 'resources'
$PgBin = Join-Path $Resources 'pgsql\bin'
$Php = Join-Path $Resources 'php\php.exe'
$PhpIni = Join-Path $Resources 'php\php.ini'
$App = Join-Path $Resources 'laravel'
$PgData = Join-Path $DataDir 'pgdata'
$EnvFile = Join-Path $DataDir '.env'
$LogDir = Join-Path $DataDir 'storage\logs'
$Log = Join-Path $LogDir ("setup-{0:yyyyMMdd-HHmmss}.log" -f (Get-Date))

New-Item -ItemType Directory -Force -Path $DataDir, $LogDir, (Join-Path $DataDir 'backups'), (Join-Path $DataDir 'cache'), (Join-Path $DataDir 'secrets') | Out-Null

function Write-Log([string]$Message) {
    $line = "[{0:yyyy-MM-dd HH:mm:ss}] {1}" -f (Get-Date), $Message
    Add-Content -Path $Log -Value $line -Encoding UTF8
    Write-Output $line
}

function New-Secret([int]$Bytes = 24) {
    $buffer = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buffer)
    # URL/SQL-safe alphabet: no quotes or backslashes.
    return ([Convert]::ToBase64String($buffer) -replace '[+/=]', '')
}

function Invoke-Tool([string]$Exe, [string[]]$Arguments, [hashtable]$Env = @{}) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $Exe
    $psi.Arguments = ($Arguments | ForEach-Object { if ($_ -match '[\s"]') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ } }) -join ' '
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.CreateNoWindow = $true
    foreach ($k in $Env.Keys) { $psi.EnvironmentVariables[$k] = $Env[$k] }
    $p = [System.Diagnostics.Process]::Start($psi)
    $out = $p.StandardOutput.ReadToEnd()
    $err = $p.StandardError.ReadToEnd()
    $p.WaitForExit()
    return [pscustomobject]@{ Code = $p.ExitCode; Out = $out; Err = $err }
}

function Set-Acl-Restricted([string]$Path, [string[]]$Grants, [switch]$Recurse) {
    # Remove inherited permissions, then grant exactly what is listed (SIDs: SYSTEM, Administrators, Users, NetworkService).
    $icaclsArgs = @($Path, '/inheritance:r')
    foreach ($g in $Grants) { $icaclsArgs += '/grant:r'; $icaclsArgs += $g }
    if ($Recurse) { $icaclsArgs += '/T' }
    $r = Invoke-Tool 'icacls.exe' $icaclsArgs
    if ($r.Code -ne 0) { throw "icacls failed on ${Path}: $($r.Err)" }
}

function Test-PortFree([int]$Port) {
    try {
        $l = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
        $l.Start(); $l.Stop(); return $true
    } catch { return $false }
}

function Wait-Postgres([int]$Port, [int]$Seconds = 60) {
    for ($i = 0; $i -lt $Seconds; $i++) {
        $r = Invoke-Tool (Join-Path $PgBin 'pg_isready.exe') @('-h', '127.0.0.1', '-p', "$Port")
        if ($r.Code -eq 0) { return $true }
        Start-Sleep -Seconds 1
    }
    return $false
}

function Get-EnvValue([string]$Key) {
    $line = Get-Content $EnvFile -Encoding UTF8 | Where-Object { $_ -match "^\s*$Key\s*=" } | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line -split '=', 2)[1].Trim().Trim('"')
}

function Register-PgService([int]$Port) {
    $existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($existing) {
        Write-Log "Service $ServiceName already registered."
    } else {
        $r = Invoke-Tool (Join-Path $PgBin 'pg_ctl.exe') @('register', '-N', $ServiceName, '-U', 'NT AUTHORITY\NetworkService', '-D', $PgData, '-S', 'auto', '-w')
        if ($r.Code -ne 0) { Write-Log "pg_ctl register failed: $($r.Err)"; exit 11 }
        Write-Log "Service $ServiceName registered (automatic start, NetworkService)."
    }
    $svc = Get-Service -Name $ServiceName
    if ($svc.Status -ne 'Running') { Start-Service -Name $ServiceName }
    if (-not (Wait-Postgres $Port 90)) { Write-Log 'PostgreSQL did not become ready.'; exit 11 }
    Write-Log "PostgreSQL ready on 127.0.0.1:$Port."
}

try {
    Write-Log "RetailPOS setup. InstallDir=$InstallDir DataDir=$DataDir"
    if (-not (Test-Path $Php) -or -not (Test-Path (Join-Path $PgBin 'postgres.exe'))) { Write-Log 'Runtime files missing.'; exit 14 }

    # Folder permissions: the app (run by any local user) needs to write storage/cache/backups.
    foreach ($dir in @('storage', 'cache', 'backups')) {
        $p = Join-Path $DataDir $dir
        New-Item -ItemType Directory -Force -Path $p | Out-Null
        $r = Invoke-Tool 'icacls.exe' @($p, '/grant', '*S-1-5-32-545:(OI)(CI)M', '/T')
        if ($r.Code -ne 0) { Write-Log "icacls warning on ${p}: $($r.Err)" }
    }
    Set-Acl-Restricted (Join-Path $DataDir 'secrets') @('*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F')

    $fresh = -not (Test-Path $EnvFile)

    if ($fresh) {
        # ---------------------------------------------------------- new install
        if (Test-Path (Join-Path $PgData 'PG_VERSION')) {
            # A cluster exists but .env is gone: refuse to guess passwords or reinitialize.
            Write-Log 'pgdata exists but .env is missing. Not touching the existing database. Restore .env from a backup or see the guide.'
            exit 12
        }

        $port = 5433
        while (-not (Test-PortFree $port) -and $port -lt 5450) { $port++ }
        if (-not (Test-PortFree $port)) { Write-Log 'No free port for PostgreSQL (5433-5449).'; exit 10 }

        $superPassword = New-Secret
        $appPassword = New-Secret
        $superFile = Join-Path $DataDir 'secrets\postgres-superuser.txt'
        Set-Content -Path $superFile -Value $superPassword -NoNewline -Encoding ASCII

        New-Item -ItemType Directory -Force -Path $PgData | Out-Null
        Set-Acl-Restricted $PgData @('*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-20:(OI)(CI)F')

        Write-Log 'Initializing PostgreSQL cluster (UTF8, scram-sha-256).'
        $r = Invoke-Tool (Join-Path $PgBin 'initdb.exe') @('-D', $PgData, '-U', 'postgres', "--pwfile=$superFile", '-E', 'UTF8',
            '--locale=C', '--auth=scram-sha-256', '--data-checksums')
        if ($r.Code -ne 0) { Write-Log "initdb failed: $($r.Err)"; exit 10 }

        # Loopback only: PostgreSQL is never reachable from the network.
        Add-Content -Path (Join-Path $PgData 'postgresql.conf') -Encoding ASCII -Value @(
            '', '# RetailPOS', "listen_addresses = 'localhost'", "port = $port", "password_encryption = 'scram-sha-256'",
            "log_destination = 'stderr'", 'logging_collector = on', "log_directory = 'log'", 'log_rotation_age = 7d')
        Set-Acl-Restricted $PgData @('*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-20:(OI)(CI)F') -Recurse

        Register-PgService $port

        Write-Log 'Creating application role and database.'
        $pgEnv = @{ PGPASSWORD = $superPassword }
        $sql = "CREATE ROLE retailpos LOGIN PASSWORD '$appPassword'; CREATE DATABASE retail_pos OWNER retailpos ENCODING 'UTF8' TEMPLATE template0;"
        $sqlFile = Join-Path $DataDir 'secrets\create.sql'
        Set-Content -Path $sqlFile -Value $sql -Encoding ASCII
        $r = Invoke-Tool (Join-Path $PgBin 'psql.exe') @('-h', '127.0.0.1', '-p', "$port", '-U', 'postgres', '-d', 'postgres', '-v', 'ON_ERROR_STOP=1', '-q', '-f', $sqlFile) $pgEnv
        Remove-Item $sqlFile -Force
        if ($r.Code -ne 0) { Write-Log "Database creation failed: $($r.Err -replace [regex]::Escape($appPassword), '******')"; exit 12 }

        # .env from the template. Literal (non-regex) replacement; secrets are never logged.
        # Windows paths go in SINGLE quotes: dotenv treats backslashes in double quotes as escapes.
        $keyBytes = New-Object byte[] 32
        [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($keyBytes)
        $appKey = 'base64:' + [Convert]::ToBase64String($keyBytes)
        $content = (Get-Content (Join-Path $Resources 'installer\env.template') -Raw -Encoding UTF8)
        $content = $content.Replace('__APP_KEY__', $appKey)
        $content = $content.Replace('__DB_PORT__', "$port")
        $content = $content.Replace('__DB_PASSWORD__', $appPassword)
        $content = $content.Replace('__BACKUP_DIR__', "'" + (Join-Path $DataDir 'backups') + "'")
        $content = $content.Replace('__PG_BIN__pg_dump__EXE__', "'" + (Join-Path $PgBin 'pg_dump.exe') + "'")
        $content = $content.Replace('__PG_BIN__pg_restore__EXE__', "'" + (Join-Path $PgBin 'pg_restore.exe') + "'")
        $content = $content.Replace('__PG_BIN__psql__EXE__', "'" + (Join-Path $PgBin 'psql.exe') + "'")
        [System.IO.File]::WriteAllText($EnvFile, $content, (New-Object System.Text.UTF8Encoding $false))
        Set-Acl-Restricted $EnvFile @('*S-1-5-18:F', '*S-1-5-32-544:F', '*S-1-5-32-545:R')
        Write-Log ".env created (port $port)."
    } else {
        # ------------------------------------------------------------- upgrade
        Write-Log 'Existing installation detected: keeping configuration and data.'
        $port = [int](Get-EnvValue 'DB_PORT')
        if ((Get-EnvValue 'DB_HOST') -in @('127.0.0.1', 'localhost') -and (Test-Path (Join-Path $PgData 'PG_VERSION'))) {
            Register-PgService $port
        } else {
            Write-Log 'Using an external/existing PostgreSQL configured in .env (not managed by RetailPOS).'
        }
    }

    # ---------------------------------------------------- app database setup
    Write-Log 'Running desktop:setup (migrations; a verified backup is taken first on upgrades).'
    $env:RETAILPOS_DATA_DIR = $DataDir
    $r = Invoke-Tool $Php @('-c', $PhpIni, (Join-Path $App 'artisan'), 'desktop:setup', '--no-ansi', '--no-interaction', '--wait=90',
        "--credentials-file=$(Join-Path $DataDir 'first-login.txt')") @{ RETAILPOS_DATA_DIR = $DataDir }
    Add-Content -Path $Log -Value $r.Out -Encoding UTF8
    if ($r.Err) { Add-Content -Path $Log -Value $r.Err -Encoding UTF8 }
    if ($r.Code -ne 0) { Write-Log "desktop:setup failed with code $($r.Code)."; exit 13 }

    if (Test-Path (Join-Path $DataDir 'first-login.txt')) {
        Set-Acl-Restricted (Join-Path $DataDir 'first-login.txt') @('*S-1-5-18:F', '*S-1-5-32-544:F', '*S-1-5-32-545:R')
    }

    Write-Log 'Setup completed successfully.'
    exit 0
} catch {
    Write-Log "Unexpected error: $($_.Exception.Message)"
    exit 13
}
