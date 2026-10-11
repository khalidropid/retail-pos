<#
  Re-runs RetailPOS setup (Start Menu: "RetailPOS - إعادة الإعداد").
  Safe: never deletes data. On an existing database it takes a verified backup before migrating.
#>
$principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process powershell.exe -Verb RunAs -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    exit
}

$here = Split-Path -Parent $PSCommandPath
$installDir = (Resolve-Path (Join-Path $here '..\..')).Path
$dataDir = Join-Path $env:ProgramData 'RetailPOS'

& (Join-Path $here 'setup.ps1') -InstallDir $installDir -DataDir $dataDir
$code = $LASTEXITCODE

Write-Host ''
if ($code -eq 0) { Write-Host 'تم الإعداد بنجاح.' } else { Write-Host "فشل الإعداد (رمز $code). راجع آخر ملف setup-*.log في: $dataDir\storage\logs" }
Read-Host 'اضغط Enter للإغلاق' | Out-Null
