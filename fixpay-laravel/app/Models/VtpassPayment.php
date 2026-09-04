<?php

namespace App\Models;

use App\Models\Concerns\RiskTagging;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VtpassPayment extends Model
{
    use HasUuids, RiskTagging;

    protected $table = 'vtpass_payments';

    protected $fillable = [
        'user_id', 'wallet_id', 'tenant_id', 'payment_reference', 'idempotency_key',
        'service_id', 'variation_code', 'amount_kobo', 'fee_kobo', 'phone',
        'billersCode', 'request_payload', 'response_payload', 'payment_status',
        'provider_code', 'processor_id', 'processor_fee_kobo', 'token', 'units',
        'requery_count', 'last_requeried_at', 'provider_request_id',
        'completed_at', 'failed_at',
        // TMS risk tags
        'aml_status', 'aml_score', 'aml_case_ref',
        'antifraud_status', 'antifraud_score',
        'risk_status', 'risk_score', 'risk_screened_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'amount_kobo' => 'integer',
        'fee_kobo' => 'integer',
        'processor_fee_kobo' => 'integer',
        'requery_count' => 'integer',
        'last_requeried_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        // TMS risk tags
        'aml_score' => 'float',
        'antifraud_score' => 'float',
        'risk_score' => 'float',
        'risk_screened_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(PaymentJournalEntry::class, 'payment_id');
    }

    public function isPending(): bool
    {
        return $this->payment_status === 'PENDING';
    }

    public function isTerminal(): bool
    {
        return in_array($this->payment_status,
            ['COMPLETED', 'FAILED', 'REVERSED', 'REQUIRES_RECONCILIATION']);
    }
}
