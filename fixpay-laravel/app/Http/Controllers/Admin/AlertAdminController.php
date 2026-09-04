<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\RiskAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin API for the TMS risk-alert inbox. Alerts are raised when an AML or
 * antifraud check returns flagged/blocked. Flag-only policy: the alert is
 * where platform users see the catch and take action (review/dismiss/escalate).
 */
class AlertAdminController extends Controller
{
    /** GET /api/admin/alerts?status=&type=&severity=&userId=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $query = RiskAlert::query();

        foreach (['status', 'type', 'severity', 'user_id'] as $filter) {
            if ($request->query($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        $alerts = $query->with('user')
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data'         => $alerts->getCollection()->map(fn (RiskAlert $a) => $this->mapAlert($a))->values(),
            'current_page' => $alerts->currentPage(),
            'last_page'    => $alerts->lastPage(),
            'total'        => $alerts->total(),
        ]);
    }

    /** GET /api/admin/alerts/unread-count */
    public function unreadCount(): JsonResponse
    {
        $count = RiskAlert::where('status', 'NEW')->whereNull('seen_at')->count();

        return response()->json(['count' => $count]);
    }

    /** POST /api/admin/alerts/seen — mark all NEW alerts as seen (clears the bell badge). */
    public function markSeen(): JsonResponse
    {
        $updated = RiskAlert::where('status', 'NEW')->whereNull('seen_at')
            ->update(['seen_at' => now()]);

        return response()->json(['marked' => $updated]);
    }

    /** PATCH /api/admin/alerts/{id} — action: REVIEWED | DISMISSED | ESCALATED */
    public function update(Request $request, string $id): JsonResponse
    {
        $alert = RiskAlert::findOrFail($id);

        $data = $request->validate([
            'action' => 'required|in:REVIEWED,DISMISSED,ESCALATED',
            'note'   => 'nullable|string|max:1000',
        ]);

        $alert->update([
            'status'      => $data['action'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'seen_at'     => $alert->seen_at ?? now(),
        ]);

        // Escalation = manual override: suspend the flagged user (flag-only → action).
        if ($data['action'] === 'ESCALATED' && $alert->user_id) {
            AppUser::where('id', $alert->user_id)->update(['status' => 'SUSPENDED']);
        }

        return response()->json($this->mapAlert($alert->fresh()));
    }

    private function mapAlert(RiskAlert $alert): array
    {
        $user = $alert->user;

        return [
            'id'             => $alert->id,
            'type'           => $alert->type,
            'severity'       => $alert->severity,
            'status'         => $alert->status,
            'summary'        => $alert->summary,
            'detail'         => $alert->detail,
            'userId'         => $alert->user_id,
            'user'           => $user ? [
                'id'         => $user->id,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
            ] : null,
            'assessableType' => $alert->assessable_type,
            'assessableId'   => $alert->assessable_id,
            'tmsCaseRef'     => $alert->tms_case_ref,
            'tmsCallRef'     => $alert->tms_call_ref,
            'createdAt'      => $alert->created_at?->toISOString(),
            'reviewedAt'     => $alert->reviewed_at?->toISOString(),
        ];
    }
}
