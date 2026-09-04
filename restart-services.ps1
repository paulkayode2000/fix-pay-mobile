# FixPay Mobile - Restart All Services & Frontends
# Stops project-owned dev servers (Laravel backend + Vite/Next frontends),
# then restarts each one on its preferred port. If a preferred port is already
# taken by ANOTHER application, the next free port is used instead.
#
# Run from anywhere:  powershell -ExecutionPolicy Bypass -File restart-services.ps1

$ErrorActionPreference = 'Stop'
$root   = $PSScriptRoot
$state  = Join-Path $root '.fixpay-services-state.json'
$logDir = Join-Path $root 'logs'
$phpExe = 'C:\Users\kolugbenga\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'

New-Item -ItemType Directory -Force -Path $logDir | Out-Null

# ---------------------------------------------------------------------------
# Port helpers: try the preferred port, fall back to the next free one when
# the preferred port is already taken by another application.
# ---------------------------------------------------------------------------
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

function Set-ProcessEnv {
    param([hashtable]$Vars)
    $saved = @{}
    foreach ($k in $Vars.Keys) {
        $saved[$k] = [Environment]::GetEnvironmentVariable($k, 'Process')
        [Environment]::SetEnvironmentVariable($k, [string]$Vars[$k], 'Process')
    }
    return $saved
}

function Restore-ProcessEnv {
    param([hashtable]$Saved)
    foreach ($k in $Saved.Keys) {
        if ($null -eq $Saved[$k]) {
            Remove-Item "Env:$k" -ErrorAction SilentlyContinue
        } else {
            [Environment]::SetEnvironmentVariable($k, [string]$Saved[$k], 'Process')
        }
    }
}

# ---------------------------------------------------------------------------
# SHUTDOWN
# ---------------------------------------------------------------------------
Write-Host '==================================================================' -ForegroundColor Cyan
Write-Host '                FIXPAY MOBILE - RESTART SERVICES                   ' -ForegroundColor Cyan
Write-Host '==================================================================' -ForegroundColor Cyan
Write-Host ''
Write-Host '== Shutting down project services ==' -ForegroundColor Yellow

# 1. Stop previously launched services (tracked in state file).
if (Test-Path $state) {
    $prev = Get-Content $state -Raw | ConvertFrom-Json
    foreach ($s in $prev) {
        if ($s.pid -and (Get-Process -Id $s.pid -ErrorAction SilentlyContinue)) {
            Write-Host "  Stopping $($s.name) (PID $($s.pid))..." -ForegroundColor Gray
            taskkill /PID $s.pid /T /F 2>&1 | Out-Null
        } else {
            Write-Host "  $($s.name) (PID $($s.pid)) not running, skipping." -ForegroundColor Gray
        }
    }
}

# 2. Stop orphaned Laravel dev servers (php artisan serve) that are not tracked.
Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match 'artisan serve' } |
    ForEach-Object {
        Write-Host "  Stopping orphaned artisan serve (PID $($_.ProcessId))..." -ForegroundColor Gray
        taskkill /PID $_.ProcessId /T /F 2>&1 | Out-Null
    }

Start-Sleep -Seconds 2

# ---------------------------------------------------------------------------
# RESTART - pick ports first (preferred, with fallback when taken elsewhere)
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host '== Reserving ports (preferred / fallback-if-taken) ==' -ForegroundColor Yellow
$backendPort = Get-FreePort -PreferredPort 8081
$pwaPort     = Get-FreePort -PreferredPort 5273
$adminPort   = Get-FreePort -PreferredPort 5174
$portalPort  = Get-FreePort -PreferredPort 3001
$nextjsPort  = Get-FreePort -PreferredPort 3000

Write-Host "  Backend (Laravel):  http://127.0.0.1:$backendPort" -ForegroundColor Gray
Write-Host "  PWA (Vite):         http://127.0.0.1:$pwaPort"       -ForegroundColor Gray
Write-Host "  Admin (Vite):       http://127.0.0.1:$adminPort"     -ForegroundColor Gray
Write-Host "  Portal (Vite):      http://127.0.0.1:$portalPort"    -ForegroundColor Gray
Write-Host "  Next.js:            http://127.0.0.1:$nextjsPort"    -ForegroundColor Gray

$services = New-Object System.Collections.ArrayList

