# gateway-e2e.ps1 — fixpay-gateway integration test harness (client -> Laravel -> gateway -> processor)
$ErrorActionPreference = 'Continue'
$base = 'http://127.0.0.1:8010'
$outFile = 'C:\Users\kolugbenga\fixpay-mobile\gateway-e2e-results.json'

function Get-Uuid { [guid]::NewGuid().ToString() }

function Call-Api {
  param($Method, $Path, $Token, $Body, $ExtraHeaders = @{}, $Idem = $true)
  $headers = @{ 'Accept' = 'application/json' }
  if ($Idem) { $headers['X-Idempotency-Key'] = (Get-Uuid) }
  if ($Token) { $headers['Authorization'] = 'Bearer ' + $Token }
  foreach ($k in $ExtraHeaders.Keys) { $headers[$k] = $ExtraHeaders[$k] }
  try {
    $params = @{ Uri = ($base + $Path); Method = $Method; Headers = $headers; UseBasicParsing = $true; TimeoutSec = 120 }
    if ($Body) { $params['ContentType'] = 'application/json'; $params['Body'] = ($Body | ConvertTo-Json -Compress -Depth 6) }
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $resp = Invoke-WebRequest @params
    $sw.Stop()
    return @{ status = [int]$resp.StatusCode; ms = $sw.ElapsedMilliseconds; body = $resp.Content }
  } catch {
    $status = 0; $bodyText = ''
    if ($_.Exception.Response) {
      $status = [int]$_.Exception.Response.StatusCode
      try { $sr = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream()); $bodyText = $sr.ReadToEnd() } catch {}
    }
    return @{ status = $status; ms = 0; body = $bodyText; error = $_.Exception.Message }
  }
}

# ── Login ───────────────────────────────────────────────────────────────
$login = Call-Api -Method 'POST' -Path '/api/auth/login' -Token $null `
  -Body @{ identifier = 'test.userA@fixpay.test'; password = 'Password123!' }
$loginJson = $null
try { $loginJson = $login.body | ConvertFrom-Json } catch {}
$token = $loginJson.access_token
if (-not $token) {
  Write-Output ('FATAL: login failed status=' + $login.status + ' body=' + $login.body)
  exit 1
}
Write-Output ('LOGIN OK status=' + $login.status + ' user=' + $loginJson.user.email)

# ── Guard headers for 9PSB wallet ops ──────────────────────────────────
$device = 'e2e-device-7f3a9c2b'
$guardHeaders = @{
  'X-Device-ID'        = $device
  'X-Location-Lat'     = '6.5244'
  'X-Location-Lng'     = '3.3792'
  'X-Request-Timestamp' = [string][int64]([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())
  'X-Request-Nonce'    = ('n' + (Get-Uuid).Replace('-', ''))
}

$results = [System.Collections.ArrayList]::new()
function Add-Result {
  param($Id, $Type, $Processor, $Route, $Resp, $Note)
  [void]$results.Add([pscustomobject]@{
    id = $Id; type = $Type; processor = $Processor; route = $Route
    http = $Resp.status; ms = $Resp.ms; body = $Resp.body; note = $Note
  })
}

# ── T1 Wallet open (9PSB via gateway /api/v1/mobile/wallet/open) ──────
$r = Call-Api -Method 'POST' -Path '/api/wallet/ninepsb/create' -Token $token `
  -Body @{ terms_accepted = $true } -ExtraHeaders $guardHeaders
Add-Result 'T1' 'Wallet open' '9PSB' 'POST /api/wallet/ninepsb/create' $r 'gateway: /api/v1/mobile/wallet/open'
Write-Output ('T1 status=' + $r.status + ' ms=' + $r.ms)

# ── T2 Wallet enquiry (9PSB direct adapter) ────────────────────────────
$r = Call-Api -Method 'GET' -Path '/api/wallet/ninepsb/enquiry' -Token $token -Body $null -ExtraHeaders $guardHeaders -Idem $false
Add-Result 'T2' 'Wallet enquiry' '9PSB' 'GET /api/wallet/ninepsb/enquiry' $r 'note: adapter direct (not gateway)'
Write-Output ('T2 status=' + $r.status + ' ms=' + $r.ms)

# ── T3 Airtime (VTPass via gateway /api/v1/mobile/bills/pay) ──────────
$r = Call-Api -Method 'POST' -Path '/api/payments/vtpass' -Token $token `
  -Body @{ service_id = 'airtel'; amount_kobo = 10000; phone = '08011111111' }
Add-Result 'T3' 'Airtime' 'VTPass' 'POST /api/payments/vtpass' $r 'gateway: /api/v1/mobile/bills/pay'
Write-Output ('T3 status=' + $r.status + ' ms=' + $r.ms)

# ── T4 Data (VTPass via gateway) ───────────────────────────────────────
$r = Call-Api -Method 'POST' -Path '/api/payments/vtpass' -Token $token `
  -Body @{ service_id = 'mtn-data'; amount_kobo = 10000; phone = '08011111111'; billers_code = '08011111111'; variation_code = 'mtn-10mb-100' }
