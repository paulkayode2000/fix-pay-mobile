<?php

use App\Http\Controllers\Admin\PaymentRailAdminController;
use App\Http\Controllers\Admin\RailsAdminController;
use App\Http\Controllers\Admin\TenantAdminController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PinController;
use App\Http\Controllers\Compliance\ComplianceController;
use App\Http\Controllers\Compliance\DisputeController;
use App\Http\Controllers\Mandate\MandateController;
use App\Http\Controllers\Payment\VtpassPaymentController;
use App\Http\Controllers\Payment\AlternativePaymentController;
use App\Http\Controllers\Portal\ApiKeyController;
use App\Http\Controllers\Portal\IpWhitelistController;
use App\Http\Controllers\Portal\KybController;
use App\Http\Controllers\Portal\PortalRegistrationController;
use App\Http\Controllers\Portal\SettlementController;
use App\Http\Controllers\Portal\TenantPortalController;
use App\Http\Controllers\Portal\WebhookController;
use App\Http\Controllers\Tenant\TenantConfigController;
use App\Http\Controllers\Transfer\PaystackWebhookController;
use App\Http\Controllers\Transfer\TransferController;
use App\Http\Controllers\User\KycController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Wallet\NinePsbWalletController;
use App\Http\Controllers\Wallet\WalletController;
use App\Http\Controllers\NinePsbWebhookController;
use Illuminate\Support\Facades\Route;

// ── Public routes ─────────────────────────────────────────────────────────

// Tenant branding config — public, rate-limited, returns only safe branding data.
// Unknown slugs return an opaque 404. No UUIDs or financial data exposed.
Route::middleware('throttle:30,1')->get('tenant/config', [TenantConfigController::class, 'show']);

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('resend-otp', [AuthController::class, 'resendOtp']);
});

Route::post('portal/register', [PortalRegistrationController::class, 'register']);

// Paystack webhook (public, verified by signature)
Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle']);

// NIBSS Webhook callback (public)
Route::post('webhooks/nibss/callback', [KycController::class, 'nibssCallback']);

// 9PSB Webhook (public, verified by Basic Auth)
Route::match(['get', 'post'], 'webhooks/9psb', [NinePsbWebhookController::class, 'handle']);

// TMS AML/Antifraud result webhook (public, verified by HMAC signature)
Route::post('webhooks/tms', [\App\Http\Controllers\Webhook\TmsWebhookController::class, 'handle']);

