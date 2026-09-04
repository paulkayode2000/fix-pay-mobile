# Run ONCE in an Administrator PowerShell to register the ingress hostnames.
# Adds: api.fixpay.test app.fixpay.test gw.payfixy.test bff.payfixy.test antifraud.tms.test aml.tms.test -> 127.0.0.1
$ErrorActionPreference = 'Stop'

$hostsFile = 'C:\Windows\System32\drivers\etc\hosts'
$entries = @(
    '127.0.0.1  api.fixpay.test app.fixpay.test gw.payfixy.test bff.payfixy.test antifraud.tms.test aml.tms.test'
)

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'ERROR: run this script from an ADMINISTRATOR PowerShell.' -ForegroundColor Red
    exit 1
}

$current = Get-Content $hostsFile -Raw
foreach ($line in $entries) {
    $anchor = $line.Trim() -split '\s+' | Select-Object -Last 1
    if ($current -match [regex]::Escape($anchor)) {
        Write-Host "already present: $anchor"
        continue
    }
    Add-Content -Path $hostsFile -Value $line -Encoding ASCII
    Write-Host "added: $anchor"
}

Write-Host 'Done. Verify: ping api.fixpay.test' -ForegroundColor Green
