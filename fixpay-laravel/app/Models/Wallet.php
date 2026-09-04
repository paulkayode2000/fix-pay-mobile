<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'tenant_id', 'balance_kobo', 'ledger_balance_kobo',
        'currency', 'status', 'wallet_provider',
        'virtual_account_number', 'virtual_account_bank',
        'virtual_account_bank_code', 'virtual_account_reference',
        'ninepsb_account_number', 'ninepsb_customer_id',
        'ninepsb_order_ref', 'ninepsb_tier', 'ninepsb_metadata',
        'device_id', 'device_bound_at',
        'last_location_lat', 'last_location_lng', 'last_location_at',
    ];

    protected $casts = [
        'balance_kobo' => 'integer',
        'ledger_balance_kobo' => 'integer',
        'ninepsb_metadata' => 'array',
        'device_bound_at' => 'datetime',
        'last_location_at' => 'datetime',
        'last_location_lat' => 'decimal:7',
        'last_location_lng' => 'decimal:7',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function hasSufficientBalance(int $amountKobo): bool
    {
        return $this->balance_kobo >= $amountKobo;
    }

    public function getAccountNoAttribute(): ?string
    {
        return $this->ninepsb_account_number ?? $this->virtual_account_number;
    }
}
