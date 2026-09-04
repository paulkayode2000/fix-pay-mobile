<?php

namespace App\Providers;

use App\Contracts\Kyc\AmlProviderInterface;
use App\Services\Gateway\GatewayClient;
use App\Contracts\Kyc\KycProviderInterface;
use App\Services\Kyc\MockAmlAdapter;
use App\Services\Kyc\MockKycAdapter;
use App\Services\NinePsb\NinePsbAdapter;
use App\Services\NinePsb\NinePsbAuthService;
use App\Services\NinePsb\NinePsbWalletProvider;
use App\Services\Security\TransactionGuardService;
use App\Services\Transfer\NinePsbTransferService;
use App\Services\Payment\PaymentRailService;
use App\Services\Payment\VtpassService;
use App\Services\Providus\ProvidusVirtualAccountAdapter;
use App\Services\Transfer\TransferService;
use App\Services\Wallet\WalletService;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── KYC Provider ────────────────────────────────────────────────────
        $this->app->bind(KycProviderInterface::class, function () {
            return new MockKycAdapter();
        });

        // ── AML Provider ─────────────────────────────────────────────────────
        $this->app->bind(AmlProviderInterface::class, function () {
            return match (config('services.aml.provider', 'mock')) {
                default => new MockAmlAdapter(),
            };
        });

        // ── Providus Virtual Account ─────────────────────────────────────────
        $this->app->singleton(ProvidusVirtualAccountAdapter::class, function () {
            return new ProvidusVirtualAccountAdapter(
                http: new Client(['timeout' => 30]),
                clientId: config('services.providus.client_id', ''),
                clientSecret: config('services.providus.client_secret', ''),
                baseUrl: config('services.providus.base_url', ''),
            );
        });

        // ── Wallet Service ────────────────────────────────────────────────────
        $this->app->singleton(WalletService::class, function ($app) {
            return new WalletService(
                virtualAccount: $app->make(ProvidusVirtualAccountAdapter::class),
                ninePsbProvider: $app->make(\App\Services\NinePsb\NinePsbWalletProvider::class),
            );
        });

        // ── VTPass Service ────────────────────────────────────────────────────
        $this->app->singleton(VtpassService::class, function ($app) {
            return new VtpassService(
                gatewayClient: $app->make(\App\Services\Gateway\GatewayClient::class),
                walletService: $app->make(WalletService::class),
                railService: $app->make(PaymentRailService::class),
                apiKey: config('services.vtpass.api_key', ''),
                secretKey: config('services.vtpass.secret_key', ''),
                publicKey: config('services.vtpass.public_key', ''),
                baseUrl: config('services.vtpass.base_url', ''),
            );
        });

        // ── Transfer Service ─────────────────────────────────────────────────
        $this->app->singleton(TransferService::class, function ($app) {
            return new TransferService(
                gatewayClient: $app->make(\App\Services\Gateway\GatewayClient::class),
                http: new Client(['timeout' => 60]),
                walletService: $app->make(WalletService::class),
                railService: $app->make(PaymentRailService::class),
                paystackSecretKey: config('services.paystack.secret_key', ''),
                paystackBaseUrl: config('services.paystack.base_url', ''),
            );
        });

        // ── 9PSB Auth Service ─────────────────────────────────────────────────
        $this->app->singleton(NinePsbAuthService::class, function () {
            return new NinePsbAuthService(
                baseUrl: config('services.ninepsb.base_url', ''),
                username: config('services.ninepsb.username', ''),
                password: config('services.ninepsb.password', ''),
                clientId: config('services.ninepsb.client_id', ''),
                clientSecret: config('services.ninepsb.client_secret', ''),
            );
        });

        // ── 9PSB Adapter ──────────────────────────────────────────────────────
        $this->app->singleton(NinePsbAdapter::class, function ($app) {
            return new NinePsbAdapter(
                auth: $app->make(NinePsbAuthService::class),
                baseUrl: config('services.ninepsb.base_url', ''),
            );
        });

        // ── 9PSB Wallet Provider ──────────────────────────────────────────────
        $this->app->singleton(NinePsbWalletProvider::class, function ($app) {
            return new NinePsbWalletProvider(
                adapter: $app->make(NinePsbAdapter::class),
                gatewayClient: $app->make(\App\Services\Gateway\GatewayClient::class),
            );
        });

        // ── Transaction Guard Service (security guardrails) ──────────────────
        $this->app->singleton(TransactionGuardService::class, function () {
            return new TransactionGuardService();
        });

        // ── 9PSB Transfer Service ────────────────────────────────────────────
        $this->app->singleton(NinePsbTransferService::class, function ($app) {
            return new NinePsbTransferService(
                ninePsb: $app->make(NinePsbAdapter::class),
                walletService: $app->make(WalletService::class),
                guard: $app->make(TransactionGuardService::class),
            );
        });

        // ── Payfixy Gateway Client ──────────────────────────────────────────
        $this->app->singleton(\App\Services\Gateway\GatewayClient::class, function () {
            return new \App\Services\Gateway\GatewayClient(
                baseUrl:    config('services.gateway.base_url', 'http://localhost:8999'),
                apiKey:     config('services.gateway.api_key', ''),
                secretKey:  config('services.gateway.secret_key', ''),
                businessId: config('services.gateway.business_id', ''),
            );
        });

        // ── TMS AML Client (aml-system) ─────────────────────────────────────
        $this->app->singleton(\App\Services\Tms\AmlClient::class, function () {
            return new \App\Services\Tms\AmlClient(
                baseUrl:  config('services.tms.base_url', 'http://127.0.0.1:8082'),
                apiToken: config('services.tms.api_token', ''),
                timeout:  (int) config('services.tms.timeout', 15),
            );
        });

        // ── TMS RiskRulesCache (antifraud-service / FastAPI rules authority) ──
        $this->app->singleton(\App\Services\Tms\RiskRulesCache::class, function () {
            return new \App\Services\Tms\RiskRulesCache(
                baseUrl: config('services.antifraud.base_url', 'http://127.0.0.1:8080'),
                apiKey:  config('services.antifraud.api_key', ''),
                ttl:     (int) config('services.antifraud.rules_cache_ttl', 60),
            );
        });

        // ── TMS Antifraud Client (antifraud-service / FastAPI) ──────────────
        $this->app->singleton(\App\Services\Tms\AntifraudClient::class, function () {
            return new \App\Services\Tms\AntifraudClient(
                baseUrl: config('services.antifraud.base_url', 'http://127.0.0.1:8080'),
                apiKey:  config('services.antifraud.api_key', ''),
                timeout: (int) config('services.antifraud.timeout', 15),
                rulesCache: app(\App\Services\Tms\RiskRulesCache::class),
            );
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
    }
}
