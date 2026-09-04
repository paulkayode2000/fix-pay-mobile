# gateway-e2e-round2.ps1 — re-run after wallet_provider=9psb fix
$ErrorActionPreference = 'Continue'
$base = 'http://127.0.0.1:8010'
$outFile = 'C:\Users\kolugbenga\fixpay-mobile\gateway-e2e-results-round2.json'
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
$login = Call-Api -Method 'POST' -Path '/api/auth/login' -Token $null -Body @{ identifier = 'test.userA@fixpay.test'; password = 'Password123!' }
$token = (($login.body | ConvertFrom-Json).access_token)
if (-not $token) { Write-Output ('FATAL login ' + $login.status + ' ' + $login.body); exit 1 }
Write-Output ('LOGIN OK')

$guardHeaders = @{
  'X-Device-ID'         = 'e2e-device-7f3a9c2b'
  'X-Location-Lat'      = '6.5244'
  'X-Location-Lng'      = '3.3792'
  'X-Request-Timestamp' = [string][int64]([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())
  'X-Request-Nonce'     = ('n' + (Get-Uuid).Replace('-', ''))
}
$results = [System.Collections.ArrayList]::new()
function Add-Result { param($Id, $Type, $Processor, $Route, $Resp, $Note)
  [void]$results.Add([pscustomobject]@{ id = $Id; type = $Type; processor = $Processor; route = $Route; http = $Resp.status; ms = $Resp.ms; body = $Resp.body; note = $Note })
}

# T2 Wallet enquiry (9PSB direct adapter from Laravel)
$r = Call-Api -Method 'GET' -Path '/api/wallet/ninepsb/enquiry' -Token $token -Body $null -ExtraHeaders $guardHeaders -Idem $false
Add-Result 'T2' 'Wallet enquiry' '9PSB' 'GET /api/wallet/ninepsb/enquiry' $r 'note: 9PSB adapter direct (not gateway)'
Write-Output ('T2 status=' + $r.status + ' ms=' + $r.ms)

# T3-T8 Bill payments via gateway
$bills = @(
  @{ id='T3'; type='Airtime';        body=@{ service_id='airtel'; amount_kobo=10000; phone='08011111111' } },
  @{ id='T4'; type='Data';           body=@{ service_id='mtn-data'; amount_kobo=10000; phone='08011111111'; billers_code='08011111111'; variation_code='mtn-10mb-100' } },
  @{ id='T5'; type='Electricity';    body=@{ service_id='ikeja-electric'; amount_kobo=100000; phone='08011111111'; billers_code='1111111111111'; variation_code='prepaid' } },
  @{ id='T6'; type='Cable TV';       body=@{ service_id='dstv'; amount_kobo=960000; phone='08011111111'; billers_code='1212121212'; variation_code='dstv-compact' } },
  @{ id='T7'; type='Education JAMB'; body=@{ service_id='jamb'; amount_kobo=770000; phone='08011111111'; billers_code='0123456789'; variation_code='utme-mock' } },
  @{ id='T8'; type='Insurance';      body=@{ service_id='ui-insure'; amount_kobo=500000; phone='08011111111'; billers_code='ABC123XY'; variation_code='2' } }
)
foreach ($b in $bills) {
  $r = Call-Api -Method 'POST' -Path '/api/payments/vtpass' -Token $token -Body $b.body
  Add-Result $b.id $b.type 'VTPass' 'POST /api/payments/vtpass' $r 'gateway: /api/v1/mobile/bills/pay'
  Write-Output ($b.id + ' status=' + $r.status + ' ms=' + $r.ms)
}

# T9 Bank transfer via gateway
$r = Call-Api -Method 'POST' -Path '/api/transfers/bank' -Token $token -Body @{ amount_kobo = 10000; account_number = '0123456789'; bank_code = '058'; narration = 'gateway e2e test' }
Add-Result 'T9' 'Bank transfer' 'Paystack' 'POST /api/transfers/bank' $r 'gateway: /api/v1/mobile/transfer/bank'
Write-Output ('T9 status=' + $r.status + ' ms=' + $r.ms)

$results | ConvertTo-Json -Depth 5 | Set-Content -Path $outFile -Encoding UTF8
Write-Output ('RESULTS written to ' + $outFile)
