<?php
// tx-observe.php — focused run: 4 transactions through fixpay to observe on the
// payfixy gateway (java-gateway-e2e) and TMS (antifraud-service).
//   T1 wallet open   -> gateway score aml=true  fraud=false
//   T2 airtime bill  -> gateway ingest (velocity only)
//   T3 transfer 150k -> gateway score aml=false fraud=true
//   T4 transfer 1.5M -> gateway ingest velocityBreach=true (sum_1h>500000)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
$base = 'http://api.fixpay.test/api';
$results = [];
$db = new PDO("pgsql:host=db;port=5432;dbname=fixpay", "fixpayuser", "secretpassword");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function call(string $method, string $url, ?array $body = null, array $headers = []): array {
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 60]];
    $allHeaders = array_merge(['Content-Type: application/json'], $headers);
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $allHeaders[] = 'X-Idempotency-Key: ' . uuid();
    }
    $opts['http']['header'] = implode("\r\n", $allHeaders);
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body);
    }
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; }
    }
    return ['status' => $status, 'body' => json_decode($raw ?: 'null', true), 'raw' => $raw];
}

function uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function guardHeaders(string $device, ?string $token = null): array {
    $h = [
        'X-Device-ID: ' . $device,
        'X-Device-Id: ' . $device,
        'X-Location-Lat: 6.5244',
        'X-Location-Lng: 3.3792',
        'X-Request-Timestamp: ' . time(),
        'X-Request-Nonce: ' . $device . bin2hex(random_bytes(10)),
    ];
    if ($token) { $h[] = 'Authorization: Bearer ' . $token; }
    return $h;
}

function record(string $id, string $type, string $proc, array $r, string $note = ''): void {
    global $results;
    $body = $r['body'] ?? [];
    $ref = $body['payment_reference'] ?? $body['transfer_reference'] ?? $body['reference']
        ?? $body['data']['accountNumber'] ?? null;
    $msg = is_string($body['message'] ?? null) ? $body['message'] : ($r['raw'] ?? '');
    $results[] = ['id' => $id, 'type' => $type, 'processor' => $proc, 'http' => $r['status'],
        'ref' => $ref, 'note' => $note, 'message' => substr((string) $msg, 0, 140)];
    echo "$id $type http=" . $r['status'] . " ref=" . ($ref ?? '?') . " msg=" . substr((string) $msg, 0, 90) . "\n";
}

// ── Auth ────────────────────────────────────────────────────────────────────
$email = 'observe.' . time() . '@fixpay.test';
$phone = '070' . substr((string) (time() % 100000000), 0, 8);
echo "=== AUTH user=$email\n";
$r = call('POST', "$base/auth/register", ['phone' => $phone, 'email' => $email,
    'first_name' => 'Observe', 'last_name' => 'Test', 'password' => 'ObservePass1!']);
echo "register http={$r['status']}\n";
$r = call('POST', "$base/auth/verify-otp", ['identifier' => $email, 'purpose' => 'verification', 'code' => '123456']);
$r = call('POST', "$base/auth/login", ['identifier' => $email, 'password' => 'ObservePass1!']);
$token = $r['body']['access_token'] ?? '';
if (!$token) { echo "FATAL: no token\n"; exit(1); }
$r = call('POST', "$base/auth/pin/set", ['pin' => '123456', 'pin_confirmation' => '123456'], ['Authorization: Bearer ' . $token]);
echo "auth ok token=" . ($token ? 'yes' : 'no') . "\n";

// ── DB: KYC fixture + capture user id ───────────────────────────────────────
$stmt = $db->prepare("SELECT id FROM app_users WHERE email = ?");
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();
$db->prepare("UPDATE app_users SET kyc_status='VERIFIED' WHERE id = ?")->execute([$userId]);
$db->prepare("INSERT INTO kyc_verifications (id, user_id, type, provider, verification_status, verified_at, created_at, updated_at)
              VALUES (gen_random_uuid(), ?, 'BVN', 'mock', 'VERIFIED', now(), now(), now())")->execute([$userId]);
echo "fixtures set user=$userId\n";

// ── T1: 9PSB wallet open (via gateway) ──────────────────────────────────────
$r = call('POST', "$base/wallet/ninepsb/create", ['terms_accepted' => true, 'bvn' => '12345678901'],
    guardHeaders('observe-dev-01', $token));
record('T1', 'Wallet open', '9PSB', $r, 'gateway /wallet/open -> score aml=true fraud=false');

// Fund + point the wallet at the sandbox account so bill/transfer items pass hold.
$db->prepare("UPDATE wallets SET balance_kobo=2000000000, ledger_balance_kobo=2000000000,
              wallet_provider='ninepsb', ninepsb_account_number='1100091317', status='ACTIVE'
              WHERE user_id = ?")->execute([$userId]);
echo "   wallet funded\n";

sleep(14);

// ── T2: Airtime bill ₦100 (via gateway) ─────────────────────────────────────
$r = call('POST', "$base/payments/vtpass", [
    'service_id' => 'mtn', 'amount_kobo' => 10000, 'phone' => '08011111111', 'billers_code' => '08011111111',
], array_merge(guardHeaders('observe-dev-01', $token), ['X-Pin-Token: observe-pin']));
record('T2', 'Airtime bill', 'VTPass', $r, 'gateway /bills/pay -> ingest (velocity only)');

sleep(14);

// ── T3: Transfer ₦150k (fraud-only) ─────────────────────────────────────────
$db->prepare("UPDATE wallets SET balance_kobo=2000000000, ledger_balance_kobo=2000000000 WHERE user_id = ?")->execute([$userId]);
$r = call('POST', "$base/transfers/bank", [
    'amount_kobo' => 15000000, 'account_number' => '0123456789', 'bank_code' => '058', 'narration' => 'observe T3',
], guardHeaders('observe-dev-01', $token));
record('T3', 'Transfer 150k', '9PSB', $r, 'gateway /transfer/bank -> score aml=false fraud=true');

sleep(14);

// ── T4: Transfer ₦1.5M (velocity block) ─────────────────────────────────────
$db->prepare("UPDATE wallets SET balance_kobo=2000000000, ledger_balance_kobo=2000000000 WHERE user_id = ?")->execute([$userId]);
$r = call('POST', "$base/transfers/bank", [
    'amount_kobo' => 150000000, 'account_number' => '0123456789', 'bank_code' => '058', 'narration' => 'observe T4',
], guardHeaders('observe-dev-01', $token));
record('T4', 'Transfer 1.5M', '9PSB', $r, 'gateway /transfer/bank -> ingest sum_1h>500000 BLOCK');

// ── Output ──────────────────────────────────────────────────────────────────
echo "\n=== OBSERVE_RESULTS_JSON_BEGIN ===\n";
echo json_encode(['user_id' => $userId, 'email' => $email, 'results' => $results], JSON_PRETTY_PRINT);
echo "\n=== OBSERVE_RESULTS_JSON_END ===\n";

