<?php

namespace App\Models;

use App\Models\Concerns\RiskTagging;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bill payment settled through a non-wallet rail (Payfixy / card / transfer /
 * USSD) via the Payfixy Gateway. `wallet_id` is null when the payer has no
 * wallet — the gateway settles the bill through its own channel routing.
 */
class AlternativePayment extends Model
{
    use HasUuids, RiskTagging;

    protected $table = 'alternative_payments';

    protected $fillable = [
        'user_id', 'tenant_id', 'wallet_id', 'payment_reference', 'gateway_reference',
        'idempotency_key', 'payment_method', 'service_id', 'variation_code',
        'amount_kobo', 'fee_kobo', 'phone', 'billers_code',
        'request_payload', 'response_payload', 'payment_status', 'provider_code',
        'token', 'units', 'completed_at', 'failed_at',
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

    public function isPending(): bool
    {
        return $this->payment_status === 'PENDING';
    }

    public function isTerminal(): bool
    {
        return in_array($this->payment_status,
            ['COMPLETED', 'FAILED', 'REVERSED']);
    }
}
