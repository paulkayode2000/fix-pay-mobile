<?php
// tx-matrix.php — transaction matrix: fixpay-mobile -> gateway -> processors -> TMS.
// Runs inside fixpay-backend (tms_default) so api.fixpay.test resolves port-free.
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
        ?? $body['data']['accountNumber'] ?? $body['data']['reference'] ?? $body['data']['account_number'] ?? null;
    $msg = is_string($body['message'] ?? null) ? $body['message'] : ($r['raw'] ?? '');
    $results[] = ['id' => $id, 'type' => $type, 'processor' => $proc, 'http' => $r['status'],
        'ref' => $ref, 'note' => $note, 'message' => substr((string) $msg, 0, 160)];
    echo "$id $type http=" . $r['status'] . " ref=" . ($ref ?? '?') . " msg=" . substr((string) $msg, 0, 90) . "\n";
}

// ── Auth setup ───────────────────────────────────────────────────────────────
$email = 'matrix.' . time() . '@fixpay.test';
$phone = '070' . substr((string) (time() % 100000000), 0, 8);
echo "=== AUTH user=$email\n";

$r = call('POST', "$base/auth/register", [
    'phone' => $phone, 'email' => $email,
    'first_name' => 'Matrix', 'last_name' => 'Test', 'password' => 'MatrixPass1!',
]);
echo "register http={$r['status']} " . ($r['body']['message'] ?? '') . "\n";

$r = call('POST', "$base/auth/verify-otp", ['identifier' => $email, 'purpose' => 'verification', 'code' => '123456']);
echo "verify-otp http={$r['status']}\n";

$r = call('POST', "$base/auth/login", ['identifier' => $email, 'password' => 'MatrixPass1!']);
$token = $r['body']['access_token'] ?? '';
echo "login http={$r['status']} token=" . ($token ? 'yes' : 'NO') . "\n";
if (!$token) { echo "FATAL: no token\n"; exit(1); }

$r = call('POST', "$base/auth/pin/set", ['pin' => '123456', 'pin_confirmation' => '123456'], ['Authorization: Bearer ' . $token]);
echo "pin-set http={$r['status']}\n";

