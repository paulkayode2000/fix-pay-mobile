<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class IdempotentRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'idempotency_key',
        'user_id',
        'request_path',
        'request_method',
        'response_code',
        'response_body',
        'response_payload',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'response_body' => 'array',
        'response_payload' => 'array',
        'expires_at' => 'datetime',
    ];
}
