<?php

namespace App\Jobs;

use App\Models\AppUser;
use App\Services\Risk\RiskTagService;
use App\Services\Tms\AmlClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Screen a customer (AML) asynchronously at onboarding/registration time.
 */
class ScreenCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(public AppUser $user) {}

    public function handle(AmlClient $aml, RiskTagService $riskTags): void
    {
        if (! $aml->enabled()) {
            return;
        }

        $fullName = trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? ''));

        if ($fullName === '') {
            return;
        }

        $result = $aml->screenAsync([
            'request_id'           => (string) Str::uuid(),
            'client_reference'     => 'user:'.$this->user->getKey(),
            'screening_mode'       => 'async',
            'screening_types'      => ['sanctions', 'pep', 'adverse_media', 'fraud'],
            'name'                 => $fullName,
            'date_of_birth'        => $this->user->date_of_birth?->format('Y-m-d'),
            'nationality'          => 'NG',
            'country_of_residence' => 'NG',
        ]);

        if (isset($result['call_ref'])) {
            $riskTags->recordAssessment(
                assessable: $this->user,
                type: 'AML',
                status: 'PENDING',
                payload: $result,
                callRef: (string) $result['call_ref'],
            );
        } elseif (($result['status'] ?? '') !== 'disabled') {
            $riskTags->recordAssessment(
                assessable: $this->user,
                type: 'AML',
                status: 'UNAVAILABLE',
                payload: $result,
                error: $result['message'] ?? 'TMS screening unavailable',
            );
        }
    }
}
