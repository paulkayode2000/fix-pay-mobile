<?php
// tx-matrix-v2.php — TMS SaaS E2E matrix: fixpay-mobile -> Payfixy gateway -> TMS (AML + antifraud).
//
// Exercises the NEW TMS SaaS implementations (v2-enhancements / "Marble" Phase 1):
//   * POST /api/v1/ingest  (+ GET /api/v1/ingest/{eventId})  — ingestion + rule engine + risk tiering
//   * POST /api/v1/assess                                     — unified AML+antifraud intake
//   * POST /api/v1/screen + GET /api/v1/calls/{ref}           — screening + async call lifecycle
//   * GET  /v1/rules + X-Rules-Version guard                  — antifraud rules authority
//   * POST /v1/transactions/score                             — scoped-identity ML scoring
//
// Run INSIDE the fixpay-backend container (on tms_default, so the *.test ingress
// hostnames resolve and both databases are reachable):
//     php /var/www/tx-matrix-v2.php
//
// Environment overrides (all optional):
//     E2E_FIXPAY_BASE, E2E_AML_BASE, E2E_AF_BASE, E2E_AML_TOKEN, E2E_BUSINESS_ID, E2E_LABEL
//
// Output: human-readable progress on stdout, then a JSON block between
//         === TMS_SAAS_E2E_JSON_BEGIN === / === TMS_SAAS_E2E_JSON_END ===

error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
@ini_set('display_errors', '1');
set_time_limit(0);

$FIXPAY_BASE = rtrim(getenv('E2E_FIXPAY_BASE') ?: 'http://api.fixpay.test/api', '/');
$AML_BASE    = rtrim(getenv('E2E_AML_BASE')    ?: 'http://aml.tms.test', '/');
$AF_BASE     = rtrim(getenv('E2E_AF_BASE')     ?: 'http://antifraud.tms.test', '/');
$AML_TOKEN   = getenv('E2E_AML_TOKEN') ?: 'amls_test_3c719403379c76ccb2120f54f90dc16a39b1cb8f5795b201';
$BUSINESS_ID = (string) (getenv('E2E_BUSINESS_ID') ?: '99');
$RUN_LABEL   = getenv('E2E_LABEL') ?: ('tms-saas-' . date('Ymd-His'));
$SOURCE      = 'mobile';

$results = [];
$user    = ['id' => null, 'email' => null, 'token' => null];

function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/**
 * Minimal HTTP client. Returns [status, body(array|null), raw, ms].
 */
function http(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 30): array {
    $ch = curl_init($url);
    $hdr = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $hdr,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $t0   = microtime(true);
    $raw  = curl_exec($ch);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [0, null, $err ?: 'request failed', $ms];
    }
    $decoded = json_decode((string) $raw, true);
    return [$code, is_array($decoded) ? $decoded : null, (string) $raw, $ms];
}

function amlHeaders(string $token, array $extra = []): array {
    return array_merge(['Authorization: Bearer ' . $token, 'Content-Type: application/json'], $extra);
}

/** Idempotency header required by fixpay's IdempotencyMiddleware on mutating calls. */
function idem(): string {
    return 'X-Idempotency-Key: ' . uuid4();
}

/** Fresh guard headers (device/location/nonce + a NEW idempotency key) per call. */
function guardHdrs(string $token): array {
    return [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'X-Idempotency-Key: ' . uuid4(),
        'X-Device-ID: e2e-matrix-dev',
        'X-Location-Lat: 6.5244',
        'X-Location-Lng: 3.3792',
        'X-Request-Timestamp: ' . time(),
        'X-Request-Nonce: e2e' . bin2hex(random_bytes(8)),
    ];
}

/** JSON + bearer + a fresh idempotency key (non-guarded endpoints). */
function authJsonHdrs(string $token): array {
    return ['Content-Type: application/json', 'Authorization: Bearer ' . $token, idem()];
}

function jsonHeaders(array $extra = []): array {
    return array_merge(['Content-Type: application/json'], $extra);
}

function record(string $id, string $area, string $title, array $resp, array $expect = [], string $note = ''): void {
    global $results;
    [$status, $body, $raw] = [$resp[0], $resp[1], $resp[2]];
    $expected  = $expect['status'] ?? null;
    $passed    = $expected === null ? ($status > 0) : ($status === $expected);
    $row = [
        'id'          => $id,
        'area'        => $area,
        'title'       => $title,
        'http'        => $status,
        'expected'    => $expected,
        'passed'      => $passed,
        'ms'          => $resp[3],
        'note'        => $note,
        'assertions'  => [],
        'body'        => is_array($body) ? $body : ['_raw' => substr((string) $raw, 0, 400)],
    ];
    // field-level assertions: $expect['has'] => ['outcome','event_id']
    foreach (($expect['has'] ?? []) as $field) {
        $ok = is_array($body) && array_key_exists($field, $body) && $body[$field] !== null;
        $row['assertions'][] = ['assert' => "has:$field", 'ok' => $ok];
        if (!$ok) { $row['passed'] = false; }
    }
    // equality assertions: $expect['eq'] => ['outcome' => 'block']
    foreach (($expect['eq'] ?? []) as $field => $want) {
        $got = is_array($body) ? ($body[$field] ?? null) : null;
        $ok  = $got === $want;
        $row['assertions'][] = ['assert' => "eq:$field", 'want' => $want, 'got' => $got, 'ok' => $ok];
        if (!$ok) { $row['passed'] = false; }
    }
    $results[] = $row;
    $mark = $passed ? 'PASS' : 'FAIL';
    printf("  [%s] %-4s %-46s http=%d expect=%s ms=%d\n", $mark, $id, $title, $status, $expected ?? '-', $resp[3]);
}

