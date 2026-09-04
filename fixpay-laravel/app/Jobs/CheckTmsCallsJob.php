<?php

namespace App\Jobs;

use App\Models\RiskAssessment;
use App\Services\Risk\RiskTagService;
use App\Services\Tms\AmlClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Polls TMS for still-pending AML screenings (fallback when the webhook is
 * missed or delayed). Run on a schedule.
 */
class CheckTmsCallsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function handle(AmlClient $aml, RiskTagService $riskTags): void
    {
        if (! $aml->enabled()) {
            return;
        }

        $pending = RiskAssessment::where('type', 'AML')
            ->where('status', 'PENDING')
            ->whereNotNull('tms_call_ref')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->orderBy('created_at')
            ->limit(25)
            ->get();

        foreach ($pending as $assessment) {
            $result = $aml->poll($assessment->tms_call_ref);
            $status = $result['status'] ?? null;

            if ($status === 'completed') {
                $riskTags->applyAmlResult($assessment, $result);
            } elseif (in_array($status, ['failed', 'error'], true)) {
                $assessment->update(['status' => 'UNAVAILABLE', 'error' => 'TMS call failed']);

                if ($assessment->assessable) {
                    $riskTags->aggregate($assessment->assessable);
                }
            }
        }
    }
}
