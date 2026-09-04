<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visual alert for platform users when TMS returns a flagged/blocked AML or
 * antifraud result. Flag-only policy: the alert drives manual review/action.
 */
class RiskAlert extends Model
{
    use HasUuids;

    protected $table = 'risk_alerts';

    protected $fillable = [
        'assessable_type', 'assessable_id', 'user_id', 'type', 'severity',
        'status', 'summary', 'detail', 'tms_case_ref', 'tms_call_ref',
        'seen_at', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'detail'      => 'array',
        'seen_at'     => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'reviewed_by');
    }
}
