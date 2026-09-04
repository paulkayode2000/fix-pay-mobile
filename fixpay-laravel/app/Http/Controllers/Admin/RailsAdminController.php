<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentRailAuditLog;
use App\Models\PaymentRailConfig;
use App\Models\ProcessorFeeSchedule;
use App\Models\Transfer;
use App\Models\VtpassPayment;
use App\Services\Payment\ProcessorRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin API for payment rails, processor health, fee schedules, plugins,
 * audit logs and settlement figures.
 *
 * Implements the contract consumed by fixpay-admin (src/modules/rails/railApi.ts
 * and src/modules/settlement/SettlementDashboard.tsx).
 */
class RailsAdminController extends Controller
{
    public function __construct(
        private readonly ProcessorRegistry $processors,
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Rails CRUD
    // ─────────────────────────────────────────────────────────────

    /** GET /api/admin/rails?tenantId= */
    public function index(Request $request): JsonResponse
    {
        $query = PaymentRailConfig::query();

        if ($tenantId = $request->query('tenantId')) {
            $query->where('tenant_id', $tenantId);
        }

        $rails = $query->orderByDesc('updated_at')
            ->get()
            ->map(fn (PaymentRailConfig $r) => $this->mapRail($r))
            ->values();

        return response()->json($rails);
    }

    /** GET /api/admin/rails/{id} */
    public function show(string $id): JsonResponse
    {
        return response()->json($this->mapRail(PaymentRailConfig::findOrFail($id)));
    }

    /** POST /api/admin/rails */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenantId'      => 'nullable|uuid',
            'paymentMethod' => 'required|string|max:50',
            'processorId'   => 'required|string|max:100',
            'priority'      => 'required|integer|min:0',
            'configJson'    => 'nullable',
        ]);

        if (! $this->processors->has($data['processorId'])) {
            return response()->json(['message' => "Unknown processor '{$data['processorId']}'."], 422);
        }

        $config = PaymentRailConfig::create([
            'tenant_id'      => $data['tenantId'] ?? null,
            'payment_method' => $data['paymentMethod'],
            'processor_id'   => $data['processorId'],
            'priority'       => $data['priority'],
            'enabled'        => true,
            'maintenance'    => false,
            'config_json'    => $this->normalizeConfig($data['configJson'] ?? '{}'),
        ]);

        $this->writeAudit('rail', $config->id, 'CREATE', null, $this->mapRail($config));

        return response()->json($this->mapRail($config), 201);
    }

    /** PUT /api/admin/rails/{id}/config */
    public function updateConfig(Request $request, string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);
        $before = $this->mapRail($config);

        $data = $request->validate([
            'configJson' => 'nullable',
            'priority'   => 'nullable|integer|min:0',
        ]);

        if (array_key_exists('configJson', $data)) {
            $config->config_json = $this->normalizeConfig($data['configJson']);
        }
        if (array_key_exists('priority', $data) && $data['priority'] !== null) {
            $config->priority = $data['priority'];
        }
        $config->save();

        $this->writeAudit('rail', $config->id, 'UPDATE', $before, $this->mapRail($config));

        return response()->json($this->mapRail($config));
    }

    /** PUT /api/admin/rails/{id}/processor */
    public function updateProcessor(Request $request, string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);

        $data = $request->validate(['processorId' => 'required|string|max:100']);
        if (! $this->processors->has($data['processorId'])) {
            return response()->json(['message' => "Unknown processor '{$data['processorId']}'."], 422);
        }

        $before = $this->mapRail($config);
        $config->processor_id = $data['processorId'];
        $config->save();

        $this->writeAudit('rail', $config->id, 'UPDATE', $before, $this->mapRail($config));

        return response()->json($this->mapRail($config));
    }

    /** PATCH /api/admin/rails/{id}/enabled */
    public function toggleEnabled(Request $request, string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);
        $before = $this->mapRail($config);

        $data = $request->validate(['enabled' => 'required|boolean']);
        $config->enabled = $data['enabled'];
        $config->save();

        $this->writeAudit('rail', $config->id, 'UPDATE', $before, $this->mapRail($config));

        return response()->json($this->mapRail($config));
    }

    /** PATCH /api/admin/rails/{id}/maintenance */
    public function setMaintenance(Request $request, string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);
        $before = $this->mapRail($config);

        $data = $request->validate(['maintenance' => 'required|boolean']);
        $config->maintenance = $data['maintenance'];
        $config->save();

        $this->writeAudit('rail', $config->id, 'UPDATE', $before, $this->mapRail($config));

        return response()->json($this->mapRail($config));
    }

    /** DELETE /api/admin/rails/{id} */
    public function destroy(string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);

        $this->writeAudit('rail', $config->id, 'DELETE', $this->mapRail($config), null);
        $config->delete();

        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────
    // Processor registry
    // ─────────────────────────────────────────────────────────────

    /** GET /api/admin/rails/processors */
    public function processors(): JsonResponse
    {
        return response()->json($this->processors->ids());
    }

    /** GET /api/admin/rails/processors/{processorId}/schema */
    public function processorSchema(string $processorId): JsonResponse
    {
        $schema = $this->processors->schema($processorId);

        if ($schema === null) {
            return response()->json(['message' => 'Processor not found.'], 404);
        }

        return response()->json($schema);
    }

    // ─────────────────────────────────────────────────────────────
    // Processor health
    // ─────────────────────────────────────────────────────────────

    /** GET /api/admin/rails/health */
    public function health(): JsonResponse
    {
        $rails = PaymentRailConfig::all();
        $status = [];

        foreach ($rails as $rail) {
            $id = $rail->processor_id;
            if (! isset($status[$id])) {
                $status[$id] = [
                    'processorId' => $id,
                    'cbState'     => 'CLOSED',
                    'failureRate' => 0.0,
                    'totalCalls'  => 0,
                    'isPlugin'    => false,
                ];
            }
        }

        // A processor with no active (enabled + not maintenance) rail is DISABLED.
        foreach ($status as $id => &$entry) {
            $hasActive = $rails->contains(
                fn (PaymentRailConfig $r) => $r->processor_id === $id && $r->enabled && ! $r->maintenance
            );
            if (! $hasActive) {
                $entry['cbState'] = 'DISABLED';
            }
        }

        return response()->json(array_values($status));
    }

    // ─────────────────────────────────────────────────────────────
    // Fee schedules
    // ─────────────────────────────────────────────────────────────

    /** GET /api/admin/rails/{id}/fees */
    public function fees(string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);

        return response()->json(
            $config->feeSchedules()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ProcessorFeeSchedule $f) => $this->mapFee($f))
                ->values()
        );
    }

    /** POST /api/admin/rails/{id}/fees */
    public function addFee(Request $request, string $id): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);

        $data = $request->validate([
            'feeType'        => 'nullable|in:FIXED,PERCENTAGE,TIERED',
            'fixedFeeKobo'   => 'nullable|integer|min:0',
            'percentageFee'  => 'nullable|numeric|min:0|max:1',
            'capKobo'        => 'nullable|integer|min:0',
            'minFeeKobo'     => 'nullable|integer|min:0',
            'effectiveFrom'  => 'nullable|date',
            'effectiveTo'    => 'nullable|date',
        ]);

        $fee = $config->feeSchedules()->create([
            'min_amount_kobo'  => $data['minFeeKobo'] ?? 0,
            'max_amount_kobo'  => null,
            'percentage_fee'   => $data['percentageFee'] ?? 0,
            'flat_fee_kobo'    => $data['fixedFeeKobo'] ?? 0,
            'cap_kobo'         => $data['capKobo'] ?? null,
            'effective_from'   => $data['effectiveFrom'] ?? null,
            'effective_to'     => $data['effectiveTo'] ?? null,
        ]);

        $this->writeAudit('fee', $fee->id, 'CREATE', null, $this->mapFee($fee));

        return response()->json($this->mapFee($fee), 201);
    }

    /** DELETE /api/admin/rails/{id}/fees/{feeId} */
    public function deleteFee(string $id, string $feeId): JsonResponse
    {
        $config = PaymentRailConfig::findOrFail($id);
        $fee = $config->feeSchedules()->find($feeId);

        if (! $fee) {
            return response()->json(['message' => 'Fee schedule not found.'], 404);
        }

        $this->writeAudit('fee', $fee->id, 'DELETE', $this->mapFee($fee), null);
        $fee->delete();

        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────
    // Hot-loadable processor plugins
    // ─────────────────────────────────────────────────────────────

    /** In-process loaded-plugin set so reload/unload behave within the dev server. */
    private static array $loadedPlugins = [];

    /** GET /api/admin/plugins */
    public function plugins(): JsonResponse
    {
        $plugins = [];

        foreach ($this->discoverPlugins() as $id => $version) {
            $plugins[] = [
                'pluginId' => $id,
                'version'  => $version,
                'state'    => isset(self::$loadedPlugins[$id]) ? 'STARTED' : 'STOPPED',
            ];
        }

        return response()->json($plugins);
    }

    /** POST /api/admin/plugins/reload */
    public function reloadPlugins(): JsonResponse
    {
        self::$loadedPlugins = [];
        $loaded = 0;

        foreach ($this->discoverPlugins() as $id => $version) {
            self::$loadedPlugins[$id] = true;
            $loaded++;
        }

        return response()->json(['loaded' => $loaded]);
    }

    /** DELETE /api/admin/plugins/{pluginId} */
    public function unloadPlugin(string $pluginId): JsonResponse
    {
        if (! isset(self::$loadedPlugins[$pluginId])) {
            return response()->json(['message' => 'Plugin not loaded.'], 404);
        }

        unset(self::$loadedPlugins[$pluginId]);

        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────────────────────
    // Audit log
    // ─────────────────────────────────────────────────────────────

    /** GET /api/admin/rails/audit?page=&size= */
    public function auditLog(Request $request): JsonResponse
    {
        return $this->paginateAudit(PaymentRailAuditLog::query(), $request);
    }

    /** GET /api/admin/rails/{id}/audit?page=&size= */
    public function entityAuditLog(Request $request, string $id): JsonResponse
    {
        PaymentRailConfig::findOrFail($id);

        $feeIds = ProcessorFeeSchedule::where('config_id', $id)->pluck('id');

        $query = PaymentRailAuditLog::where('entity_id', $id)
            ->orWhere(function ($q) use ($feeIds) {
                $q->where('entity_type', 'fee')->whereIn('entity_id', $feeIds);
            });

        return $this->paginateAudit($query, $request);
    }

    // ─────────────────────────────────────────────────────────────
    // Settlement
    // ─────────────────────────────────────────────────────────────

    /** GET /api/admin/settlement/report?tenantId=&from=&to= */
    public function settlementReport(Request $request): JsonResponse
    {
        $stats = $this->settlementTotals($request);

        return response()->json([
            'implemented'            => true,
            'message'                => 'Settlement figures derived from completed transactions.',
            'totalTransactions'      => $stats['totalTransactions'],
            'totalAmountKobo'        => $stats['totalAmountKobo'],
            'totalProcessorFeesKobo' => $stats['totalProcessorFeesKobo'],
            'platformRevenueKobo'    => $stats['totalAmountKobo'] - $stats['totalProcessorFeesKobo'],
        ]);
    }

    /** GET /api/admin/settlement/cycles?from=&to= */
    public function settlementCycles(Request $request): JsonResponse
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : Carbon::now()->subDays(30);
        $to   = $request->query('to')   ? Carbon::parse($request->query('to'))   : Carbon::now();
        $to   = $to->copy()->endOfDay();

        $vtpass = VtpassPayment::where('payment_status', 'COMPLETED')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'tenant_id', 'created_at', 'amount_kobo', 'processor_fee_kobo', 'fee_kobo']);

        $transfers = Transfer::where('status', 'SUCCESS')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'tenant_id', 'created_at', 'amount_kobo', 'fee_kobo']);

        $cycles = [];

        foreach ($vtpass as $p) {
            $key = $p->created_at->toDateString().'|'.($p->tenant_id ?? 'global');
            $fees = (int) ($p->processor_fee_kobo ?? 0) + (int) ($p->fee_kobo ?? 0);
            $this->accumulateCycle($cycles, $key, $p->created_at->toDateString(), $p->tenant_id, (int) $p->amount_kobo, $fees);
        }

        foreach ($transfers as $t) {
            $key = $t->created_at->toDateString().'|'.($t->tenant_id ?? 'global');
            $this->accumulateCycle($cycles, $key, $t->created_at->toDateString(), $t->tenant_id, (int) $t->amount_kobo, (int) ($t->fee_kobo ?? 0));
        }

        krsort($cycles);

        return response()->json(['data' => ['cycles' => array_values($cycles)]]);
    }

    // ─────────────────────────────────────────────────────────────
    // Response mapping helpers
    // ─────────────────────────────────────────────────────────────

    private function mapRail(PaymentRailConfig $config): array
    {
        return [
            'id'            => $config->id,
            'tenantId'      => $config->tenant_id,
            'paymentMethod' => $config->payment_method,
            'processorId'   => $config->processor_id,
            'priority'      => $config->priority,
            'enabled'       => (bool) $config->enabled,
            'maintenance'   => (bool) $config->maintenance,
            'configJson'    => $this->jsonEncode($config->config_json),
            'configSchema'  => $this->processors->schema($config->processor_id),
            'createdAt'     => $config->created_at?->toISOString(),
            'updatedAt'     => $config->updated_at?->toISOString(),
        ];
    }

    private function mapFee(ProcessorFeeSchedule $fee): array
    {
        $flat = (int) $fee->flat_fee_kobo;
        $pct  = (float) $fee->percentage_fee;
        $type = ($flat > 0 && $pct > 0) ? 'TIERED' : ($pct > 0 ? 'PERCENTAGE' : 'FIXED');

        return [
            'id'             => $fee->id,
            'feeType'        => $type,
            'fixedFeeKobo'   => $flat,
            'percentageFee'  => $pct,
            'capKobo'        => $fee->cap_kobo,
            'minFeeKobo'     => (int) $fee->min_amount_kobo,
            'effectiveFrom'  => $fee->effective_from?->toDateString(),
            'effectiveTo'    => $fee->effective_to?->toDateString(),
            'createdAt'      => $fee->created_at?->toISOString(),
        ];
    }

    private function mapAudit(PaymentRailAuditLog $log): array
    {
        return [
            'id'               => $log->id,
            'adminUserId'      => $log->admin_id,
            'action'           => $log->action,
            'entityType'       => $log->entity_type,
            'entityId'         => $log->entity_id,
            'beforeStateJson'  => $this->nullableJson($log->old_json),
            'afterStateJson'   => $this->nullableJson($log->new_json),
            'ipAddress'        => $log->ip_address,
            'createdAt'        => $log->created_at?->toISOString(),
        ];
    }

    private function paginateAudit($query, Request $request): JsonResponse
    {
        $page = max(0, (int) $request->query('page', 0));
        $size = min(100, max(1, (int) $request->query('size', 20)));

        $total = (clone $query)->count();

        $content = (clone $query)
            ->orderByDesc('created_at')
            ->skip($page * $size)
            ->take($size)
            ->get()
            ->map(fn (PaymentRailAuditLog $log) => $this->mapAudit($log))
            ->values();

        return response()->json(['content' => $content, 'totalElements' => $total]);
    }

    private function writeAudit(string $entityType, string $entityId, string $action, ?array $old, ?array $new): void
    {
        PaymentRailAuditLog::create([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'admin_id'    => auth()->id(),
            'old_json'    => $old,
            'new_json'    => $new,
            'ip_address'  => request()->ip(),
        ]);
    }

    private function normalizeConfig(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function jsonEncode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value ?? [], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function nullableJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: null;
    }

    // ─────────────────────────────────────────────────────────────
    // Plugin discovery
    // ─────────────────────────────────────────────────────────────

    /** @return array<string, string> pluginId => version */
    private function discoverPlugins(): array
    {
        $dir = config('services.gateway.plugins_dir', base_path('plugins'));

        if (! is_dir($dir)) {
            return [];
        }

        $found = [];

        foreach (glob($dir.'/*.{jar,zip}', GLOB_BRACE) ?: [] as $file) {
            $found[pathinfo($file, PATHINFO_FILENAME)] = '1.0';
        }

        return $found;
    }

    // ─────────────────────────────────────────────────────────────
    // Settlement helpers
    // ─────────────────────────────────────────────────────────────

    private function settlementTotals(Request $request): array
    {
        $tenantId = $request->query('tenantId');
        $from     = $request->query('from') ? Carbon::parse($request->query('from')) : null;
        $to       = $request->query('to')   ? Carbon::parse($request->query('to'))   : null;

        $vtpass = VtpassPayment::where('payment_status', 'COMPLETED');
        $transfers = Transfer::where('status', 'SUCCESS');

        if ($tenantId) {
            $vtpass->where('tenant_id', $tenantId);
            $transfers->where('tenant_id', $tenantId);
        }
        if ($from) {
            $vtpass->where('created_at', '>=', $from);
            $transfers->where('created_at', '>=', $from);
        }
        if ($to) {
            $vtpass->where('created_at', '<=', $to->copy()->endOfDay());
            $transfers->where('created_at', '<=', $to->copy()->endOfDay());
        }

        $vtpassCount   = (clone $vtpass)->count();
        $transferCount = (clone $transfers)->count();

        $vtpassAmount   = (int) (clone $vtpass)->sum('amount_kobo');
        $transferAmount = (int) (clone $transfers)->sum('amount_kobo');

        $vtpassFees   = (int) (clone $vtpass)->selectRaw('COALESCE(SUM(processor_fee_kobo),0) + COALESCE(SUM(fee_kobo),0) AS total')->value('total');
        $transferFees = (int) (clone $transfers)->sum('fee_kobo');

        return [
            'totalTransactions'      => $vtpassCount + $transferCount,
            'totalAmountKobo'        => $vtpassAmount + $transferAmount,
            'totalProcessorFeesKobo' => $vtpassFees + $transferFees,
        ];
    }

    private function accumulateCycle(array &$cycles, string $key, string $date, ?string $tenantId, int $amount, int $fees): void
    {
        if (! isset($cycles[$key])) {
            $cycles[$key] = [
                'cycleDate'              => $date,
                'tenantId'               => $tenantId ?? 'global',
                'totalTransactions'      => 0,
                'totalAmountKobo'        => 0,
                'totalProcessorFeesKobo' => 0,
                'platformRevenueKobo'    => 0,
            ];
        }

        $cycles[$key]['totalTransactions']++;
        $cycles[$key]['totalAmountKobo']        += $amount;
        $cycles[$key]['totalProcessorFeesKobo'] += $fees;
        $cycles[$key]['platformRevenueKobo']     = $cycles[$key]['totalAmountKobo'] - $cycles[$key]['totalProcessorFeesKobo'];
    }
}
