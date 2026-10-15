<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bill payments settled through a non-wallet rail (Payfixy / card /
     * bank transfer / USSD) routed through the Payfixy Gateway.
     *
     * These transactions never touch a user wallet, so `wallet_id` is nullable:
     * a user who has not opened a wallet can still pay a bill. Wallet payments
     * keep living in `vtpass_payments` (they reserve funds via a wallet hold).
     */
    public function up(): void
    {
        Schema::create('alternative_payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(config('database.default') === 'sqlite' ? null : DB::raw('gen_random_uuid()'));
            $table->uuid('user_id')->index();
            $table->uuid('tenant_id')->nullable()->index();
            // Null when the payer has no wallet — the gateway settles the bill
            // through its own channel routing.
            $table->uuid('wallet_id')->nullable()->index();
            $table->string('payment_reference')->unique();
            $table->string('gateway_reference')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('payment_method', 40); // payfixy | card | bank_transfer | ussd
            $table->string('service_id', 100);
            $table->string('variation_code', 100)->nullable();
            $table->bigInteger('amount_kobo');
            $table->bigInteger('fee_kobo')->default(0);
            $table->string('phone', 20)->nullable();
            $table->string('billers_code')->nullable(); // meter/smartcard number
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->enum('payment_status', ['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED', 'REVERSED'])->default('PENDING');
            $table->string('provider_code', 20)->nullable(); // gateway/provider response code
            $table->string('token')->nullable(); // electricity token
            $table->string('units')->nullable(); // electricity units
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // TMS denormalized risk tags (mirrors transfers / vtpass_payments).
            $table->string('aml_status', 20)->nullable()->index();
            $table->decimal('aml_score', 10, 4)->nullable();
            $table->string('aml_case_ref', 64)->nullable();
            $table->string('antifraud_status', 20)->nullable()->index();
            $table->decimal('antifraud_score', 10, 4)->nullable();
            $table->string('risk_status', 20)->nullable()->index();
            $table->decimal('risk_score', 10, 4)->nullable();
            $table->timestamp('risk_screened_at')->nullable();

            $table->foreign('user_id')->references('id')->on('app_users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alternative_payments');
    }
};
