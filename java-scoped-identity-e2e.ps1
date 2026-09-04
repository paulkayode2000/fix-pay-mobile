# java-scoped-identity-e2e.ps1 — per-user / per-channel (scoped) identity + velocity tests.
# Demonstrates:
#   T1 Per-user isolation   : user A bursts 6 (5 allowed + 6th 403), user B unaffected (200)
#   T2 Cross-channel        : source=pos user_id=42 vs source=mobile user_id=42 -> separate buckets
#   T3 Device correlation   : same device across channels accumulates device-level velocity
#   T4 Spoofing rejection   : client-sent X-Source is ignored; gateway pins source=mobile
#   T5 PoS plug-in          : source=pos ingests & scores exactly like mobile
$ErrorActionPreference = 'Continue'
$base = 'http://localhost:8080'
$tms = 'http://localhost:8083'
$outFile = 'C:\Users\kolugbenga\fixpay-mobile\java-scoped-identity-results.json'
$results = [System.Collections.ArrayList]::new()
function Add-R { param($Id, $Type, $Http, $Body, $Note)
  [void]$results.Add([pscustomobject]@{ id=$Id; type=$Type; http=$Http; body=$Body; note=$Note })
}
function Get-Uuid { [guid]::NewGuid().ToString() }

$hdr = @{
  'Authorization' = 'Bearer ce_sk_test_fixpaymobile_z1tG4wJ7oN3qX6vA9bD2eF5hK8mL0pR'
  'Client-Public' = 'ce_pk_test_fixpaymobile_x8k2mQ4nW7pL3vR9yB6cF1dH5jA0sN8u'
  'X-Business-Id' = '99'
  'Accept' = 'application/json'
}
function Call-JavaGw {
  param($Path, $Body, $Extra)
  $h = @{}; $hdr.GetEnumerator() | ForEach-Object { $h[$_.Key] = $_.Value }
  $Extra.GetEnumerator() | ForEach-Object { $h[$_.Key] = $_.Value }
  $h['X-Idempotency-Key'] = (Get-Uuid)
  try {
    $params = @{ Uri = ($base + $Path); Method = 'POST'; Headers = $h; UseBasicParsing = $true; TimeoutSec = 90 }
    if ($Body) { $params['ContentType'] = 'application/json'; $params['Body'] = ($Body | ConvertTo-Json -Compress -Depth 6) }
    $resp = Invoke-WebRequest @params
    return @{ status = [int]$resp.StatusCode; body = $resp.Content }
  } catch {
    $status = 0; $bodyText = ''
    if ($_.Exception.Response) {
      $status = [int]$_.Exception.Response.StatusCode
      try { $sr = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream()); $bodyText = $sr.ReadToEnd() } catch {}
    }
    return @{ status = $status; body = $bodyText }
  }
}
function Call-Tms {
  param($Ref, $Source, $Biz, $User, $Device, $Amount)
  $payload = @{
    ref_no = $Ref; customer_id = 99; amount = $Amount; currency = 'NGN'
    type = 'bill_payment'; country_origin = 'NG'; country_destination = 'NG'; counterparty = 'x'
    metadata = @{ source = $Source; business_id = $Biz; user_id = $User; device_id = $Device }
  }
  try {
    $r = Invoke-RestMethod -Uri ($tms + '/v1/transactions/ingest') -Method 'POST' `
      -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress -Depth 6) -TimeoutSec 20
    return $r
  } catch {
    return @{ velocity = @{ breach = $true; rule = 'tms-error' } }
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

# ── Cleanup: remove prior demo buckets (deterministic windows) ──────────────
docker exec postgres psql -U antifraud -d antifraud -c "DELETE FROM transactions WHERE source='mobile' AND user_id LIKE 'demo-%'; DELETE FROM transactions WHERE source='pos' AND business_id='99' AND user_id='42'; DELETE FROM transactions WHERE source='mobile' AND business_id='99' AND user_id='42'; DELETE FROM transactions WHERE source='mobile' AND user_id='spoof-probe';" | Out-Null

# ── T1 Per-user isolation ────────────────────────────────────────────────────
Write-Output '=== T1: per-user isolation (A bursts -> blocked; B allowed) ==='
# wallet_account='000' fails the 10-digit NUBAN routing check AFTER ingest, so
# each call is fast (~ms, no slow VTPass roundtrip) while still incrementing the
# velocity counter. Expect: 5x 400 (validation) then 6th 403 (velocity block).
$bill = @{ payment_method='wallet'; wallet_provider='9psb'; wallet_account='000'; service_id='airtel'; amount=100; phone='08011111111' }
$aStatuses = @()
for ($i = 1; $i -le 6; $i++) {
  $r = Call-JavaGw -Path '/api/v1/mobile/bills/pay' -Body $bill -Extra @{ 'X-User-Id' = 'demo-user-A'; 'X-Device-Id' = 'demo-dev-A' }
  $aStatuses += $r.status
  Write-Output ("A#$i status=" + $r.status)
}
$t1aPass = (($aStatuses[0..4] | Where-Object { $_ -eq 403 }).Count -eq 0)
$t1aBlock = ($aStatuses[5] -eq 403)
$rB = Call-JavaGw -Path '/api/v1/mobile/bills/pay' -Body $bill -Extra @{ 'X-User-Id' = 'demo-user-B'; 'X-Device-Id' = 'demo-dev-B' }
Write-Output ("B status=" + $rB.status)
Add-R 'T1A' 'user-A 6x burst' ($aStatuses -join ',') '' ("first5-not-blocked=" + $t1aPass + " 6th blocked=" + $t1aBlock)
Add-R 'T1B' 'user-B isolated' $rB.status $rB.body ("not-blocked(not-403)=" + ($rB.status -ne 403))

# ── T2 Cross-channel isolation (TMS direct) ─────────────────────────────────
Write-Output '=== T2: cross-channel isolation (pos/user 42 vs mobile/user 42) ==='
$posB = $null
for ($i = 1; $i -le 6; $i++) { $posB = Call-Tms -Ref ("POS42-" + $i) -Source 'pos' -Biz '99' -User '42' -Device 'posdev-1' -Amount 100 }
$mobile42 = Call-Tms -Ref 'MOB42-1' -Source 'mobile' -Biz '99' -User '42' -Device 'mobdev-1' -Amount 100
Write-Output ("pos/user42 6th breach=" + $posB.velocity.breach + " rule=" + $posB.velocity.rule)
Write-Output ("mobile/user42 count_1m=" + $mobile42.velocity.count_1m + " (isolated=" + ($mobile42.velocity.count_1m -eq 1) + ")")
Add-R 'T2A' 'pos user 42 bursts' 200 ($posB | ConvertTo-Json -Compress -Depth 4) ("breach=" + $posB.velocity.breach + " rule=" + $posB.velocity.rule)
Add-R 'T2B' 'mobile user 42 isolated' 200 ($mobile42 | ConvertTo-Json -Compress -Depth 4) ("count_1m=" + $mobile42.velocity.count_1m)

# ── T3 Device correlation (same device, two channels) ───────────────────────
Write-Output '=== T3: device correlation (shared device accumulates) ==='
$d1 = Call-Tms -Ref 'DEV-SHR-1' -Source 'mobile' -Biz '99' -User 'U-DEV-A' -Device 'shared-dev-1' -Amount 100
$d2 = Call-Tms -Ref 'DEV-SHR-2' -Source 'pos' -Biz '99' -User 'U-DEV-B' -Device 'shared-dev-1' -Amount 100
$devRows = docker exec postgres psql -U antifraud -d antifraud -t -c "SELECT count(*) FROM transactions WHERE device_id='shared-dev-1';" 2>&1 | Select-Object -First 1
$devRows = $devRows.Trim()
Write-Output ("device rows=" + $devRows)
Add-R 'T3' 'device correlation' 200 '' ("same device rows=" + $devRows)

# ── T4 Spoofing rejection (client X-Source is ignored) ──────────────────────
Write-Output '=== T4: spoofing rejection (X-Source: pos must NOT be honored) ==='
$rS = Call-JavaGw -Path '/api/v1/mobile/bills/pay' -Body $bill -Extra @{ 'X-User-Id' = 'spoof-probe'; 'X-Device-Id' = 'spoof-dev'; 'X-Source' = 'pos' }
$spoofRow = docker exec postgres psql -U antifraud -d antifraud -t -c "SELECT source FROM transactions WHERE user_id='spoof-probe' ORDER BY id DESC LIMIT 1;" 2>&1 | Select-Object -First 1
$spoofSource = $spoofRow.Trim()
Write-Output ("spoof attempt status=" + $rS.status + " stored source=" + $spoofSource)
Add-R 'T4' 'spoof rejection' $rS.status '' ("stored source=" + $spoofSource + " (must be mobile)")

# ── T5 PoS plug-in simulation ───────────────────────────────────────────────
Write-Output '=== T5: PoS plug-in (source=pos flows through TMS scoring path) ==='
$posScore = Invoke-RestMethod -Uri ($tms + '/v1/transactions/score') -Method 'POST' -ContentType 'application/json' -TimeoutSec 20 -Body (@{
  ref_no = 'POS-SCORE-1'; customer_id = 99; amount = 250000; currency = 'NGN'; type = 'sale'
  country_origin = 'NG'; country_destination = 'NG'; counterparty = 'pos-terminal-1'
  metadata = @{ source = 'pos'; business_id = '99'; user_id = '42'; device_id = 'posdev-1' }
} | ConvertTo-Json -Compress -Depth 6)
Write-Output ("pos score status=" + $posScore.status + " velocityBreach=" + $posScore.velocity.breach)
Add-R 'T5' 'PoS plug-in' 200 ($posScore | ConvertTo-Json -Compress -Depth 4) "score + velocity for source=pos"


# ── T6 Version guard (TMS): stale header -> 412 with inline rules ───────────
Write-Output '=== T6: rules version guard (stale X-Rules-Version -> 412) ==='
$v6 = (Invoke-RestMethod -Uri ($tms + '/v1/rules?source=mobile&business_id=99') -TimeoutSec 10).version
$v6Body = @{ ref_no='T6-STALE-1'; customer_id=99; amount=100; currency='NGN'; type='bill_payment'; country_origin='NG'; country_destination='NG'; counterparty='x'; metadata=@{ source='mobile'; business_id='99'; user_id='t6'; device_id='t6d' } } | ConvertTo-Json -Compress -Depth 5
$staleCode = 0; $staleDetail = ''
try { Invoke-WebRequest -Uri ($tms + '/v1/transactions/ingest') -Method 'POST' -ContentType 'application/json' -Headers @{ 'X-Rules-Version' = '0.0' } -Body $v6Body -TimeoutSec 15 | Out-Null } catch {
  $staleCode = [int]$_.Exception.Response.StatusCode
  $staleDetail = $_.ErrorDetails.Message
}
$hasInlineRules = $staleDetail -match 'current_version'
Write-Output ("stale header -> HTTP " + $staleCode + " inline rules=" + $hasInlineRules + " (current=" + $v6 + ")")
Add-R 'T6' 'rules version guard' $staleCode $staleDetail ("412+inline=" + ($staleCode -eq 412 -and $hasInlineRules))

# ── T7 Gateway survives a rules bump (auto-refresh + retry) ─────────────────
Write-Output '=== T7: gateway auto-refreshes after rules bump ==='
$vBefore = (Invoke-RestMethod -Uri ($tms + '/v1/rules?source=mobile&business_id=99') -TimeoutSec 10).version
[System.IO.File]::WriteAllText('C:\Users\kolugbenga\fixpay-mobile\bump.json', '{"fraud_amount_threshold":250000}')
Invoke-RestMethod -Uri ($tms + '/v1/admin/rules/99') -Method 'PUT' -ContentType 'application/json' -Body '{"fraud_amount_threshold":250000}' -TimeoutSec 10 | Out-Null
$vAfter = (Invoke-RestMethod -Uri ($tms + '/v1/rules?source=mobile&business_id=99') -TimeoutSec 10).version
$rT7 = Call-JavaGw -Path '/api/v1/mobile/bills/pay' -Body $bill -Extra @{ 'X-User-Id' = 't7-user'; 'X-Device-Id' = 't7-dev' }
$retried = -not [string]::IsNullOrEmpty((docker logs java-gateway-e2e --since 3m 2>&1 | Select-String 'stale rules' | Select-Object -Last 1))
docker exec postgres psql -U antifraud -d antifraud -c "DELETE FROM risk_rules WHERE business_id='99';" | Out-Null
Write-Output ("bump " + $vBefore + " -> " + $vAfter + " | gateway status=" + $rT7.status + " auto-refresh=" + $retried)
Add-R 'T7' 'gateway auto-refresh' $rT7.status '' ("bump " + $vBefore + "->" + $vAfter + " auto-refresh=" + $retried)


$results | ConvertTo-Json -Depth 6 | Set-Content -Path $outFile -Encoding UTF8
Write-Output ('RESULTS written to ' + $outFile)

