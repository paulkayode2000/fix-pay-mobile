<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single AML or antifraud assessment returned by TMS for a fixpay entity
 * (AppUser, Transfer, VtpassPayment, NinePsbTransaction). One row per check.
 */
class RiskAssessment extends Model
{
    use HasUuids;

    protected $table = 'risk_assessments';

    protected $fillable = [
        'assessable_type', 'assessable_id', 'type', 'status', 'score',
        'tags', 'payload', 'tms_call_ref', 'tms_case_ref', 'decision_id',
        'error', 'screened_at',
    ];

    protected $casts = [
        'score'       => 'float',
        'tags'        => 'array',
        'payload'     => 'array',
        'screened_at' => 'datetime',
    ];

    public function assessable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isFlagged(): bool
    {
        return in_array($this->status, ['FLAGGED', 'BLOCKED'], true);
    }
}
