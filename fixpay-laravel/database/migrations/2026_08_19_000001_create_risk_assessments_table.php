<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(config('database.default') === 'sqlite' ? null : DB::raw('gen_random_uuid()'));
            $table->string('assessable_type');          // App\Models\AppUser|Transfer|VtpassPayment|NinePsbTransaction
            $table->uuid('assessable_id');
            $table->string('type', 20)->default('AML'); // AML | ANTIFRAUD
            $table->string('status', 20)->default('PENDING'); // PENDING|CLEAR|FLAGGED|BLOCKED|UNAVAILABLE
            $table->decimal('score', 10, 4)->nullable();
            $table->jsonb('tags')->nullable();
            $table->jsonb('payload')->nullable();       // full TMS response
            $table->string('tms_call_ref', 64)->nullable();
            $table->string('tms_case_ref', 64)->nullable();
            $table->string('decision_id', 64)->nullable(); // antifraud transaction id
            $table->text('error')->nullable();
            $table->timestamp('screened_at')->nullable();
            $table->timestamps();

            $table->index(['assessable_type', 'assessable_id']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
