<?php

namespace App\Models;

use App\Models\Concerns\RiskTagging;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NinePsbTransaction extends Model
{
    use HasUuids, RiskTagging;

    protected $table = 'ninepsb_transactions';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'tenant_id',
        'transaction_id',
        'transaction_type',
        'account_number',
        'amount',
        'fee_amount',
        'status',
        'response_code',
        'response_message',
        'narration',
        'request_payload',
        'response_payload',
        'transaction_date',
        // TMS risk tags
        'aml_status', 'aml_score', 'aml_case_ref',
        'antifraud_status', 'antifraud_score',
        'risk_status', 'risk_score', 'risk_screened_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'transaction_date' => 'datetime',
        // TMS risk tags
        'aml_score' => 'float',
        'antifraud_score' => 'float',
        'risk_score' => 'float',
        'risk_screened_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isSuccess(): bool
    {
        return $this->response_code === '00';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }

    /**
     * Requires TSQ verification per 9PSB spec.
     * Response codes 09, 96, 97, 98, 99 need requery.
     */
    public function requiresRequery(): bool
    {
        return in_array($this->response_code, ['09', '96', '97', '98', '99']);
    }
}