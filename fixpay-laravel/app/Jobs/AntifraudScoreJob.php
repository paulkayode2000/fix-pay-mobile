<?php

namespace App\Jobs;

use App\Jobs\Concerns\BuildsRiskPayload;
use App\Services\Risk\RiskTagService;
use App\Services\Tms\AntifraudClient;
use App\Services\Tms\RiskRulesCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fast, independent antifraud check for a transaction. Runs the ML scoring
 * service; result tags the transaction (antifraud_status / risk_status) and
 * raises an alert when flagged.
 */
class AntifraudScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, BuildsRiskPayload;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(public Model $transaction, public ?string $deviceId = null) {}

    public function handle(AntifraudClient $client, RiskTagService $riskTags, RiskRulesCache $rulesCache): void
    {
        if (! $client->enabled()) {
            return;
        }

        $user = $this->transaction->user ?? null;

        if (! $user) {
            return;
        }

        $businessId = (string) config('services.gateway.business_id', '');
        $amount = $this->transactionAmount($this->transaction);

        // Decide from the TMS-published ruleset (cached in memory) whether this
        // transaction needs the AML watchlist and/or the ML fraud score.
        $rules = $rulesCache->get('mobile', $businessId);
        $aml = $rules['aml'] ?? [];
        $fraud = $rules['fraud'] ?? [];
        $needsAml = ($aml['enabled'] ?? true)
            && $amount >= (float) ($aml['amount_threshold'] ?? 1_000_000);
        $needsFraud = ($fraud['enabled'] ?? true)
            && $amount >= (float) ($fraud['amount_threshold'] ?? 100_000);

        if (! $needsAml && ! $needsFraud) {
            $riskTags->recordAssessment(
                assessable: $this->transaction,
                type: 'ANTIFRAUD',
                status: 'SKIPPED',
                payload: [
                    'reason' => 'below_ruleset_threshold',
                    'rules_version' => $rules['version'] ?? null,
                ],
                decisionId: null,
            );

            return;
        }

        $payload = [
            'ref_no'              => $this->transactionReference($this->transaction),
            'customer_id'         => $this->customerIdNumeric((string) $user->getKey()),
            'amount'              => $amount,
            'currency'            => 'NGN',
            'type'                => $this->transactionType($this->transaction),
            'country_origin'      => 'NG',
            'country_destination' => 'NG',
            'counterparty'        => $this->transactionCounterparty($this->transaction),
            'aml_check'           => $needsAml,
            'fraud_check'         => $needsFraud,
            'metadata'            => [
                'fixpay_entity_type' => get_class($this->transaction),
                'fixpay_entity_id'   => (string) $this->transaction->getKey(),
                // Scoped identity — TMS velocity/blocking keys on
                // (source, business_id, user_id) so the mobile product's user
                // directory never collides with other channels (pos, portal, ...).
                'source'             => 'mobile',
                'business_id'        => $businessId,
                'user_id'            => (string) $user->getKey(),
                'device_id'          => $this->deviceId ?? '',
            ],
        ];

        $result = $client->scoreTransaction($payload);

        // Integration switched off — do not tag.
        if (($result['status'] ?? '') === 'disabled') {
            return;
        }

        $success = in_array($result['status'] ?? '', ['clear', 'flagged'], true);
        $status = $success ? (($result['status'] === 'flagged') ? 'FLAGGED' : 'CLEAR') : 'UNAVAILABLE';
        $score = $result['anomaly']['score'] ?? $result['score'] ?? null;

        $riskTags->recordAssessment(
            assessable: $this->transaction,
            type: 'ANTIFRAUD',
            status: $status,
            score: is_numeric($score) ? (float) $score : null,
            payload: $result,
            decisionId: isset($result['transaction_id']) ? (string) $result['transaction_id'] : null,
            error: $success ? null : ($result['message'] ?? null),
        );
    }
}
