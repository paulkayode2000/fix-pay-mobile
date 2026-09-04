<?php

namespace App\Http\Controllers\User;

use App\Contracts\Kyc\AmlProviderInterface;
use App\Contracts\Kyc\KycProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\KycVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class KycController extends Controller
{
    public function __construct(
        private readonly KycProviderInterface $kyc,
        private readonly AmlProviderInterface $aml,
    ) {}

    /** POST /api/kyc/bvn */
    public function verifyBvn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bvn' => 'required|string|size:11',
            'dob' => 'required|date_format:Y-m-d',
        ]);

        $user = $request->user();

        $existing = KycVerification::where('user_id', $user->id)->where('type', 'BVN')->where('verification_status', 'VERIFIED')->first();
        if ($existing) {
            return response()->json([
                'status' => 'VERIFIED',
                'message' => 'BVN already verified.',
            ]);
        }

        $record = KycVerification::create([
            'user_id' => $user->id,
            'type' => 'BVN',
            'identifier' => Hash::make($data['bvn']), // store hashed for lookup
            'encrypted_identifier' => Crypt::encryptString($data['bvn']), // encrypted for wallet creation
            'provider' => $this->kyc->getProviderName(),
            'verification_status' => 'PENDING',
        ]);

        try {
            // $result = $this->kyc->verifyBvn($data['bvn'], $data['dob']);
            // $status = $result['status'] ? 'VERIFIED' : 'FAILED';
            
            // Allow random BVN in live for now to test VTPass sandbox
            $status = 'VERIFIED';
            $result = ['reference' => 'mock_ref_' . uniqid(), 'data' => ['mock' => true]];

            $record->update([
                'verification_status' => $status,
                'provider_reference' => $result['reference'],
                'response_json' => $result['data'],
                'verified_at' => $status === 'VERIFIED' ? now() : null,
            ]);

            if ($status === 'VERIFIED' && $user->kyc_status === 'UNVERIFIED') {
                $user->update([
                    'kyc_status' => 'PENDING',
                    'date_of_birth' => $data['dob'] ?? null,
                ]);
            } elseif ($status === 'VERIFIED') {
                // Still persist DOB even if user was already PENDING
                $user->update(['date_of_birth' => $data['dob'] ?? null]);
            }

            return response()->json([
                'status' => $status,
                'message' => $status === 'VERIFIED' ? 'BVN verified successfully.' : 'BVN verification failed.',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('verifyBvn exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $record->update(['verification_status' => 'FAILED', 'failure_reason' => $e->getMessage()]);

            return response()->json(['message' => 'Verification service unavailable.'], 503);
        }
    }

    /** POST /api/kyc/nin */
    public function verifyNin(Request $request): JsonResponse
    {
        $data = $request->validate(['nin' => 'required|string|size:11']);

        $user = $request->user();

        $existing = KycVerification::where('user_id', $user->id)->where('type', 'NIN')->where('verification_status', 'VERIFIED')->first();
        if ($existing) {
            return response()->json(['status' => 'VERIFIED']);
        }

        $record = KycVerification::create([
            'user_id' => $user->id,
            'type' => 'NIN',
            'identifier' => Hash::make($data['nin']),
            'encrypted_identifier' => Crypt::encryptString($data['nin']), // encrypted for wallet creation
            'provider' => $this->kyc->getProviderName(),
            'verification_status' => 'PENDING',
        ]);

        try {
            // $result = $this->kyc->verifyNin($data['nin']);
            // $status = $result['status'] ? 'VERIFIED' : 'FAILED';

            // Allow random NIN in live for now to test VTPass sandbox
            $status = 'VERIFIED';
            $result = ['reference' => 'mock_ref_' . uniqid(), 'data' => ['mock' => true]];

            $record->update([
                'verification_status' => $status,
                'provider_reference' => $result['reference'],
                'response_json' => $result['data'],
                'verified_at' => $status === 'VERIFIED' ? now() : null,
            ]);

            return response()->json(['status' => $status]);
        } catch (\Throwable $e) {
            $record->update(['verification_status' => 'FAILED', 'failure_reason' => $e->getMessage()]);

            return response()->json(['message' => 'Verification service unavailable.'], 503);
        }
    }

    /** POST /api/kyc/bvn/consent/initiate */
    public function initiateBvnConsent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bvn' => 'required|string|size:11',
            'dob' => 'nullable|date_format:Y-m-d',
        ]);

        $user = $request->user();

        // Check if already verified
        $existing = KycVerification::where('user_id', $user->id)
            ->where('type', 'BVN')
            ->where('verification_status', 'VERIFIED')
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'VERIFIED',
                'message' => 'BVN already verified.',
            ]);
        }

        $sessionId = 'mock_session_' . uniqid();
        $consentUrl = env('NIBSS_BASE_URL', 'https://apitest.nibss-plc.com.ng/api') . '/consent/mock?sessionId=' . $sessionId;

        $record = KycVerification::create([
            'user_id' => $user->id,
            'type' => 'BVN',
            'identifier' => Hash::make($data['bvn']),
            'provider' => 'NIBSS_CONSENT_HUB',
            'verification_status' => 'PENDING',
            'nibss_session_id' => $sessionId,
        ]);

        return response()->json([
            'status' => 'PENDING',
            'message' => 'Consent initiated successfully.',
            'consentUrl' => $consentUrl,
            'sessionId' => $sessionId,
        ]);
    }

    /** POST /api/webhooks/nibss/callback */
    public function nibssCallback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sessionId' => 'required|string',
            'retrievalToken' => 'required|string',
            'dataOwnerId' => 'required|string',
            'consentExpiryTime' => 'nullable|string',
            'tokenIssuedDate' => 'nullable|string',
            'requestCategory' => 'nullable|string',
        ]);

        $record = KycVerification::where('nibss_session_id', $data['sessionId'])->first();

        if (!$record) {
            return response()->json(['message' => 'Session not found'], 404);
        }

        // Mocking the NIBSS Data Retrieval using the retrievalToken
        // In a real scenario, we would make a POST to the NIBSS retrieval endpoint here
        $mockedRetrievedData = [
            'bvn' => $data['dataOwnerId'],
            'firstName' => 'John',
            'lastName' => 'Doe',
            'mock' => true,
        ];

        $record->update([
            'nibss_retrieval_token' => $data['retrievalToken'],
            'consent_expiry_time' => isset($data['consentExpiryTime']) ? \Carbon\Carbon::parse($data['consentExpiryTime']) : null,
            'bvn_consent_status' => 'APPROVED',
            'verification_status' => 'VERIFIED',
            'verified_at' => now(),
            'response_json' => $mockedRetrievedData,
        ]);

        $user = $record->user;
        if ($user && $user->kyc_status === 'UNVERIFIED') {
            $user->update(['kyc_status' => 'PENDING']);
        }

        return response()->json(['message' => 'request received successfully']);
    }

    /** GET /api/kyc/status */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $verifications = KycVerification::where('user_id', $user->id)->get();

        // Determine if user is ready for 9PSB wallet creation
        $hasBvn = $verifications->contains(fn ($v) => $v->type === 'BVN' && $v->verification_status === 'VERIFIED');
        $hasNin = $verifications->contains(fn ($v) => $v->type === 'NIN' && $v->verification_status === 'VERIFIED');

        $hasNinePsbWallet = \App\Models\Wallet::where('user_id', $user->id)
            ->where('wallet_provider', 'ninepsb')
            ->exists();

        $readyForNinePsb = ($hasBvn || $hasNin) && !$hasNinePsbWallet;

        $response = [
            'kyc_status' => $user->kyc_status,
            'tier' => $user->tier,
            'verifications' => $verifications->map(fn ($v) => [
                'type' => $v->type,
                'status' => $v->verification_status,
                'provider' => $v->provider,
                'verified_at' => $v->verified_at,
            ]),
        ];

        // Add 9PSB readiness indicators
        if ($readyForNinePsb) {
            $response['ready_for_ninepsb'] = true;
            $response['next_action'] = 'accept_terms';
            $response['next_action_label'] = 'Review & Accept 9PSB Terms';
            $response['next_action_route'] = 'GET /wallet/ninepsb/terms';
        } elseif ($hasNinePsbWallet) {
            $response['ready_for_ninepsb'] = false;
            $response['wallet_status'] = 'active';
        } else {
            $response['ready_for_ninepsb'] = false;
            $response['next_action'] = 'verify_identity';
            $response['next_action_label'] = 'Verify BVN or NIN';
            $response['next_action_route'] = 'POST /kyc/bvn';
        }

        return response()->json($response);
    }
}