# --- 1. Laravel Backend (php artisan serve) ---
Write-Host ''
Write-Host '== Starting services ==' -ForegroundColor Yellow
$beLog = Join-Path $logDir 'backend.out.log'
$beErr = Join-Path $logDir 'backend.err.log'
$beEnvSaved = Set-ProcessEnv @{
    DB_HOST     = '127.0.0.1'
    DB_PORT     = '5432'
    DB_DATABASE = 'fixpay_db'
    DB_USERNAME = 'fixpay'
    DB_PASSWORD = 'secretpassword'
}
# -d max_execution_time=0: the PHP built-in server hard-caps requests at 30s even
# when php.ini says 0, so slow gateway/provider chains die with a fatal
# mid-request. Note: run php -S DIRECTLY (-d on `artisan serve` only caps the
# parent; the built-in child it spawns does not inherit the flag).
$backend = Start-Process -FilePath $phpExe `
    -ArgumentList "-d", "max_execution_time=0", "-S", "127.0.0.1:$backendPort", "-t", "public", "public/index.php" `
    -WorkingDirectory (Join-Path $root 'fixpay-laravel') `
    -RedirectStandardOutput $beLog -RedirectStandardError $beErr `
    -WindowStyle Hidden -PassThru
Restore-ProcessEnv $beEnvSaved
[void]$services.Add([pscustomobject]@{ name = 'backend'; pid = $backend.Id; port = $backendPort; url = "http://127.0.0.1:$backendPort" })
Write-Host "  Backend started (PID $($backend.Id), port $backendPort, log: $beLog)" -ForegroundColor Gray

# --- 2. Laravel Queue Worker (processes TMS risk + payment jobs) ---
$queueLog = Join-Path $logDir 'queue.out.log'
$queueErr = Join-Path $logDir 'queue.err.log'
$queueEnvSaved = Set-ProcessEnv @{
    DB_HOST     = '127.0.0.1'
    DB_PORT     = '5432'
    DB_DATABASE = 'fixpay_db'
    DB_USERNAME = 'fixpay'
    DB_PASSWORD = 'secretpassword'
}
$queue = Start-Process -FilePath $phpExe `
    -ArgumentList 'artisan queue:work --sleep=3 --tries=3 --timeout=90' `
    -WorkingDirectory (Join-Path $root 'fixpay-laravel') `
    -RedirectStandardOutput $queueLog -RedirectStandardError $queueErr `
    -WindowStyle Hidden -PassThru
Restore-ProcessEnv $queueEnvSaved
[void]$services.Add([pscustomobject]@{ name = 'queue'; pid = $queue.Id; port = 0; url = '' })
Write-Host "  queue:work started (PID $($queue.Id), log: $queueLog)" -ForegroundColor Gray

# --- 3. Laravel Scheduler (TMS poll + stale payments) ---
$schedLog = Join-Path $logDir 'scheduler.out.log'
$schedErr = Join-Path $logDir 'scheduler.err.log'
$schedEnvSaved = Set-ProcessEnv @{
    DB_HOST     = '127.0.0.1'
    DB_PORT     = '5432'
    DB_DATABASE = 'fixpay_db'
    DB_USERNAME = 'fixpay'
    DB_PASSWORD = 'secretpassword'
}
$scheduler = Start-Process -FilePath $phpExe `
    -ArgumentList 'artisan schedule:work' `
    -WorkingDirectory (Join-Path $root 'fixpay-laravel') `
    -RedirectStandardOutput $schedLog -RedirectStandardError $schedErr `
    -WindowStyle Hidden -PassThru
Restore-ProcessEnv $schedEnvSaved
[void]$services.Add([pscustomobject]@{ name = 'scheduler'; pid = $scheduler.Id; port = 0; url = '' })
Write-Host "  scheduler started (PID $($scheduler.Id), log: $schedLog)" -ForegroundColor Gray

# --- 4. fixpay-pwa (Vite) - proxy /api to the actual backend port ---
$pwaLog = Join-Path $logDir 'pwa.out.log'
$pwaErr = Join-Path $logDir 'pwa.err.log'
$pwaEnvSaved = Set-ProcessEnv @{ VITE_API_PROXY_TARGET = "http://127.0.0.1:$backendPort" }
$pwa = Start-Process -FilePath 'cmd.exe' `
    -ArgumentList "/c npm run dev -- --port $pwaPort --strictPort" `
    -WorkingDirectory (Join-Path $root 'fixpay-pwa') `
    -RedirectStandardOutput $pwaLog -RedirectStandardError $pwaErr `
    -WindowStyle Hidden -PassThru
Restore-ProcessEnv $pwaEnvSaved
[void]$services.Add([pscustomobject]@{ name = 'fixpay-pwa'; pid = $pwa.Id; port = $pwaPort; url = "http://127.0.0.1:$pwaPort" })
Write-Host "  fixpay-pwa started (PID $($pwa.Id), port $pwaPort, log: $pwaLog)" -ForegroundColor Gray

