<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('wallet_provider', 20)->default('providus')->after('status')
                ->comment('Wallet provider: providus | ninepsb');
            $table->string('ninepsb_account_number', 10)->nullable()->after('wallet_provider')
                ->comment('9PSB NUBAN account number');
            $table->string('ninepsb_customer_id', 50)->nullable()->after('ninepsb_account_number')
                ->comment('9PSB customer ID');
            $table->string('ninepsb_order_ref', 50)->nullable()->after('ninepsb_customer_id')
                ->comment('9PSB wallet opening order reference');
            $table->string('ninepsb_tier', 5)->default('1')->after('ninepsb_order_ref')
                ->comment('9PSB KYC tier: 1, 2, or 3');
            $table->json('ninepsb_metadata')->nullable()->after('ninepsb_tier')
                ->comment('Additional 9PSB wallet metadata (fullName, mfbcode, etc.)');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn([
                'wallet_provider',
                'ninepsb_account_number',
                'ninepsb_customer_id',
                'ninepsb_order_ref',
                'ninepsb_tier',
                'ninepsb_metadata',
            ]);
        });
    }
};