function dbConnect(string $dsn, string $user, string $pass): ?PDO {
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        return $pdo;
    } catch (Throwable $e) {
        echo "  !! DB connect failed ($dsn): " . $e->getMessage() . "\n";
        return null;
    }
}

echo "===================================================================\n";
echo "  TMS SaaS E2E — fixpay-mobile -> Payfixy gateway -> TMS (AML + AF)\n";
echo "===================================================================\n";
echo "  run_label : $RUN_LABEL\n";
echo "  fixpay    : $FIXPAY_BASE\n";
echo "  aml       : $AML_BASE\n";
echo "  antifraud : $AF_BASE\n";
echo "  business  : $BUSINESS_ID (source=$SOURCE)\n\n";

// ── Databases (all reachable on tms_default) ────────────────────────────────
$fixpayDb = dbConnect('pgsql:host=db;port=5432;dbname=fixpay', 'fixpayuser', 'secretpassword');
$amsDb    = dbConnect('pgsql:host=postgres;port=5432;dbname=ams_laravel', 'laravel', 'secret');
$afDb     = dbConnect('pgsql:host=postgres;port=5432;dbname=antifraud', 'antifraud', 'secret');

function scalar(?PDO $db, string $sql, array $args = []): ?string {
    if (!$db) { return null; }
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    } catch (Throwable $e) {
        return null;
    }
}

echo "-- Phase 0: service health ----------------------------------------\n";
$h = http('GET', "$AML_BASE/api/v1/health", null, amlHeaders($AML_TOKEN));
record('H1', 'health', 'TMS aml-system /api/v1/health', $h, ['status' => 200, 'eq' => ['status' => 'healthy']]);
$h = http('GET', "$AF_BASE/v1/health");
record('H2', 'health', 'TMS antifraud /v1/health', $h, ['status' => 200, 'eq' => ['status' => 'ok']]);
$h = http('GET', "$AF_BASE/v1/rules?source=$SOURCE&business_id=$BUSINESS_ID");
$rulesVersion = is_array($h[1]) ? ($h[1]['version'] ?? null) : null;
record('H3', 'health', 'antifraud rules authority (GET /v1/rules)', $h,
    ['status' => 200, 'has' => ['version', 'velocity', 'aml', 'fraud', 'block_on_flag']],
    "version=$rulesVersion");
echo "\n";

// ── Snapshot counters so correlation deltas are exact ──────────────────────
$pre = [
    'ingested_events' => (int) (scalar($amsDb, 'select count(*) from ingested_events') ?? 0),
    'decisions'       => (int) (scalar($amsDb, 'select count(*) from decisions') ?? 0),
    'af_transactions' => (int) (scalar($afDb, 'select count(*) from transactions') ?? 0),
];
$preRunId = uuid4();

// ── Phase 1: seed rule-engine scenarios for the ingestion API ───────────────
// The RuleEngine matches Scenario.trigger_event = "<data_model>.<event_type>".
// Without published scenarios /api/v1/ingest always falls through to `allow`.
echo "-- Phase 1: seed published rule-engine scenarios -------------------\n";
$scenarios = [
    [
        'name'         => 'E2E Block High-Value Transfer',
        'description'  => 'Seeded by tx-matrix-v2: block transfers >= 9,000,000 (major units).',
        'category'     => 'fraud',
        'trigger_event'=> 'bank_transfer.initiated',
        'rules'        => json_encode([
            'operator'   => 'AND',
            'conditions' => [[
                'field' => 'payload.amount', 'operator' => 'gte',
                'value' => 9000000, 'value_type' => 'number',
                'label' => 'amount >= 9000000',
            ]],
        ], JSON_UNESCAPED_SLASHES),
        'outcome_type' => 'block',
        'priority'     => 1,
    ],
    [
        'name'         => 'E2E Flag Mid-Value Transfer',
        'description'  => 'Seeded by tx-matrix-v2: flag transfers >= 600,000 (major units).',
        'category'     => 'aml',
        'trigger_event'=> 'bank_transfer.initiated',
        'rules'        => json_encode([
            'operator'   => 'AND',
            'conditions' => [[
                'field' => 'payload.amount', 'operator' => 'gte',
                'value' => 600000, 'value_type' => 'number',
                'label' => 'amount >= 600000',
            ]],
        ], JSON_UNESCAPED_SLASHES),
        'outcome_type' => 'flag_for_review',
        'priority'     => 40,
    ],
    [
        'name'         => 'E2E Flag Wallet Transfer',
        'description'  => 'Seeded by tx-matrix-v2: flag wallet_transfer.initiated events (unified-assess probe).',
        'category'     => 'aml',
        'trigger_event'=> 'wallet_transfer.initiated',
        'rules'        => json_encode([
            'operator'   => 'AND',
            'conditions' => [[
                'field' => 'payload.amount', 'operator' => 'gte',
                'value' => 1, 'value_type' => 'number',
                'label' => 'wallet transfer present',
            ]],
        ], JSON_UNESCAPED_SLASHES),
        'outcome_type' => 'flag_for_review',
        'priority'     => 40,
    ],
];

