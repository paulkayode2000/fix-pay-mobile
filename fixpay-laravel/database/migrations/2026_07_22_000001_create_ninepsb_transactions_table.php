<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ninepsb_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(
                config('database.default') === 'sqlite'
                    ? null
                    : \Illuminate\Support\Facades\DB::raw('gen_random_uuid()')
            );
            $table->uuid('wallet_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('tenant_id')->nullable()->index();

            // 9PSB transaction identifiers
            $table->string('transaction_id', 25)->index()
                ->comment('Unique transaction reference sent to 9PSB');
            $table->string('transaction_type')->comment('DEBIT_WALLET | CREDIT_WALLET | OTHER_BANKS | INFLOW');

            // Account details
            $table->string('account_number', 10)->index();

            // Amounts
            $table->decimal('amount', 20, 2)->comment('Transaction amount in Naira');
            $table->decimal('fee_amount', 20, 2)->default(0)->comment('Merchant fee amount if any');

            // Status
            $table->string('status')->default('PENDING')
                ->comment('PENDING | SUCCESS | FAILED');
            $table->string('response_code', 5)->nullable()
                ->comment('9PSB response code (00=success, 51=insufficient, etc.)');
            $table->string('response_message')->nullable();

            // Narration
            $table->string('narration')->nullable();

            // Full request/response payload for audit
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            // Timestamps
            $table->timestamp('transaction_date')->nullable()
                ->comment('When 9PSB processed the transaction');
            $table->timestamps();

            // Foreign keys
            $table->foreign('wallet_id')->references('id')->on('wallets')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('app_users')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ninepsb_transactions');
    }
};