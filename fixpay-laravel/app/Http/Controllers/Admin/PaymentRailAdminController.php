<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentRailAuditLog;
use App\Models\PaymentRailConfig;
use App\Models\ProcessorFeeSchedule;
use App\Services\Payment\ProcessorRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Legacy admin API for payment rails.
 *
 * Kept for backwards compatibility but now mirrors the manual/UI path
 * (RailsAdminController): same processor-registry guard, same validation and
 * the same audit trail, so configuration changes cannot bypass the change log.
 */
class PaymentRailAdminController extends Controller
{
    public function __construct(
        private readonly ProcessorRegistry $processors,
    ) {}

    /** GET /api/admin/payment-rails */
    public function index(): JsonResponse
    {
        return response()->json(
            PaymentRailConfig::with('feeSchedules')->get()
        );
    }

    /** POST /api/admin/payment-rails */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => 'required|string|max:50',
            'processor_id'   => 'required|string|max:50',
            'priority'       => 'required|integer|min:0',
            'enabled'        => 'required|boolean',
            'maintenance'    => 'required|boolean',
            'config_json'    => 'nullable|array',
            'tenant_id'      => 'nullable|uuid',
        ]);

        // Same processor guard as the admin UI path (RailsAdminController::store).
        if (! $this->processors->has($data['processor_id'])) {
            return response()->json(['message' => "Unknown processor '{$data['processor_id']}'."], 422);
        }

        $config = PaymentRailConfig::create($data);

        $this->writeAudit('rail', $config->id, 'CREATE', null, $config->toArray());

        return response()->json($config, 201);
    }

    /** PUT /api/admin/payment-rails/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);
        $before = $config->toArray();

        // Previously unvalidated ($request->only(...)) — any payload type was
        // persisted. Validate types while still allowing partial updates.
        $data = $request->validate([
            'enabled'     => 'sometimes|boolean',
            'maintenance' => 'sometimes|boolean',
            'priority'    => 'sometimes|integer|min:0',
            'config_json' => 'sometimes|nullable|array',
        ]);

        if ($data !== []) {
            $config->update($data);
            $this->writeAudit('rail', $config->id, 'UPDATE', $before, $config->toArray());
        }

        return response()->json($config);
    }

    /** POST /api/admin/payment-rails/{id}/fee-schedules */
    public function addFeeSchedule(Request $request, string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);

        $data = $request->validate([
            'min_amount_kobo'  => 'required|integer|min:0',
            'max_amount_kobo'  => 'nullable|integer|min:0',
            'percentage_fee'   => 'required|numeric|min:0|max:1',
            'flat_fee_kobo'    => 'required|integer|min:0',
            'cap_kobo'         => 'nullable|integer|min:0',
            'effective_from'   => 'nullable|date',
            'effective_to'     => 'nullable|date',
        ]);

        $schedule = $config->feeSchedules()->create($data);

        $this->writeAudit('fee', $schedule->id, 'CREATE', null, $schedule->toArray());

        return response()->json($schedule, 201);
    }

    /**
     * Persist an audit entry, mirroring RailsAdminController::writeAudit so the
     * manual (admin UI) and API input paths leave identical evidence in the
     * change log. The audit trail must never block the underlying config change.
     */
    private function writeAudit(string $entityType, string $entityId, string $action, ?array $old, ?array $new): void
    {
        try {
            PaymentRailAuditLog::create([
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'action'      => $action,
                'admin_id'    => auth()->id(),
                'old_json'    => $old,
                'new_json'    => $new,
                'ip_address'  => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist payment rail audit entry', [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'action'      => $action,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
