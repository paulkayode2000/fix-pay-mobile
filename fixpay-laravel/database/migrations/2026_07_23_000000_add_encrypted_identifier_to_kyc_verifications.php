<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->text('encrypted_identifier')->nullable()->after('identifier')
                ->comment('Encrypted raw BVN/NIN for wallet creation auto-fill (identifier is hashed)');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->dropColumn('encrypted_identifier');
        });
    }
};