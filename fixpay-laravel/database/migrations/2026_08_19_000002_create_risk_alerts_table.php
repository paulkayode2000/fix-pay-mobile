<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(config('database.default') === 'sqlite' ? null : DB::raw('gen_random_uuid()'));
            $table->string('assessable_type')->nullable();
            $table->uuid('assessable_id')->nullable();
            $table->uuid('user_id')->nullable()->index();
            $table->string('type', 20)->default('AML');          // AML | ANTIFRAUD
            $table->string('severity', 20)->default('MEDIUM');   // LOW|MEDIUM|HIGH|CRITICAL
            $table->string('status', 20)->default('NEW');        // NEW|REVIEWED|DISMISSED|ESCALATED
            $table->string('summary', 500);
            $table->jsonb('detail')->nullable();
            $table->string('tms_case_ref', 64)->nullable();
            $table->string('tms_call_ref', 64)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_alerts');
    }
};
