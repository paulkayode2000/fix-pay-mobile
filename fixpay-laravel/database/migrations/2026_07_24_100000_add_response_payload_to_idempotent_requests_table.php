<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotent_requests', function (Blueprint $table) {
            $table->json('response_payload')->nullable()->after('response_body');
            $table->timestamp('expires_at')->nullable()->after('response_payload');
        });
    }

    public function down(): void
    {
        Schema::table('idempotent_requests', function (Blueprint $table) {
            $table->dropColumn(['response_payload', 'expires_at']);
        });
    }
};