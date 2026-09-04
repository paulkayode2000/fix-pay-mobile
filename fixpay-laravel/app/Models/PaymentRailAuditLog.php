<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for payment rail / fee schedule configuration changes.
 *
 * Column set (create_payment_rail_tables migration):
 * id, entity_type, entity_id, action, admin_id, old_json, new_json, ip_address, created_at.
 */
class PaymentRailAuditLog extends Model
{
    use HasUuids;

    /** The audit table only keeps a created_at timestamp. */
    public const UPDATED_AT = null;

    protected $table = 'payment_rail_audit_logs';

    protected $fillable = [
        'entity_type', 'entity_id', 'action',
        'admin_id', 'old_json', 'new_json', 'ip_address',
    ];

    protected $casts = [
        'old_json' => 'array',
        'new_json' => 'array',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'admin_id');
    }
}
