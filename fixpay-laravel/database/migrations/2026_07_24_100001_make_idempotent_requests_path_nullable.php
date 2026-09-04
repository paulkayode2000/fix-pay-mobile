<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotent_requests', function (Blueprint $table) {
            $table->string('request_path')->nullable()->change();
            $table->string('request_method')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('idempotent_requests', function (Blueprint $table) {
            $table->string('request_path')->nullable(false)->change();
            $table->string('request_method')->nullable(false)->change();
        });
    }
};