# --- 5. fixpay-admin (Vite) ---
$adminLog = Join-Path $logDir 'admin.out.log'
$adminErr = Join-Path $logDir 'admin.err.log'
$admin = Start-Process -FilePath 'cmd.exe' `
    -ArgumentList "/c npm run dev -- --port $adminPort --strictPort" `
    -WorkingDirectory (Join-Path $root 'fixpay-admin') `
    -RedirectStandardOutput $adminLog -RedirectStandardError $adminErr `
    -WindowStyle Hidden -PassThru
[void]$services.Add([pscustomobject]@{ name = 'fixpay-admin'; pid = $admin.Id; port = $adminPort; url = "http://127.0.0.1:$adminPort" })
Write-Host "  fixpay-admin started (PID $($admin.Id), port $adminPort, log: $adminLog)" -ForegroundColor Gray

# --- 6. DNDfixpay-portal (Vite) ---
$portalLog = Join-Path $logDir 'portal.out.log'
$portalErr = Join-Path $logDir 'portal.err.log'
$portal = Start-Process -FilePath 'cmd.exe' `
    -ArgumentList "/c npm run dev -- --port $portalPort --strictPort" `
    -WorkingDirectory (Join-Path $root 'DNDfixpay-portal') `
    -RedirectStandardOutput $portalLog -RedirectStandardError $portalErr `
    -WindowStyle Hidden -PassThru
[void]$services.Add([pscustomobject]@{ name = 'DNDfixpay-portal'; pid = $portal.Id; port = $portalPort; url = "http://127.0.0.1:$portalPort" })
Write-Host "  DNDfixpay-portal started (PID $($portal.Id), port $portalPort, log: $portalLog)" -ForegroundColor Gray

# --- 7. DNDfixpay-nextjs (Next.js) ---
$nextLog = Join-Path $logDir 'nextjs.out.log'
$nextErr = Join-Path $logDir 'nextjs.err.log'
$next = Start-Process -FilePath 'cmd.exe' `
    -ArgumentList "/c npm run dev -- -p $nextjsPort" `
    -WorkingDirectory (Join-Path $root 'DNDfixpay-nextjs') `
    -RedirectStandardOutput $nextLog -RedirectStandardError $nextErr `
    -WindowStyle Hidden -PassThru
[void]$services.Add([pscustomobject]@{ name = 'DNDfixpay-nextjs'; pid = $next.Id; port = $nextjsPort; url = "http://127.0.0.1:$nextjsPort" })
Write-Host "  DNDfixpay-nextjs started (PID $($next.Id), port $nextjsPort, log: $nextLog)" -ForegroundColor Gray

# Save state for future restarts.
$services | ConvertTo-Json | Set-Content -Path $state -Encoding UTF8

# ---------------------------------------------------------------------------
# VERIFY
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host '== Verifying services are up ==' -ForegroundColor Yellow
Start-Sleep -Seconds 12

$allOk = $true
foreach ($s in $services) {
    if ($s.port -gt 0) {
        $listener = Get-NetTCPConnection -State Listen -LocalPort $s.port -ErrorAction SilentlyContinue
        if ($listener) {
            Write-Host "  OK  $($s.name) listening on port $($s.port) -> $($s.url)" -ForegroundColor Green
        } else {
            Write-Host "  !!  $($s.name) NOT listening on port $($s.port) - check log" -ForegroundColor Red
            $allOk = $false
        }
    } else {
        if (Get-Process -Id $s.pid -ErrorAction SilentlyContinue) {
            Write-Host "  OK  $($s.name) running (PID $($s.pid))" -ForegroundColor Green
        } else {
            Write-Host "  !!  $($s.name) not running (PID $($s.pid)) - check log" -ForegroundColor Red
            $allOk = $false
        }
    }
}

# Extra health check for the backend (/up route from bootstrap/app.php).
try {
    $r = Invoke-WebRequest -Uri "http://127.0.0.1:$backendPort/up" -UseBasicParsing -TimeoutSec 10
    Write-Host "  OK  backend health check /up -> HTTP $($r.StatusCode)" -ForegroundColor Green
} catch {
    Write-Host "  !!  backend /up check failed: $($_.Exception.Message) (see $beErr)" -ForegroundColor Red
    $allOk = $false
}

Write-Host ''
if ($allOk) {
    Write-Host 'All FixPay services restarted successfully.' -ForegroundColor Green
} else {
    Write-Host 'Some services did not come up - inspect the logs above.' -ForegroundColor Red
}
Write-Host ''
Write-Host 'To stop everything later, run:  powershell -ExecutionPolicy Bypass -File restart-services.ps1' -ForegroundColor Gray