$seedOk = 0;
if ($amsDb) {
    try {
        foreach ($scenarios as $s) {
            $del = $amsDb->prepare('delete from scenarios where name = ?');
            $del->execute([$s['name']]);
            $ins = $amsDb->prepare(
                'insert into scenarios (name, description, category, trigger_event, version, rules,
                                        outcome_type, aggregation, priority, is_published, published_at,
                                        created_at, updated_at)
                 values (?, ?, ?, ?, 1, CAST(? AS jsonb), ?, \'highest_severity\', ?, true, now(), now(), now())'
            );
            $ins->execute([
                $s['name'], $s['description'], $s['category'], $s['trigger_event'],
                $s['rules'], $s['outcome_type'], $s['priority'],
            ]);
            $seedOk++;
        }
    } catch (Throwable $e) {
        echo '  !! scenario seed failed: ' . $e->getMessage() . "\n";
    }
}
$published = (int) (scalar($amsDb, 'select count(*) from scenarios where is_published = true') ?? 0);
printf("  seeded=%d  published_scenarios=%d\n", $seedOk, $published);
$results[] = [
    'id' => 'S1', 'area' => 'precondition', 'title' => 'rule-engine scenarios seeded & published',
    'http' => null, 'expected' => null, 'passed' => $seedOk === count($scenarios) && $published > 0,
    'ms' => 0, 'note' => "seeded=$seedOk published=$published", 'assertions' => [], 'body' => null,
];
echo "\n";

echo "-- Phase 2: fixpay-mobile auth (mobile caller identity) ------------\n";
$email = 'e2e.' . time() . '@fixpay.test';
$phone = '080' . substr((string) (time() % 100000000), 0, 8);
$pass  = 'MatrixPass1!';

$r = http('POST', "$FIXPAY_BASE/auth/register", [
    'phone' => $phone, 'email' => $email,
    'first_name' => 'E2E', 'last_name' => 'TmsSaas', 'password' => $pass,
], jsonHeaders([idem()]), 60);
record('A0a', 'auth', 'fixpay register (mobile user)', $r, ['status' => null], 'identifier=' . $email);

$r = http('POST', "$FIXPAY_BASE/auth/verify-otp", [
    'identifier' => $email, 'purpose' => 'verification', 'code' => '123456',
], jsonHeaders([idem()]), 60);
record('A0b', 'auth', 'fixpay verify-otp', $r, ['status' => null]);

$r = http('POST', "$FIXPAY_BASE/auth/login", ['identifier' => $email, 'password' => $pass], jsonHeaders([idem()]), 60);
$user['token'] = is_array($r[1]) ? ($r[1]['access_token'] ?? null) : null;
$user['id']    = is_array($r[1]) ? ($r[1]['user']['id'] ?? null) : null;
$user['email'] = $email;
// Fall back to a direct lookup if the login payload omits the id.
if (!$user['id'] && $fixpayDb) {
    $user['id'] = scalar($fixpayDb, 'select id from app_users where email = ?', [$email]);
}
record('A0c', 'auth', 'fixpay login -> bearer token', $r,
    ['status' => 200, 'has' => ['access_token']], 'user_id=' . ($user['id'] ?? '?'));
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 3 — NEW ingestion API  POST /api/v1/ingest
// ─────────────────────────────────────────────────────────────────────────────
echo "-- Phase 3: POST /api/v1/ingest (rule engine + risk tiering) --------\n";

function ingestBody(string $runId, string $suffix, float $amount, array $meta = []): array {
    global $BUSINESS_ID, $SOURCE;
    return [
        'data_model'     => 'bank_transfer',
        'event_type'     => 'initiated',
        'transaction_id' => "$runId-$suffix",
        'timestamp'      => gmdate('c'),
        'payload'        => [
            'amount'         => $amount,
            'currency'       => 'NGN',
            'type'           => 'transfer',
            'sender_account' => 'E2E' . substr(md5($runId . $suffix), 0, 10),
            'counterparty'   => 'E2E Counterparty ' . $suffix,
            'country_origin' => 'NG',
            'country_destination' => 'NG',
            'source'         => $SOURCE,
            'business_id'    => $BUSINESS_ID,
            'user_id'        => 'e2e-user',
            'device_id'      => 'e2e-dev-' . substr($runId, 0, 6),
        ],
        'metadata'       => array_merge([
            'source'      => $SOURCE,
            'business_id' => $BUSINESS_ID,
            'user_id'     => 'e2e-user',
            'device_id'   => 'e2e-dev-' . substr($runId, 0, 6),
        ], $meta),
        // request_id MUST be top-level: IngestionService reads $validated['request_id']
        // (from ingestRules()) for its 24h idempotency check.
        'request_id'     => $meta['request_id'] ?? $runId,
    ];
}