// ── Authenticated consumer routes ─────────────────────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('auth/logout', [AuthController::class, 'logout']);

    // PIN
    Route::prefix('auth/pin')->group(function () {
        Route::post('set', [PinController::class, 'set']);
        Route::post('verify', [PinController::class, 'verify']);
        Route::put('change', [PinController::class, 'change']);
    });

    // User profile
    Route::prefix('user')->group(function () {
        Route::get('profile', [UserController::class, 'profile']);
        Route::put('profile', [UserController::class, 'updateProfile']);
    });

    Route::get('analytics', [\App\Http\Controllers\User\AnalyticsController::class, 'show']);

    // Favourites
    Route::prefix('favourites')->group(function () {
        Route::get('/', [\App\Http\Controllers\User\FavouriteController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\User\FavouriteController::class, 'store']);
        Route::delete('{id}', [\App\Http\Controllers\User\FavouriteController::class, 'destroy']);
    });

    // KYC
    Route::prefix('kyc')->group(function () {
        Route::post('bvn', [KycController::class, 'verifyBvn']);
        Route::post('bvn/consent/initiate', [KycController::class, 'initiateBvnConsent']);
        Route::post('nin', [KycController::class, 'verifyNin']);
        Route::get('status', [KycController::class, 'status']);
    });

    // Wallet
    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'show']);
        Route::get('transactions', [WalletController::class, 'transactions']);

        // 9PSB Wallet operations (KYC-gated, device-bound, location-enforced)
        Route::prefix('ninepsb')->group(function () {
            Route::get('terms', [NinePsbWalletController::class, 'terms']);
            Route::post('create', [NinePsbWalletController::class, 'create']);
            Route::get('enquiry', [NinePsbWalletController::class, 'enquiry']);
            Route::get('status', [NinePsbWalletController::class, 'status']);
            Route::get('transactions', [NinePsbWalletController::class, 'transactions']);
            Route::post('upgrade', [NinePsbWalletController::class, 'upgrade']);
            Route::get('upgrade-status', [NinePsbWalletController::class, 'upgradeStatus']);
            Route::post('requery', [NinePsbWalletController::class, 'requery']);
        });
    });

    // Payments
    Route::prefix('payments')->group(function () {
        Route::get('vtpass/services', [VtpassPaymentController::class, 'services']);
        Route::get('vtpass/variations', [VtpassPaymentController::class, 'variations']);
        Route::post('verify', [VtpassPaymentController::class, 'verify']);
        Route::post('vtpass', [VtpassPaymentController::class, 'pay']);
        Route::get('vtpass/{reference}', [VtpassPaymentController::class, 'status']);
        Route::post('alternative/initiate', [AlternativePaymentController::class, 'initiate']);
        Route::post('alternative/verify', [AlternativePaymentController::class, 'verify']);
    });

    // Transfers
    Route::prefix('transfers')->group(function () {
        Route::get('/', [TransferController::class, 'index']);
        Route::get('banks', [TransferController::class, 'banks']);
        Route::post('verify-account', [TransferController::class, 'verifyAccount']);
        Route::post('bank', [TransferController::class, 'toBank']);
        Route::post('wallet', [TransferController::class, 'toWallet']);
        Route::get('{reference}', [TransferController::class, 'status']);
    });

    // Mandates
    Route::prefix('mandates')->group(function () {
        Route::get('/', [MandateController::class, 'index']);
        Route::post('/', [MandateController::class, 'create']);
        Route::get('{id}', [MandateController::class, 'show']);
        Route::delete('{id}', [MandateController::class, 'cancel']);
    });

    // Disputes
    Route::prefix('disputes')->group(function () {
        Route::get('/', [DisputeController::class, 'index']);
        Route::post('/', [DisputeController::class, 'create']);
        Route::get('{id}', [DisputeController::class, 'show']);
    });
});

// ── Portal routes (API key auth) ──────────────────────────────────────────
Route::prefix('portal')->middleware(['api.key', 'tenant', 'ip.whitelist'])->group(function () {
    Route::prefix('kyb')->group(function () {
        Route::get('/', [KybController::class, 'show']);
        Route::post('/', [KybController::class, 'submit']);
    });

    Route::prefix('api-keys')->group(function () {
        Route::get('/', [ApiKeyController::class, 'index']);
        Route::post('/', [ApiKeyController::class, 'create']);
        Route::delete('{id}', [ApiKeyController::class, 'revoke']);
    });

    Route::prefix('webhooks')->group(function () {
        Route::get('/', [WebhookController::class, 'index']);
        Route::post('/', [WebhookController::class, 'create']);
        Route::delete('{id}', [WebhookController::class, 'delete']);
    });

    Route::prefix('ip-whitelist')->group(function () {
        Route::get('/', [IpWhitelistController::class, 'index']);
        Route::post('/', [IpWhitelistController::class, 'create']);
        Route::delete('{id}', [IpWhitelistController::class, 'delete']);
    });

    Route::prefix('settlement')->group(function () {
        Route::get('/', [SettlementController::class, 'show']);
        Route::post('/', [SettlementController::class, 'upsert']);
    });

    Route::get('profile', [TenantPortalController::class, 'profile']);
    Route::put('profile', [TenantPortalController::class, 'updateProfile']);
    Route::post('go-live', [TenantPortalController::class, 'requestGoLive']);
});