Add-Result 'T4' 'Data' 'VTPass' 'POST /api/payments/vtpass' $r 'gateway: /api/v1/mobile/bills/pay'
Write-Output ('T4 status=' + $r.status + ' ms=' + $r.ms)

# ── T5 Electricity (VTPass via gateway) ────────────────────────────────
$r = Call-Api -Method 'POST' -Path '/api/payments/vtpass' -Token $token `
  -Body @{ service_id = 'ikeja-electric'; amount_kobo = 100000; phone = '08011111111'; billers_code = '1111111111111'; variation_code = 'prepaid' }
Add-Result 'T5' 'Electricity' 'VTPass' 'POST /api/payments/vtpass' $r 'gateway: /api/v1/mobile/bills/pay'
Write-Output ('T5 status=' + $r.status + ' ms=' + $r.ms)

# ── T6 Cable TV (VTPass via gateway) ───────────────────────────────────
$r = Call-Api -Method 'POST' -Path '/api/payments/vtpass' -Token $token `
  -Body @{ service_id = 'dstv'; amount_kobo = 960000; phone = '08011111111'; billers_code = '1212121212'; variation_code = 'dstv-compact' }
Add-Result 'T6' 'Cable TV' 'VTPass' 'POST /api/payments/vtpass' $r 'gateway: /api/v1/mobile/bills/pay'
Write-Output ('T6 status=' + $r.status + ' ms=' + $r.ms)

# ── T7 Education/JAMB (VTPass via gateway) ─────────────────────────────
$r = Call-Api -Method 'POST' -Path '/api/payments/vtpass' -Token $token `
  -Body @{ service_id = 'jamb'; amount_kobo = 770000; phone = '08011111111'; billers_code = '0123456789'; variation_code = 'utme-mock' }
Add-Result 'T7' 'Education (JAMB)' 'VTPass' 'POST /api/payments/vtpass' $r 'gateway: /api/v1/mobile/bills/pay'
Write-Output ('T7 status=' + $r.status + ' ms=' + $r.ms)

# ── T8 Insurance (VTPass via gateway) ──────────────────────────────────
$r = Call-Api -Method 'POST' -Path '/api/payments/vtpass' -Token $token `
  -Body @{ service_id = 'ui-insure'; amount_kobo = 500000; phone = '08011111111'; billers_code = 'ABC123XY'; variation_code = '2' }
Add-Result 'T8' 'Insurance' 'VTPass' 'POST /api/payments/vtpass' $r 'gateway: /api/v1/mobile/bills/pay'
Write-Output ('T8 status=' + $r.status + ' ms=' + $r.ms)

# ── T9 Bank transfer (Paystack via gateway /api/v1/mobile/transfer/bank) ─
$r = Call-Api -Method 'POST' -Path '/api/transfers/bank' -Token $token `
  -Body @{ amount_kobo = 10000; account_number = '0123456789'; bank_code = '058'; narration = 'gateway e2e test' }
Add-Result 'T9' 'Bank transfer' 'Paystack' 'POST /api/transfers/bank' $r 'gateway: /api/v1/mobile/transfer/bank'
Write-Output ('T9 status=' + $r.status + ' ms=' + $r.ms)

# ── T10 Wallet transfer (internal ledger, no gateway) ──────────────────
$r = Call-Api -Method 'POST' -Path '/api/transfers/wallet' -Token $token `
  -Body @{ amount_kobo = 5000; recipient_phone = '08099999992'; narration = 'gateway e2e test' }
Add-Result 'T10' 'Wallet transfer' 'Internal' 'POST /api/transfers/wallet' $r 'note: internal ledger only'
Write-Output ('T10 status=' + $r.status + ' ms=' + $r.ms)

# ── T11 Alternative payment (stub) ─────────────────────────────────────
$r = Call-Api -Method 'POST' -Path '/api/payments/alternative/initiate' -Token $token `
  -Body @{ service_id = 'airtel'; amount = 10000; phone = '08011111111' }
Add-Result 'T11' 'Alternative pay' 'n/a' 'POST /api/payments/alternative/initiate' $r 'note: stub'
Write-Output ('T11 status=' + $r.status + ' ms=' + $r.ms)

$results | ConvertTo-Json -Depth 5 | Set-Content -Path $outFile -Encoding UTF8
Write-Output ('RESULTS written to ' + $outFile)