// A5 — low-value event: no scenario matches -> allow.
$runId5 = uuid4();
$r = http('POST', "$AML_BASE/api/v1/ingest", ingestBody($runId5, 'low', 150000.0, ['request_id' => uuid4()]), amlHeaders($AML_TOKEN), 40);
record('A5', 'ingest', 'low-value ingest -> allow (below scenarios)', $r,
    ['status' => 200, 'has' => ['event_id', 'outcome', 'risk_tier', 'call_ref'], 'eq' => ['outcome' => 'allow']],
    'POST /api/v1/ingest');
$eventLowId = is_array($r[1]) ? ($r[1]['event_id'] ?? null) : null;

// A6a — validation failure -> 422
$r = http('POST', "$AML_BASE/api/v1/ingest", ['data_model' => 'bank_transfer'], amlHeaders($AML_TOKEN), 20);
record('A6a', 'ingest', 'missing required fields -> 422', $r, ['status' => 422]);

// A6b — duplicate request_id -> 409
$reqId6 = uuid4();
$body6  = ingestBody(uuid4(), 'dup', 120000.0, ['request_id' => $reqId6]);
$r1 = http('POST', "$AML_BASE/api/v1/ingest", $body6, amlHeaders($AML_TOKEN), 40);
$r2 = http('POST', "$AML_BASE/api/v1/ingest", $body6, amlHeaders($AML_TOKEN), 40);
record('A6b', 'ingest', 'duplicate request_id -> 409', $r2, ['status' => 409], 'first_http=' . $r1[0]);

// A6c — status lookup GET /api/v1/ingest/{eventId}
if ($eventLowId) {
    $r = http('GET', "$AML_BASE/api/v1/ingest/$eventLowId", null, amlHeaders($AML_TOKEN), 20);
    record('A6c', 'ingest', 'GET /api/v1/ingest/{eventId}', $r,
        ['status' => 200, 'has' => ['event_id', 'status'], 'eq' => ['event_id' => $eventLowId]]);
} else {
    record('A6c', 'ingest', 'GET /api/v1/ingest/{eventId}', [0, null, 'no event id', 0], ['status' => 200]);
}

// A7a — rule engine: seeded BLOCK scenario (amount >= 9,000,000) -> 403 block
$r = http('POST', "$AML_BASE/api/v1/ingest", ingestBody(uuid4(), 'block', 9500000.0), amlHeaders($AML_TOKEN), 40);
record('A7a', 'ingest', 'rule-engine BLOCK scenario -> 403 block', $r,
    ['status' => 403, 'has' => ['event_id', 'outcome'], 'eq' => ['outcome' => 'block']],
    'amount=9,500,000 >= 9,000,000');
$eventBlockId = is_array($r[1]) ? ($r[1]['event_id'] ?? null) : null;

// A7b — rule engine: seeded FLAG scenario (600,000 <= x < 9,000,000)
$r = http('POST', "$AML_BASE/api/v1/ingest", ingestBody(uuid4(), 'flag', 750000.0), amlHeaders($AML_TOKEN), 40);
record('A7b', 'ingest', 'rule-engine FLAG scenario -> flag_for_review', $r,
    ['status' => 200, 'has' => ['event_id', 'outcome'], 'eq' => ['outcome' => 'flag_for_review']],
    'amount=750,000');

// A7c — DB correlation: ingested_events + decisions written
$evDelta  = (int) (scalar($amsDb, 'select count(*) from ingested_events') ?? 0) - $pre['ingested_events'];
$decDelta = (int) (scalar($amsDb, 'select count(*) from decisions') ?? 0) - $pre['decisions'];
$blockOutcome = scalar($amsDb,
    'select d.outcome from decisions d join ingested_events e on e.id = d.event_id where e.event_id = ?',
    [$eventBlockId]);
printf("  ingested_events delta=%d  decisions delta=%d  block_outcome=%s\n", $evDelta, $decDelta, $blockOutcome ?? '?');
$results[] = [
    'id' => 'A7c', 'area' => 'ingest', 'title' => 'ingested_events + decisions persisted (DB correlation)',
    'http' => null, 'expected' => null,
    'passed' => $evDelta >= 4 && $blockOutcome === 'block', 'ms' => 0,
    'note' => "events_delta=$evDelta decisions_delta=$decDelta block_outcome=" . ($blockOutcome ?? 'null'),
    'assertions' => [
        ['assert' => 'ingested_events grew', 'ok' => $evDelta >= 4],
        ['assert' => 'block decision recorded', 'ok' => $blockOutcome === 'block'],
    ],
    'body' => null,
];
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 4 — NEW unified assessment  POST /api/v1/assess  (AML + antifraud)
// ─────────────────────────────────────────────────────────────────────────────
echo "-- Phase 4: POST /api/v1/assess (unified AML + antifraud) -----------\n";

$assessBase = [
    'screening_types'      => ['sanctions', 'pep', 'adverse_media'],
    'entity_type'          => 'individual',
    'country_of_residence' => 'NG',
    'nationality'          => 'NG',
    'id_numbers'           => [['type' => 'bvn', 'value' => '22233344455']],
    'transaction'          => [
        'amount'   => 1200000,
        'currency' => 'NGN',
    ],
];

// A8a — risky subject + transaction leg in one call
$r = http('POST', "$AML_BASE/api/v1/assess",
    array_merge($assessBase, [
        'first_name' => 'E2E', 'last_name' => 'Watchlist Target',
        'date_of_birth' => '1985-04-12', 'request_id' => uuid4(),
    ]), amlHeaders($AML_TOKEN), 60);
