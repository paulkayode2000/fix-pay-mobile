<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'type', 'identifier', 'encrypted_identifier', 'provider',
        'provider_reference', 'verification_status',
        'response_json', 'failure_reason', 'verified_at',
        'nibss_session_id', 'nibss_retrieval_token', 'consent_expiry_time',
        'bvn_consent_status',
    ];

    protected $casts = [
        'response_json' => 'array',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'VERIFIED';
    }
}