// ── DB: KYC-verify the test user (test fixture, direct DB) ──────────────────
$stmt = $db->prepare("SELECT id FROM app_users WHERE email = ?");
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();
$db->prepare("UPDATE app_users SET kyc_status='VERIFIED' WHERE id = ?")->execute([$userId]);
$db->prepare("INSERT INTO kyc_verifications (id, user_id, type, provider, verification_status, verified_at, created_at, updated_at)
              VALUES (gen_random_uuid(), ?, 'BVN', 'mock', 'VERIFIED', now(), now(), now())")->execute([$userId]);
echo "KYC fixture set for user $userId\n";

// ── M1 9PSB wallet create (via gateway) ─────────────────────────────────────
$r = call('POST', "$base/wallet/ninepsb/create", ['terms_accepted' => true, 'bvn' => '12345678901'],
    guardHeaders('matrix-dev-01', $token));
$acct = $r['body']['data']['account_number'] ?? $r['body']['data']['ninepsb_account_number'] ?? null;
record('M1', 'Wallet create', '9PSB', $r, 'wallet/ninepsb/create -> gateway /wallet/open');
if ($acct) { echo "   new 9PSB account=$acct\n"; }

// Fund + point the wallet at the funded sandbox account for payment items.
$db->prepare("UPDATE wallets SET balance_kobo=2000000000, ledger_balance_kobo=2000000000,
              wallet_provider='ninepsb', ninepsb_account_number='1100091317', status='ACTIVE'
              WHERE user_id = ?")->execute([$userId]);
echo "   wallet funded + pointed at sandbox account 1100091317\n";

// ── M2 9PSB wallet enquiry (via gateway) ────────────────────────────────────
sleep(12);
$r = call('GET', "$base/wallet/ninepsb/enquiry", null, guardHeaders('matrix-dev-01', $token));
record('M2', 'Wallet enquiry', '9PSB', $r, 'wallet/ninepsb/enquiry -> gateway /wallet/enquiry');

// Re-fund the local wallet AFTER the enquiry (it syncs the provider balance over
// the DB funding) so the transfer items pass the local hold() and reach the
// gateway. The provider-side balance is what it is; provider failures are
// tabulated honestly.
$db->prepare("UPDATE wallets SET balance_kobo=2000000000, ledger_balance_kobo=2000000000 WHERE user_id = ?")->execute([$userId]);
echo "   wallet re-funded after enquiry\n";

// ── M3–M8 VTPass bill payments (via gateway) ────────────────────────────────
$bills = [
    ['id' => 'M3', 'type' => 'Airtime',     'body' => ['service_id' => 'mtn',        'amount_kobo' => 10000, 'phone' => '08011111111', 'billers_code' => '08011111111']],
    ['id' => 'M4', 'type' => 'Data',        'body' => ['service_id' => 'mtn-data',   'amount_kobo' => 10000, 'phone' => '08011111111', 'billers_code' => '08011111111', 'variation_code' => 'mtn-10mb-100']],
    ['id' => 'M5', 'type' => 'Electricity', 'body' => ['service_id' => 'ikeja-electric', 'amount_kobo' => 100000, 'phone' => '08011111111', 'billers_code' => '1111111111111', 'variation_code' => 'prepaid']],
    ['id' => 'M6', 'type' => 'Cable TV',    'body' => ['service_id' => 'dstv',       'amount_kobo' => 100000, 'phone' => '08011111111', 'billers_code' => '1212121212', 'variation_code' => 'dstv-compact']],
    ['id' => 'M7', 'type' => 'Education',   'body' => ['service_id' => 'jamb',       'amount_kobo' => 100000, 'phone' => '08011111111', 'billers_code' => '0123456789', 'variation_code' => 'utme-mock']],
    ['id' => 'M8', 'type' => 'Insurance',   'body' => ['service_id' => 'ui-insure',  'amount_kobo' => 100000, 'phone' => '08011111111', 'billers_code' => 'ABC123XY',   'variation_code' => '2']],
];
foreach ($bills as $b) {
    sleep(12);
    $r = call('POST', "$base/payments/vtpass", $b['body'], array_merge(guardHeaders('matrix-dev-01', $token), ['X-Pin-Token: matrix-pin']));
    record($b['id'], $b['type'], 'VTPass', $r, 'payments/vtpass -> gateway /bills/pay');
}


// ── M9 VTPass meter/verification (Laravel -> VTPass direct, no gateway) ─────
sleep(12);
$r = call('POST', "$base/payments/verify", ['service_id' => 'ikeja-electric', 'billers_code' => '1111111111111', 'type' => 'prepaid'], ['Authorization: Bearer ' . $token]);
record('M9', 'Meter verify', 'VTPass', $r, 'payments/verify (direct, bypasses gateway)');

// ── M10 Bank transfer ₦150k (fraud-only: 100k <= 150k < 1M) ────────────────
sleep(12);
$db->prepare("UPDATE wallets SET balance_kobo=2000000000, ledger_balance_kobo=2000000000 WHERE user_id = ?")->execute([$userId]);
$r = call('POST', "$base/transfers/bank", [
    'amount_kobo' => 15000000, 'account_number' => '0123456789', 'bank_code' => '058', 'narration' => 'matrix M10',
], guardHeaders('matrix-dev-01', $token));
record('M10', 'Bank transfer', '9PSB', $r, 'transfers/bank -> gateway /transfer/bank; fraud_check only');

// ── M11 Account lookup (name enquiry) ───────────────────────────────────────
sleep(12);
$r = call('POST', "$base/transfers/verify-account", ['accountNumber' => '0123456789', 'bankCode' => '058'], ['Authorization: Bearer ' . $token]);
record('M11', 'Account lookup', '9PSB', $r, 'transfers/verify-account -> gateway /transfer/lookup');

// ── M12 Wallet-to-wallet transfer ───────────────────────────────────────────
sleep(12);
$r = call('POST', "$base/transfers/wallet", ['amount_kobo' => 50000, 'recipient_phone' => '08022222222', 'narration' => 'matrix M12'], ['Authorization: Bearer ' . $token]);
record('M12', 'Wallet transfer', 'internal', $r, 'transfers/wallet (internal)');

// ── M13 Alternative payment (stub) ──────────────────────────────────────────
sleep(12);
$r = call('POST', "$base/payments/alternative/initiate", ['amount_kobo' => 50000, 'channel' => 'card'], ['Authorization: Bearer ' . $token]);
record('M13', 'Alternative init', 'Paystack', $r, 'payments/alternative/initiate (stub)');

// ── M14 Transfer ₦1.5M (AML + fraud both: >= 1M AML and >= 100k fraud) ─────
sleep(12);
$db->prepare("UPDATE wallets SET balance_kobo=2000000000, ledger_balance_kobo=2000000000 WHERE user_id = ?")->execute([$userId]);
$r = call('POST', "$base/transfers/bank", [
    'amount_kobo' => 150000000, 'account_number' => '0123456789', 'bank_code' => '058', 'narration' => 'matrix M14 AML probe',
], guardHeaders('matrix-dev-01', $token));
record('M14', 'Bank transfer (AML+fraud)', '9PSB', $r, '1.5M -> aml_check=true fraud_check=true');

// ── M15 Transfer ₦2M with fraud threshold bumped -> AML-only ────────────────
$tms = 'http://antifraud.tms.test';
call('PUT', "$tms/v1/admin/rules/99", ['fraud_amount_threshold' => 5000000]);
sleep(5); // gateway TTL / 412 auto-refresh handles the version bump
$db->prepare("UPDATE wallets SET balance_kobo=2000000000, ledger_balance_kobo=2000000000 WHERE user_id = ?")->execute([$userId]);
$r = call('POST', "$base/transfers/bank", [
    'amount_kobo' => 200000000, 'account_number' => '0123456789', 'bank_code' => '058', 'narration' => 'matrix M15 AML-only probe',
], guardHeaders('matrix-dev-01', $token));
record('M15', 'Bank transfer (AML-only)', '9PSB', $r, '2M, fraud threshold 5M -> aml_check=true fraud_check=false');
call('PUT', "$tms/v1/admin/rules/99", ['fraud_amount_threshold' => 100000]);

// ── Output ──────────────────────────────────────────────────────────────────
echo "\n=== MATRIX_RESULTS_JSON_BEGIN ===\n";
echo json_encode(['user_id' => $userId, 'email' => $email, 'results' => $results], JSON_PRETTY_PRINT);
echo "\n=== MATRIX_RESULTS_JSON_END ===\n";