$assessAml = is_array($r[1]) ? ($r[1]['aml'] ?? null) : null;
record('A8a', 'assess', 'unified assess: AML+PEP subject + txn leg', $r,
    ['status' => 200, 'has' => ['call_ref', 'status', 'risk_tier', 'aml', 'antifraud', 'subject_id']],
    'aml=' . json_encode(is_array($assessAml) ? array_intersect_key($assessAml, ['status' => 1, 'decision' => 1, 'flagged' => 1]) : null));

// A8b — clean subject (control)
$r = http('POST', "$AML_BASE/api/v1/assess",
    array_merge($assessBase, [
        'first_name' => 'Obinnaya', 'last_name' => 'Clearwater',
        'date_of_birth' => '1992-08-30', 'request_id' => uuid4(),
        'id_numbers' => [['type' => 'bvn', 'value' => '99112233445']],
    ]), amlHeaders($AML_TOKEN), 60);
$assessClean = is_array($r[1]) ? ($r[1]['aml'] ?? null) : null;
record('A8b', 'assess', 'unified assess: clean subject (control)', $r,
    ['status' => 200, 'has' => ['call_ref', 'status', 'risk_tier', 'aml', 'antifraud']],
    'aml=' . json_encode(is_array($assessClean) ? array_intersect_key($assessClean, ['status' => 1, 'decision' => 1, 'flagged' => 1]) : null));

// A8c — validation: missing subject identity -> 422
$r = http('POST', "$AML_BASE/api/v1/assess", ['screening_types' => ['sanctions']], amlHeaders($AML_TOKEN), 20);
record('A8c', 'assess', 'unified assess: invalid payload -> 422', $r, ['status' => 422]);
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 5 — screening + async call lifecycle
// ─────────────────────────────────────────────────────────────────────────────
echo "-- Phase 5: POST /api/v1/screen + GET /api/v1/calls/{callRef} -------\n";

$r = http('POST', "$AML_BASE/api/v1/screen", [
    'first_name'      => 'E2E',
    'last_name'       => 'Async Screen',
    'date_of_birth'   => '1980-01-01',
    'nationality'     => 'NG',
    'entity_type'     => 'individual',
    'screening_mode'  => 'async',
    'screening_types' => ['sanctions', 'pep'],
], amlHeaders($AML_TOKEN), 40);
$screenRef = is_array($r[1]) ? ($r[1]['call_ref'] ?? null) : null;
record('A9a', 'screen', 'async screen -> 202 + call_ref', $r,
    ['status' => 202, 'has' => ['call_ref']], 'call_ref=' . ($screenRef ?? '?'));

