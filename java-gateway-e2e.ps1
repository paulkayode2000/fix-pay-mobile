# java-gateway-e2e.ps1 — run transaction tests through the Java gateway routes (localhost:8080)
$ErrorActionPreference = 'Continue'
$base = 'http://localhost:8080'
$outFile = 'C:\Users\kolugbenga\fixpay-mobile\java-gateway-e2e-results.json'
$hdr = @{
  'Authorization' = 'Bearer ce_sk_test_fixpaymobile_z1tG4wJ7oN3qX6vA9bD2eF5hK8mL0pR'
  'Client-Public' = 'ce_pk_test_fixpaymobile_x8k2mQ4nW7pL3vR9yB6cF1dH5jA0sN8u'
  'X-Business-Id' = '99'
  'Accept' = 'application/json'
}
function Get-Uuid { [guid]::NewGuid().ToString() }
function Call-JavaGw {
  param($Method, $Path, $Body, $Idem = $true)
  $h = @{}; $hdr.GetEnumerator() | ForEach-Object { $h[$_.Key] = $_.Value }
  if ($Idem) { $h['X-Idempotency-Key'] = (Get-Uuid) }
  try {
    $params = @{ Uri = ($base + $Path); Method = $Method; Headers = $h; UseBasicParsing = $true; TimeoutSec = 90 }
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

# Wait for gateway
$gw = $false
for ($i = 0; $i -lt 20; $i++) {
  try { $r = Invoke-WebRequest -Uri "$base/actuator/health" -UseBasicParsing -TimeoutSec 6; if ($r.StatusCode -eq 200) { $gw = $true; break } } catch {}
  Start-Sleep -Seconds 10
}
if (-not $gw) { Write-Output 'FATAL: gateway not healthy'; exit 1 }
Write-Output 'GATEWAY HEALTHY'

$results = [System.Collections.ArrayList]::new()
function Add-R { param($Id, $Type, $Processor, $Route, $Resp, $Note)
  [void]$results.Add([pscustomobject]@{ id=$Id; type=$Type; processor=$Processor; route=$Route; http=$Resp.status; ms=$Resp.ms; body=$Resp.body; note=$Note })
}

# G0 health
$r = Call-JavaGw -Method 'GET' -Path '/actuator/health' -Idem $false
Add-R 'G0' 'Gateway health' 'n/a' 'GET /actuator/health' $r 'gateway node up'

# G1 Wallet open (9PSB)
$r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/wallet/open' -Body @{ wallet_provider='9psb'; accountName='E2E Java Test'; phoneNo='08099999991'; email='test.userA@fixpay.test'; bvn='12345678901' }
Add-R 'G1' 'Wallet open' '9PSB' 'POST /api/v1/mobile/wallet/open' $r '-> transaction /nuban/9psb/generate'
Write-Output ('G1 status=' + $r.status)

# G2 Wallet enquiry (9PSB)
$r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/wallet/enquiry' -Body @{ wallet_provider='9psb'; accountNumber='1100091317' }
Add-R 'G2' 'Wallet enquiry' '9PSB' 'POST /api/v1/mobile/wallet/enquiry' $r '-> transaction /nuban/9psb/validate'
Write-Output ('G2 status=' + $r.status)

# G3 Wallet debit (9PSB)
$r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/wallet/debit' -Body @{ wallet_provider='9psb'; accountNo='1100091317'; totalAmount=5; transactionId=('E2ED' + (Get-Uuid).Replace('-','').Substring(0,12)) }
Add-R 'G3' 'Wallet debit' '9PSB' 'POST /api/v1/mobile/wallet/debit' $r '-> transaction /nuban/wallet/debit'
Write-Output ('G3 status=' + $r.status)

# G4 Wallet credit (9PSB)
$r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/wallet/credit' -Body @{ wallet_provider='9psb'; accountNo='1100091317'; totalAmount=5; transactionId=('E2EC' + (Get-Uuid).Replace('-','').Substring(0,12)) }
Add-R 'G4' 'Wallet credit' '9PSB' 'POST /api/v1/mobile/wallet/credit' $r '-> transaction /nuban/wallet/credit'
Write-Output ('G4 status=' + $r.status)

# G5 Bills categories (VTPass)
$r = Call-JavaGw -Method 'GET' -Path '/api/v1/mobile/bills/categories' -Idem $false
Add-R 'G5' 'Bills categories' 'VTPass' 'GET /api/v1/mobile/bills/categories' $r '-> vtpass categories'
Write-Output ('G5 status=' + $r.status)

# G6 Bills services (VTPass)
$r = Call-JavaGw -Method 'GET' -Path '/api/v1/mobile/bills/services?category=airtime' -Idem $false
Add-R 'G6' 'Bills services' 'VTPass' 'GET /api/v1/mobile/bills/services' $r '-> vtpass services'
Write-Output ('G6 status=' + $r.status)

# G7 Bills variations (VTPass)
$r = Call-JavaGw -Method 'GET' -Path '/api/v1/mobile/bills/variations?service_id=mtn-data' -Idem $false
Add-R 'G7' 'Bills variations' 'VTPass' 'GET /api/v1/mobile/bills/variations' $r '-> vtpass variations'
Write-Output ('G7 status=' + $r.status)

# G8-G13 Bills pay (9PSB VAS via gateway)
$bills = @(
  @{ id='G8';  type='Airtime';     body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='airtel'; amount=100; phone='08011111111' } },
  @{ id='G9';  type='Data';        body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='mtn-data'; amount=100; phone='08011111111'; billersCode='08011111111'; variation_code='mtn-10mb-100' } },
  @{ id='G10'; type='Electricity'; body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='ikeja-electric'; amount=1000; phone='08011111111'; billersCode='1111111111111'; variation_code='prepaid' } },
  @{ id='G11'; type='Cable TV';    body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='dstv'; amount=9600; phone='08011111111'; billersCode='1212121212'; variation_code='dstv-compact' } },
  @{ id='G12'; type='Education';   body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='jamb'; amount=7700; phone='08011111111'; billersCode='0123456789'; variation_code='utme-mock' } },
  @{ id='G13'; type='Insurance';   body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='ui-insure'; amount=5000; phone='08011111111'; billersCode='ABC123XY'; variation_code='2' } }
)
foreach ($b in $bills) {
  $r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/bills/pay' -Body $b.body
  Add-R $b.id $b.type '9PSB VAS/VTPass' 'POST /api/v1/mobile/bills/pay' $r '-> transaction /vas/9psb/<channel>'
  Write-Output ($b.id + ' status=' + $r.status)
}

