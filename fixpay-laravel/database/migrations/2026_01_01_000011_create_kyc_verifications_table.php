<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(config('database.default') === 'sqlite' ? null : \Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->uuid('user_id')->index();
            $table->enum('type', ['BVN', 'NIN', 'CAC', 'SELFIE', 'AML_PEP', 'AML_SANCTIONS']);
            $table->string('identifier')->nullable(); // the BVN/NIN number (hashed for PII)
            $table->string('provider', 30)->default('mock'); // youverify, smileid, mock
            $table->string('provider_reference')->nullable();
            $table->enum('verification_status', ['PENDING', 'VERIFIED', 'FAILED'])->default('PENDING');
            $table->jsonb('response_json')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('app_users')->cascadeOnDelete();
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verifications');
    }
};
