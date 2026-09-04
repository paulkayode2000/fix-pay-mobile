<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalized risk-tagging columns used for fast admin filtering.
     * The full per-check detail lives in the polymorphic risk_assessments table.
     */
    public function up(): void
    {
        $transactionTables = ['transfers', 'vtpass_payments', 'ninepsb_transactions'];

        foreach ($transactionTables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('aml_status', 20)->nullable()->index();
                $table->decimal('aml_score', 10, 4)->nullable();
                $table->string('aml_case_ref', 64)->nullable();
                $table->string('antifraud_status', 20)->nullable()->index();
                $table->decimal('antifraud_score', 10, 4)->nullable();
                $table->string('risk_status', 20)->nullable()->index();
                $table->decimal('risk_score', 10, 4)->nullable();
                $table->timestamp('risk_screened_at')->nullable();
            });
        }

        Schema::table('app_users', function (Blueprint $table) {
            $table->string('aml_status', 20)->nullable()->index();
            $table->decimal('aml_score', 10, 4)->nullable();
            $table->string('aml_case_ref', 64)->nullable();
            $table->string('risk_status', 20)->nullable()->index();
            $table->jsonb('risk_tags')->nullable();
            $table->timestamp('aml_screened_at')->nullable();
        });
    }

    public function down(): void
    {
        $transactionTables = ['transfers', 'vtpass_payments', 'ninepsb_transactions'];

        foreach ($transactionTables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn([
                    'aml_status', 'aml_score', 'aml_case_ref',
                    'antifraud_status', 'antifraud_score',
                    'risk_status', 'risk_score', 'risk_screened_at',
                ]);
            });
        }

        Schema::table('app_users', function (Blueprint $table) {
            $table->dropColumn([
                'aml_status', 'aml_score', 'aml_case_ref',
                'risk_status', 'risk_tags', 'aml_screened_at',
            ]);
        });
    }
};