# G14 Bank transfer (9PSB via gateway)
$r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/transfer/bank' -Body @{ wallet_provider='9psb'; wallet_account='1100091317'; recipient_account='0123456789'; bank_code='058'; amount=100; narration='java gateway e2e' }
Add-R 'G14' 'Bank transfer' '9PSB' 'POST /api/v1/mobile/transfer/bank' $r '-> transaction /nuban/9psb/transfer'
Write-Output ('G14 status=' + $r.status)

# G15 Transfer banks (9PSB)
$r = Call-JavaGw -Method 'GET' -Path '/api/v1/mobile/transfer/banks?wallet_provider=9psb' -Idem $false
Add-R 'G15' 'Transfer banks' '9PSB' 'GET /api/v1/mobile/transfer/banks' $r '-> transaction /nuban/9psb/banks'
Write-Output ('G15 status=' + $r.status)

# G16 Transfer lookup (9PSB name enquiry)
$r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/transfer/lookup' -Body @{ wallet_provider='9psb'; account_number='0123456789'; bank_code='058'; source_account='1100091317' }
Add-R 'G16' 'Transfer lookup' '9PSB' 'POST /api/v1/mobile/transfer/lookup' $r '-> transaction /nuban/9psb/lookup'
Write-Output ('G16 status=' + $r.status)

$results | ConvertTo-Json -Depth 5 | Set-Content -Path $outFile -Encoding UTF8
Write-Output ('RESULTS written to ' + $outFile)
