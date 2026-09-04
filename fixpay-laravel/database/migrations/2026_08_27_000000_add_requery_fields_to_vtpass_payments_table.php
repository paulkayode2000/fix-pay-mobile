<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vtpass_payments', function (Blueprint $table) {
            $table->unsignedInteger('requery_count')->default(0)->after('processor_fee_kobo');
            $table->timestamp('last_requeried_at')->nullable()->after('requery_count');
            $table->string('provider_request_id', 120)->nullable()->after('last_requeried_at');
        });

        // The requery/timeout commands escalate unresolved payments to
        // REQUIRES_RECONCILIATION (funds stay reserved for manual reconciliation).
        // Widen the enum-backed CHECK constraint that the base table created.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vtpass_payments DROP CONSTRAINT vtpass_payments_payment_status_check');
            DB::statement("ALTER TABLE vtpass_payments ADD CONSTRAINT vtpass_payments_payment_status_check CHECK (payment_status IN ('PENDING','PROCESSING','COMPLETED','FAILED','REVERSED','REQUIRES_RECONCILIATION'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vtpass_payments DROP CONSTRAINT vtpass_payments_payment_status_check');
            DB::statement("ALTER TABLE vtpass_payments ADD CONSTRAINT vtpass_payments_payment_status_check CHECK (payment_status IN ('PENDING','PROCESSING','COMPLETED','FAILED','REVERSED'))");
        }

        Schema::table('vtpass_payments', function (Blueprint $table) {
            $table->dropColumn(['requery_count', 'last_requeried_at', 'provider_request_id']);
        });
    }
};
