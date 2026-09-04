<?php

namespace App\Jobs;

use App\Models\AppUser;
use App\Services\Risk\RiskTagService;
use App\Services\Tms\AmlClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Slow, independent AML screen for a transaction's user. Submitted in async
 * mode — TMS delivers the result to our webhook later (call_ref tracked here).
 */
class TransactionAmlScreenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(public Model $transaction) {}

    public function handle(AmlClient $aml, RiskTagService $riskTags): void
    {
        if (! $aml->enabled()) {
            return;
        }

        $user = $this->transaction->user ?? null;

        if (! $user) {
            return;
        }

        $result = $aml->screenAsync($this->customerPayload($user, $this->transaction));

        if (isset($result['call_ref'])) {
            $riskTags->recordAssessment(
                assessable: $this->transaction,
                type: 'AML',
                status: 'PENDING',
                payload: $result,
                callRef: (string) $result['call_ref'],
            );
        } elseif (($result['status'] ?? '') !== 'disabled') {
            $riskTags->recordAssessment(
                assessable: $this->transaction,
                type: 'AML',
                status: 'UNAVAILABLE',
                payload: $result,
                error: $result['message'] ?? 'TMS screening unavailable',
            );
        }
    }

    protected function customerPayload(AppUser $user, Model $transaction): array
    {
        return [
            'request_id'           => (string) Str::uuid(),
            'client_reference'     => 'transaction:'.$transaction->getKey(),
            'screening_mode'       => 'async',
            'screening_types'      => ['sanctions', 'pep', 'adverse_media', 'fraud'],
            'name'                 => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'date_of_birth'        => $user->date_of_birth?->format('Y-m-d'),
            'nationality'          => 'NG',
            'country_of_residence' => 'NG',
        ];
    }
}