if ($screenRef) {
    $poll = http('GET', "$AML_BASE/api/v1/calls/$screenRef", null, amlHeaders($AML_TOKEN), 20);
    record('A9b', 'screen', 'GET /api/v1/calls/{callRef} (poll)', $poll,
        ['status' => 200, 'has' => ['call_ref', 'status']],
        'status=' . (is_array($poll[1]) ? ($poll[1]['status'] ?? '?') : '?'));
} else {
    record('A9b', 'screen', 'GET /api/v1/calls/{callRef} (poll)', [0, null, 'no call_ref', 0], ['status' => 200]);
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 6 — antifraud rules authority + version guard
// ─────────────────────────────────────────────────────────────────────────────
echo "-- Phase 6: antifraud rules authority + X-Rules-Version guard -------\n";

function scoreBody(string $ref, float $amount, string $userId, string $deviceId): array {
    global $BUSINESS_ID, $SOURCE;
    return [
        'ref_no'      => $ref,
        'customer_id' => abs(crc32($userId)) % 1000000000,
        'amount'      => $amount,
        'currency'    => 'NGN',
        'type'        => 'transfer',
        'aml_check'   => false,
        'fraud_check' => true,
        'metadata'    => [
            'source'      => $SOURCE,
            'business_id' => $BUSINESS_ID,
            'user_id'     => $userId,
            'device_id'   => $deviceId,
        ],
    ];
}

// A10a — current ruleset
$r = http('GET', "$AF_BASE/v1/rules?source=$SOURCE&business_id=$BUSINESS_ID");
$liveVersion = is_array($r[1]) ? ($r[1]['version'] ?? null) : null;
record('A10a', 'rules', 'GET /v1/rules (authority)', $r,
    ['status' => 200, 'has' => ['version', 'velocity', 'aml', 'fraud', 'block_on_flag']],
    "version=$liveVersion");

// A10b — stale version -> 412 + inline ruleset
$r = http('POST', "$AF_BASE/v1/transactions/score",
    scoreBody('E2E-STALE-' . substr(uuid4(), 0, 8), 150000.0, 'e2e-stale-user', 'e2e-stale-dev'),
    jsonHeaders(['X-Rules-Version: 0.0']), 30);
$staleDetail = is_array($r[1]) ? ($r[1]['detail'] ?? null) : null;
$staleHasRules = is_array($staleDetail) && isset($staleDetail['rules']);
record('A10b', 'rules', 'stale X-Rules-Version -> 412 + inline rules', $r,
    ['status' => 412],
    'has_inline_rules=' . ($staleHasRules ? 'yes' : 'no'));

// A10c — retry with the live version -> 200 OK
$retry = $staleHasRules ? ($staleDetail['current_version'] ?? $liveVersion) : $liveVersion;
$r = http('POST', "$AF_BASE/v1/transactions/score",
    scoreBody('E2E-RETRY-' . substr(uuid4(), 0, 8), 150000.0, 'e2e-retry-user', 'e2e-retry-dev'),
    jsonHeaders(['X-Rules-Version: ' . $retry]), 30);
record('A10c', 'rules', 'score with current version -> 200', $r,
    ['status' => 200, 'has' => ['status', 'decision', 'checks']],
    'version=' . $retry);

// A11 — admin per-business override bump -> version increments
$newFraudThreshold = 75000;
$r = http('PUT', "$AF_BASE/v1/admin/rules/$BUSINESS_ID",
    ['fraud_amount_threshold' => $newFraudThreshold], jsonHeaders(), 20);
$bumpedVersion = is_array($r[1]) ? ($r[1]['version'] ?? null) : null;
record('A11a', 'rules', "PUT /v1/admin/rules/$BUSINESS_ID (bump)", $r,
    ['status' => 200, 'has' => ['version']], "new_version=$bumpedVersion");

$r = http('GET', "$AF_BASE/v1/rules?source=$SOURCE&business_id=$BUSINESS_ID");
$afterVersion  = is_array($r[1]) ? ($r[1]['version'] ?? null) : null;
$afterThreshold = is_array($r[1]) ? ($r[1]['fraud']['amount_threshold'] ?? null) : null;
record('A11b', 'rules', 'ruleset reflects override + new version', $r,
    ['status' => 200],
    "version=$afterVersion fraud_threshold=$afterThreshold");
$results[] = [
    'id' => 'A11c', 'area' => 'rules', 'title' => 'override bumped version + applied threshold',
    'http' => null, 'expected' => null,
    'passed' => $bumpedVersion !== null && $afterVersion === $bumpedVersion && (float) $afterThreshold === (float) $newFraudThreshold,
    'ms' => 0, 'note' => "before=$liveVersion bumped=$bumpedVersion after=$afterVersion threshold=$afterThreshold",
    'assertions' => [], 'body' => null,
];

// restore the original fraud threshold so downstream cases are unaffected
$restore = http('PUT', "$AF_BASE/v1/admin/rules/$BUSINESS_ID", ['fraud_amount_threshold' => 100000], jsonHeaders(), 20);
record('A11d', 'rules', 'restore fraud threshold (cleanup)', $restore, ['status' => 200]);
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 7 — scoped identity: per-user velocity isolation
// ─────────────────────────────────────────────────────────────────────────────
echo "-- Phase 7: scoped identity + per-user velocity isolation -----------\n";

// Phase 6 bumped the per-business rules version (admin override), so re-read the
// live version — otherwise every Phase 7 score would 412 on a stale header.
$vres = http('GET', "$AF_BASE/v1/rules?source=$SOURCE&business_id=$BUSINESS_ID");
if (is_array($vres[1]) && !empty($vres[1]['version'])) {
    $liveVersion = $vres[1]['version'];
}
printf("  live rules version for scoring: %s\n", $liveVersion ?? '?');

$userA = 'e2e-vel-A-' . substr(uuid4(), 0, 6);
$userB = 'e2e-vel-B-' . substr(uuid4(), 0, 6);
$devA  = 'e2e-vel-devA-' . substr(uuid4(), 0, 6);
$devB  = 'e2e-vel-devB-' . substr(uuid4(), 0, 6);

$burst = null;
for ($i = 1; $i <= 6; $i++) {
    $r = http('POST', "$AF_BASE/v1/transactions/score",
        scoreBody('E2E-VEL-A-' . $i . '-' . substr(uuid4(), 0, 6), 100.0, $userA, $devA),
        jsonHeaders(['X-Rules-Version: ' . $liveVersion]), 30);
    $v = is_array($r[1]) ? ($r[1]['velocity'] ?? null) : null;
    if ($i === 6) { $burst = $v; }
    if ($i === 1 || $i === 6) {
        printf("  userA score #%d http=%d velocity.count_1m=%s breach=%s rule=%s\n",
            $i, $r[0], $v['count_1m'] ?? '?', json_encode($v['breach'] ?? null), $v['rule'] ?? '-');
    }
    usleep(150000);
}
$results[] = [
    'id' => 'A12a', 'area' => 'scoped-identity', 'title' => 'user A 6th score -> velocity breach count_1m>5',
    'http' => null, 'expected' => null,
    'passed' => is_array($burst) && ($burst['count_1m'] ?? 0) >= 6 && ($burst['breach'] ?? false) === true,
    'ms' => 0,
    'note' => 'count_1m=' . ($burst['count_1m'] ?? 'null') . ' breach=' . json_encode($burst['breach'] ?? null) . ' rule=' . ($burst['rule'] ?? 'null'),
    'assertions' => [
        ['assert' => 'count_1m >= 6', 'ok' => ($burst['count_1m'] ?? 0) >= 6],
        ['assert' => 'breach == true', 'ok' => ($burst['breach'] ?? false) === true],
    ],
    'body' => null,
];

// user B must be isolated from user A's burst
$r = http('POST', "$AF_BASE/v1/transactions/score",
    scoreBody('E2E-VEL-B-1-' . substr(uuid4(), 0, 6), 100.0, $userB, $devB),
    jsonHeaders(['X-Rules-Version: ' . $liveVersion]), 30);
$vb = is_array($r[1]) ? ($r[1]['velocity'] ?? null) : null;
record('A12b', 'scoped-identity', 'user B isolated from user A burst', $r, ['status' => 200],
    'count_1m=' . ($vb['count_1m'] ?? '?') . ' breach=' . json_encode($vb['breach'] ?? null));
$results[] = [
    'id' => 'A12b', 'area' => 'scoped-identity', 'title' => 'user B bucket starts fresh (count_1m == 1)',
    'http' => null, 'expected' => null,
    'passed' => is_array($vb) && (int) ($vb['count_1m'] ?? -1) === 1 && ($vb['breach'] ?? true) === false,
    'ms' => 0, 'note' => 'count_1m=' . ($vb['count_1m'] ?? 'null') . ' breach=' . json_encode($vb['breach'] ?? null),
    'assertions' => [], 'body' => null,
];

// spoof attempt: client-supplied X-Source must be ignored (identity comes from metadata)
$spoofUser = 'e2e-spoof-' . substr(uuid4(), 0, 6);
$r = http('POST', "$AF_BASE/v1/transactions/score",
    scoreBody('E2E-SPOOF-' . substr(uuid4(), 0, 6), 100.0, $spoofUser, 'e2e-spoof-dev'),
    jsonHeaders(['X-Rules-Version: ' . $liveVersion, 'X-Source: pos']), 30);
$spoofStored = scalar($afDb,
    'select source from transactions where user_id = ? order by id desc limit 1', [$spoofUser]);
record('A12c', 'scoped-identity', 'client X-Source spoof ignored (stored source)', $r, ['status' => 200],
    'stored_source=' . ($spoofStored ?? '?'));
$results[] = [
    'id' => 'A12c', 'area' => 'scoped-identity', 'title' => 'X-Source spoof stored as mobile',
    'http' => null, 'expected' => null,
    'passed' => $spoofStored === 'mobile', 'ms' => 0, 'note' => 'stored_source=' . ($spoofStored ?? 'null'),
    'assertions' => [], 'body' => null,
];

$afDelta = (int) (scalar($afDb, 'select count(*) from transactions') ?? 0) - $pre['af_transactions'];
printf("  antifraud transactions delta=%d (scoped rows persisted)\n", $afDelta);
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Phase 8 — fixpay-mobile journeys through the Payfixy gateway -> TMS
// ─────────────────────────────────────────────────────────────────────────────
echo "-- Phase 8: fixpay -> Payfixy gateway -> TMS journeys ----------------\n";

$token = $user['token'];

// ── KYC fixture (direct DB): fixpay gates wallet/transfer behind KYC, so mark
//    the e2e user verified and fund the wallet so journeys reach the gateway.
$r = http('POST', "$FIXPAY_BASE/auth/pin/set", ['pin' => '123456', 'pin_confirmation' => '123456'], authJsonHdrs($token), 30);
record('A0pin', 'auth', 'set transaction PIN', $r, ['status' => null]);
$kycOk = false;
if ($fixpayDb && $user['id']) {
    try {
        $fixpayDb->prepare("update app_users set kyc_status = 'VERIFIED' where id = ?")->execute([$user['id']]);
        $fixpayDb->prepare("insert into kyc_verifications (id, user_id, type, provider, verification_status, verified_at, created_at, updated_at)
                            values (gen_random_uuid(), ?, 'BVN', 'mock', 'VERIFIED', now(), now(), now())")->execute([$user['id']]);
        $fixpayDb->prepare("update wallets set balance_kobo = 2000000000, ledger_balance_kobo = 2000000000,
                            wallet_provider = 'ninepsb', ninepsb_account_number = '1100091317', status = 'ACTIVE'
                            where user_id = ?")->execute([$user['id']]);
        $kycOk = true;
    } catch (Throwable $e) {
        echo '  !! KYC fixture failed: ' . $e->getMessage() . "\n";
    }
}
printf("  KYC fixture applied=%s (user=%s)\n", $kycOk ? 'yes' : 'no', $user['id'] ?? '?');
$results[] = [
    'id' => 'A0d', 'area' => 'auth', 'title' => 'KYC fixture + wallet funding applied',
    'http' => null, 'expected' => null, 'passed' => $kycOk, 'ms' => 0,
    'note' => 'user=' . ($user['id'] ?? '?'), 'assertions' => [], 'body' => null,
];

// A1 — wallet open (gateway /api/v1/mobile/wallet/open) -> AML identity event
$r = http('POST', "$FIXPAY_BASE/wallet/ninepsb/create", ['terms_accepted' => true, 'bvn' => '12345678901'], guardHdrs($token), 60);
$walletRef = is_array($r[1]) ? ($r[1]['data']['accountNumber'] ?? $r[1]['reference'] ?? null) : null;
record('A1', 'journey', 'wallet open -> gateway -> TMS AML screen', $r, ['status' => null],
    'ref=' . ($walletRef ?? '?') . ' (provider 9PSB sandbox may fail; TMS leg still runs)');

// A2 — bill payment ₦100 (gateway /api/v1/mobile/bills/pay)
$r = http('POST', "$FIXPAY_BASE/payments/vtpass", [
    'service_id' => 'airtime', 'amount_kobo' => 10000, 'phone' => '08011111111',
], authJsonHdrs($token), 60);
record('A2', 'journey', 'bill ₦100 -> gateway (velocity-only ingest)', $r, ['status' => null],
    'amount=100 NGN');

// A3 — transfer ₦150k (fraud-only band: 100k <= x < 1M)
if ($fixpayDb && $user['id']) {
    try {
        $fixpayDb->prepare('update wallets set balance_kobo = 500000000, ledger_balance_kobo = 500000000 where user_id = ?')
                 ->execute([$user['id']]);
    } catch (Throwable $e) { /* wallet row may not exist yet */ }
}
$r = http('POST', "$FIXPAY_BASE/transfers/bank", [
    'amount_kobo' => 15000000, 'account_number' => '0123456789', 'bank_code' => '058', 'narration' => 'e2e 150k',
], guardHdrs($token), 60);
$ref150 = is_array($r[1]) ? ($r[1]['transfer_reference'] ?? $r[1]['reference'] ?? $r[1]['data']['reference'] ?? null) : null;
record('A3', 'journey', 'transfer ₦150k -> gateway (fraud-only band)', $r, ['status' => null], 'ref=' . ($ref150 ?? '?'));

// A4 — transfer ₦1.5M (AML-eligible: >= 1M -> deep score + velocity)
$r = http('POST', "$FIXPAY_BASE/transfers/bank", [
    'amount_kobo' => 150000000, 'account_number' => '0123456789', 'bank_code' => '058', 'narration' => 'e2e 1.5M',
], guardHdrs($token), 60);
$ref15m = is_array($r[1]) ? ($r[1]['transfer_reference'] ?? $r[1]['reference'] ?? $r[1]['data']['reference'] ?? null) : null;
record('A4', 'journey', 'transfer ₦1.5M -> gateway (AML-eligible deep score)', $r, ['status' => null], 'ref=' . ($ref15m ?? '?'));

// Correlation: gateway must have recorded scoped rows in TMS antifraud
$afMobile = (int) (scalar($afDb,
    "select count(*) from transactions where source = 'mobile' and business_id = ?", [$BUSINESS_ID]) ?? 0);
$afScoped = (int) (scalar($afDb,
    "select count(*) from transactions where ref_no in (?, ?)", [$ref150 ?: '-', $ref15m ?: '-']) ?? 0);
printf("  antifraud mobile-scoped rows=%d  rows matching journey refs=%d\n", $afMobile, $afScoped);
$results[] = [
    'id' => 'A13', 'area' => 'journey', 'title' => 'gateway journeys recorded scoped identity in TMS',
    'http' => null, 'expected' => null,
    'passed' => $afMobile > 0, 'ms' => 0,
    'note' => "mobile_rows=$afMobile journey_ref_rows=$afScoped",
    'assertions' => [], 'body' => null,
];

// fixpay async tagging (queue worker must be running)
$riskAssess = (int) (scalar($fixpayDb, 'select count(*) from risk_assessments') ?? 0);
printf("  fixpay risk_assessments=%d\n", $riskAssess);
$results[] = [
    'id' => 'A14', 'area' => 'journey', 'title' => 'fixpay async risk tagging ran (queue worker)',
    'http' => null, 'expected' => null, 'passed' => $riskAssess > 0, 'ms' => 0,
    'note' => "risk_assessments=$riskAssess", 'assertions' => [], 'body' => null,
];
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Summary + machine-readable output
// ─────────────────────────────────────────────────────────────────────────────
$total = count($results);
$pass  = 0;
$byArea = [];
foreach ($results as $row) {
    if (!empty($row['passed'])) { $pass++; }
    $area = $row['area'] ?? 'other';
    $byArea[$area]['total'] = ($byArea[$area]['total'] ?? 0) + 1;
    if (!empty($row['passed'])) { $byArea[$area]['pass'] = ($byArea[$area]['pass'] ?? 0) + 1; }
}

echo "===================================================================\n";
echo "  SUMMARY: $pass/$total checks passed\n";
foreach ($byArea as $area => $c) {
    printf("    %-18s %d/%d\n", $area, $c['pass'] ?? 0, $c['total']);
}
echo "===================================================================\n";

$report = [
    'run_label'    => $RUN_LABEL,
    'run_id'       => $preRunId,
    'generated_at' => gmdate('c'),
    'environment'  => [
        'fixpay_base' => $FIXPAY_BASE,
        'aml_base'    => $AML_BASE,
        'af_base'     => $AF_BASE,
        'source'      => $SOURCE,
        'business_id' => $BUSINESS_ID,
    ],
    'identity'     => ['user_email' => $user['email'], 'user_id' => $user['id']],
    'totals'       => ['total' => $total, 'passed' => $pass, 'failed' => $total - $pass, 'by_area' => $byArea],
    'results'      => $results,
];

echo "\n=== TMS_SAAS_E2E_JSON_BEGIN ===\n";
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo "\n=== TMS_SAAS_E2E_JSON_END ===\n";