// ── Admin routes (Sanctum + role guard) ──────────────────────────────────
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {

    Route::get('profile', [App\Http\Controllers\Admin\AdminProfileController::class, 'show']);
    Route::get('analytics', [App\Http\Controllers\Admin\AnalyticsAdminController::class, 'index']);

    Route::prefix('transactions')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\TransactionAdminController::class, 'index']);
        Route::get('ledger', [App\Http\Controllers\Admin\TransactionAdminController::class, 'ledger']);
    });

    Route::prefix('system')->group(function () {
        Route::get('health', [App\Http\Controllers\Admin\SystemAdminController::class, 'health']);
    });

    Route::prefix('fraud')->group(function () {
        Route::get('rules', [App\Http\Controllers\Admin\FraudAdminController::class, 'getRules']);
        Route::get('cases', [App\Http\Controllers\Admin\FraudAdminController::class, 'getCases']);
    });

    Route::prefix('users')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\UserAdminController::class, 'index']);
        Route::get('{id}', [App\Http\Controllers\Admin\UserAdminController::class, 'show']);
        Route::put('{id}/status', [App\Http\Controllers\Admin\UserAdminController::class, 'updateStatus']);
    });

    Route::prefix('tenants')->group(function () {
        Route::get('/', [TenantAdminController::class, 'index']);
        Route::get('{id}', [TenantAdminController::class, 'show']);
        Route::put('{id}/status', [TenantAdminController::class, 'updateStatus']);
        Route::put('{id}/kyb', [TenantAdminController::class, 'reviewKyb']);
    });

    Route::prefix('payment-rails')->group(function () {
        Route::get('/', [PaymentRailAdminController::class, 'index']);
        Route::post('/', [PaymentRailAdminController::class, 'create']);
        Route::put('{id}', [PaymentRailAdminController::class, 'update']);
        Route::post('{id}/fee-schedules', [PaymentRailAdminController::class, 'addFeeSchedule']);
    });

    Route::prefix('rails')->group(function () {
        Route::get('/', [RailsAdminController::class, 'index']);
        Route::post('/', [RailsAdminController::class, 'store']);
        Route::get('audit', [RailsAdminController::class, 'auditLog']);
        Route::get('processors', [RailsAdminController::class, 'processors']);
        Route::get('processors/{processorId}/schema', [RailsAdminController::class, 'processorSchema']);
        Route::get('health', [RailsAdminController::class, 'health']);
        Route::get('{id}', [RailsAdminController::class, 'show']);
        Route::put('{id}/config', [RailsAdminController::class, 'updateConfig']);
        Route::put('{id}/processor', [RailsAdminController::class, 'updateProcessor']);
        Route::patch('{id}/enabled', [RailsAdminController::class, 'toggleEnabled']);
        Route::patch('{id}/maintenance', [RailsAdminController::class, 'setMaintenance']);
        Route::delete('{id}', [RailsAdminController::class, 'destroy']);
        Route::get('{id}/fees', [RailsAdminController::class, 'fees']);
        Route::post('{id}/fees', [RailsAdminController::class, 'addFee']);
        Route::delete('{id}/fees/{feeId}', [RailsAdminController::class, 'deleteFee']);
        Route::get('{id}/audit', [RailsAdminController::class, 'entityAuditLog']);
    });

    Route::prefix('plugins')->group(function () {
        Route::get('/', [RailsAdminController::class, 'plugins']);
        Route::post('reload', [RailsAdminController::class, 'reloadPlugins']);
        Route::delete('{pluginId}', [RailsAdminController::class, 'unloadPlugin']);
    });

    Route::prefix('settlement')->group(function () {
        Route::get('report', [RailsAdminController::class, 'settlementReport']);
        Route::get('cycles', [RailsAdminController::class, 'settlementCycles']);
    });

    Route::prefix('alerts')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\AlertAdminController::class, 'index']);
        Route::get('unread-count', [\App\Http\Controllers\Admin\AlertAdminController::class, 'unreadCount']);
        Route::post('seen', [\App\Http\Controllers\Admin\AlertAdminController::class, 'markSeen']);
        Route::patch('{id}', [\App\Http\Controllers\Admin\AlertAdminController::class, 'update']);
    });

    Route::prefix('disputes')->group(function () {
        Route::get('/', [DisputeController::class, 'adminIndex']);
        Route::put('{id}', [DisputeController::class, 'resolve']);
    });

    Route::prefix('compliance')->group(function () {
        Route::get('users', [ComplianceController::class, 'userList']);
        Route::post('screen/{userId}', [ComplianceController::class, 'screenUser']);
    });
});
