# FixPay Mobile PWA Run-On-Device Script
# This script starts both the Laravel backend and React PWA servers, exposing them to the local network for testing on mobile.

# 1. Determine Local IP Address
$localIp = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { 
    $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" 
} | Select-Object -First 1).IPAddress

if (-not $localIp) {
    $localIp = "YOUR_PC_IP"
}

# Port helpers: automatically fall back to the next free port when the
# preferred (default) port is already in use by another app on this device.
function Test-PortInUse {
    param([int]$Port)
    $listener = $null
    try {
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Any, $Port)
        $listener.Start()
        $listener.Stop()
        $listener = $null
        return $false
    } catch {
        return $true
    } finally {
        if ($null -ne $listener) {
            try { $listener.Stop() } catch {}
        }
    }
}

function Get-FreePort {
    param([int]$PreferredPort)
    $port = $PreferredPort
    while (Test-PortInUse $port) {
        Write-Host "  Port $port is already in use, trying $($port + 1)..." -ForegroundColor Yellow
        $port++
        if ($port -gt 65535) { throw "No free port found above $PreferredPort." }
    }
    return $port
}

Clear-Host
Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host "                FIXPAY MOBILE RUN-ON-DEVICE SCRIPT               " -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "  1. Connect your mobile device and PC to the SAME Wi-Fi network." -ForegroundColor Yellow
Write-Host "  2. Open the URL shown below in your mobile browser." -ForegroundColor Yellow
Write-Host "  3. In your mobile browser, tap 'Add to Home Screen' to install." -ForegroundColor Yellow
Write-Host ""

# Resolve ports now (fall back to the next free port when defaults are taken)
$backendPort = Get-FreePort -PreferredPort 8081
$pwaPort     = Get-FreePort -PreferredPort 5273

Write-Host "  Backend (Laravel):  http://127.0.0.1:$backendPort" -ForegroundColor DarkGray
Write-Host "  PWA (Vite):         http://${localIp}:$pwaPort   <-- open this on your phone" -ForegroundColor Green -BackgroundColor Black
Write-Host ""
Write-Host "  Starting servers now... (Ctrl+C to terminate both)" -ForegroundColor Gray
Write-Host "=================================================================" -ForegroundColor Cyan

# 2. Concurrently Run Backend and PWA
$backendPath = Join-Path $PSScriptRoot "fixpay-laravel"
$pwaPath = Join-Path $PSScriptRoot "fixpay-pwa"

# Start Laravel Backend on the chosen port (default 8081)
# -d max_execution_time=0 lifts the PHP built-in server's hard 30s cap so slow
# gateway/provider chains don't die mid-request. Run php -S DIRECTLY (-d on
# `artisan serve` only caps the parent; its built-in child doesn't inherit it).
$phpPath = "C:\Users\kolugbenga\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$backendJob = Start-Process $phpPath -ArgumentList '-d','max_execution_time=0','-S',"127.0.0.1:$backendPort",'-t','public','public/index.php' -WorkingDirectory $backendPath -NoNewWindow -PassThru

# Point the Vite dev proxy at the chosen backend port so the PWA keeps working
# even when the backend falls back to a new port.
$previousProxyTarget = $env:VITE_API_PROXY_TARGET
$env:VITE_API_PROXY_TARGET = "http://127.0.0.1:$backendPort"

# Start Vite PWA on the chosen port (host=true already configured in vite.config.ts)
# npm is a .cmd shim on Windows, so it must be launched through cmd.exe when
# Start-Process runs with -NoNewWindow (UseShellExecute=false).
$pwaJob = Start-Process cmd.exe -ArgumentList "/c npm run dev -- --port $pwaPort --strictPort" -WorkingDirectory $pwaPath -NoNewWindow -PassThru

# Restore the previous proxy target so this script doesn't leak environment changes
if ($null -eq $previousProxyTarget) {
    Remove-Item Env:\VITE_API_PROXY_TARGET -ErrorAction SilentlyContinue
} else {
    $env:VITE_API_PROXY_TARGET = $previousProxyTarget
}

# Monitor process execution and wait for Ctrl+C
try {
    while ($true) {
        Start-Sleep -Seconds 1
    }
}
finally {
    Write-Host ""
    Write-Host "Stopping servers..." -ForegroundColor Red
    # taskkill /T also terminates child processes (e.g. the `php -S` server
    # spawned by `artisan serve`, and the `node` process spawned by `npm`), so
    # the chosen ports are fully released.
    if ($backendJob) { taskkill /PID $backendJob.Id /T /F 2>&1 | Out-Null }
    if ($pwaJob) { taskkill /PID $pwaJob.Id /T /F 2>&1 | Out-Null }
    Write-Host "Servers stopped successfully." -ForegroundColor Green
}
