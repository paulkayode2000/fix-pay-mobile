<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Device binding
            $table->string('device_id', 64)->nullable()->after('ninepsb_metadata')
                ->comment('SHA-256 hash of device fingerprint: IMEI+model+OS+build');
            $table->timestamp('device_bound_at')->nullable()->after('device_id')
                ->comment('When the wallet was bound to this device');

            // Location tracking
            $table->decimal('last_location_lat', 10, 7)->nullable()->after('device_bound_at');
            $table->decimal('last_location_lng', 10, 7)->nullable()->after('last_location_lat');
            $table->timestamp('last_location_at')->nullable()->after('last_location_lng');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn([
                'device_id',
                'device_bound_at',
                'last_location_lat',
                'last_location_lng',
                'last_location_at',
            ]);
        });
    }
};