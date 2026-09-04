<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail column too small for REQUIRES_RECONCILIATION (21 chars)
        // written by payments:timeout-stale.
        Schema::table('payment_journal_entries', function (Blueprint $table) {
            $table->string('status', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_journal_entries', function (Blueprint $table) {
            $table->string('status', 20)->change();
        });
    }
};
