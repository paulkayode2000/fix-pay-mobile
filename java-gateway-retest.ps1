# java-gateway-retest.ps1 — full retest of previously-failed items through the Java gateway
$ErrorActionPreference = 'Continue'
$base = 'http://localhost:8080'
$outFile = 'C:\Users\kolugbenga\fixpay-mobile\java-gateway-retest-results.json'
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
# wait for gateway
for ($i = 0; $i -lt 20; $i++) { try { $r = Invoke-WebRequest -Uri "$base/actuator/health" -UseBasicParsing -TimeoutSec 6; if ($r.StatusCode -eq 200) { break } } catch {}; Start-Sleep -Seconds 10 }
$results = [System.Collections.ArrayList]::new()
function Add-R { param($Id, $Type, $Route, $Resp, $Note)
  [void]$results.Add([pscustomobject]@{ id=$Id; type=$Type; route=$Route; http=$Resp.status; ms=$Resp.ms; body=$Resp.body; note=$Note })
}

# Bills pay — all 6 billers (previously all 500)
$bills = @(
  @{ id='R1';  type='Airtime';     body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='airtel'; amount=100; phone='08011111111' } },
  @{ id='R2';  type='Data';        body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='mtn-data'; amount=100; phone='08011111111'; billersCode='08011111111'; variation_code='mtn-10mb-100' } },
  @{ id='R3';  type='Electricity'; body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='ikeja-electric'; amount=1000; phone='08011111111'; billersCode='1111111111111'; variation_code='prepaid' } },
  @{ id='R4';  type='Cable TV';    body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='dstv'; amount=9600; phone='08011111111'; billersCode='1212121212'; variation_code='dstv-compact' } },
  @{ id='R5';  type='Education';   body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='jamb'; amount=7700; phone='08011111111'; billersCode='0123456789'; variation_code='utme-mock' } },
  @{ id='R6';  type='Insurance';   body=@{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='1100091317'; service_id='ui-insure'; amount=5000; phone='08011111111'; billersCode='ABC123XY'; variation_code='2' } }
)
foreach ($b in $bills) {
  $r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/bills/pay' -Body $b.body
  Add-R $b.id $b.type 'POST /api/v1/mobile/bills/pay' $r 'bills/pay'
  Write-Output ($b.id + ' ' + $b.type + ' status=' + $r.status)
}

# Transfer (funded wallet, correct name)
$r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/transfer/bank' -Body @{ wallet_provider='9psb'; wallet_account='1100091317'; recipient_account='1100011303'; bank_code='120001'; amount=100; recipient_name='FATAI GANIU' }
Add-R 'R7' 'Bank transfer' 'POST /api/v1/mobile/transfer/bank' $r 'transfer/bank'
Write-Output ('R7 transfer status=' + $r.status)

# Lookup (known 9PSB account)
$r = Call-JavaGw -Method 'POST' -Path '/api/v1/mobile/transfer/lookup' -Body @{ wallet_provider='9psb'; source_account='1100091317'; account_number='1100011303'; bank_code='120001'; sender_name='Payfixy Test' }
Add-R 'R8' 'Transfer lookup' 'POST /api/v1/mobile/transfer/lookup' $r 'transfer/lookup'
Write-Output ('R8 lookup status=' + $r.status)

$results | ConvertTo-Json -Depth 5 | Set-Content -Path $outFile -Encoding UTF8
Write-Output ('RESULTS written to ' + $outFile)
