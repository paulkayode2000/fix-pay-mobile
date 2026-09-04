<?php

namespace App\Services\Risk;

use App\Models\AppUser;
use App\Models\RiskAlert;
use App\Models\RiskAssessment;
use App\Models\NinePsbTransaction;
use App\Models\Transfer;
use App\Models\VtpassPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Central service for tagging fixpay entities with TMS risk results and
 * raising visual alerts for platform users.
 *
 * Flag-only policy: TMS advises (clear/flagged/blocked); fixpay never
 * auto-blocks — alerts drive manual review/action.
 */
class RiskTagService
{
    private const AML_FLAG_THRESHOLD = 85; // TMS default risk threshold

    /**
     * Create a new assessment row, update the entity's denormalized tags and
     * raise an alert when the result is flagged/blocked.
     */
    public function recordAssessment(
        Model $assessable,
        string $type,                     // AML | ANTIFRAUD
        string $status,                   // PENDING|CLEAR|FLAGGED|BLOCKED|UNAVAILABLE
        ?float $score = null,
        array $payload = [],
        ?string $callRef = null,
        ?string $caseRef = null,
        ?string $decisionId = null,
        ?string $error = null,
    ): RiskAssessment {
        return DB::transaction(function () use ($assessable, $type, $status, $score, $payload, $callRef, $caseRef, $decisionId, $error) {
            $assessment = RiskAssessment::create([
                'assessable_type' => $assessable->getMorphClass(),
                'assessable_id'   => $assessable->getKey(),
                'type'            => $type,
                'status'          => $status,
                'score'           => $score,
                'tags'            => $this->extractTags($type, $payload),
                'payload'         => $payload ?: null,
                'tms_call_ref'    => $callRef,
                'tms_case_ref'    => $caseRef,
                'decision_id'     => $decisionId,
                'error'           => $error,
                'screened_at'     => now(),
            ]);

            $this->updateEntityTags($assessable, $assessment);

            if (in_array($status, ['FLAGGED', 'BLOCKED'], true)) {
                $this->createAlert($assessable, $type, $status, $assessment);
            }

            return $assessment;
        });
    }

    /**
     * Apply a delivered AML screening result (webhook or poll) to an existing
     * PENDING assessment, then re-tag the entity.
     */
    public function applyAmlResult(RiskAssessment $assessment, array $result): RiskAssessment
    {
        $highestScore = (float) (
            $result['highest_score']
            ?? $result['aggregate']['highest_score']
            ?? 0
        );

        $status = $highestScore >= self::AML_FLAG_THRESHOLD ? 'FLAGGED' : 'CLEAR';

        $assessment->update([
            'status'       => $status,
            'score'        => $highestScore > 0 ? $highestScore : null,
            'tags'         => $this->extractTags('AML', $result),
            'payload'      => $result ?: $assessment->payload,
            'tms_case_ref' => $result['case_ref'] ?? $result['aggregate']['case_ref'] ?? $assessment->tms_case_ref,
            'screened_at'  => now(),
            'error'        => null,
        ]);

        $assessable = $assessment->assessable;

        if ($assessable) {
            $this->updateEntityTags($assessable, $assessment->fresh());

            if (in_array($status, ['FLAGGED', 'BLOCKED'], true)) {
                $this->createAlert($assessable, 'AML', $status, $assessment->fresh());
            }
        }

        return $assessment->fresh();
    }

    /**
     * Recompute aggregate risk_status/risk_score (and user risk_tags) from the
     * entity's latest assessments. Worst known result wins.
     */
    public function aggregate(Model $assessable): void
    {
        $assessments = $assessable->riskAssessments()->orderByDesc('screened_at')->get();

        $worst = null;
        $worstRank = 0;
        $worstScore = 0.0;
        $hasClear = false;
        $hasPendingOrUnavailable = false;

        foreach ($assessments as $assessment) {
            if (in_array($assessment->status, ['FLAGGED', 'BLOCKED'], true)) {
                $rank = $assessment->status === 'BLOCKED' ? 2 : 1;
                if ($worst === null || $rank > $worstRank) {
                    $worst = $assessment->status;
                    $worstRank = $rank;
                }
            }

            if ($assessment->status === 'CLEAR') {
                $hasClear = true;
            }
            if (in_array($assessment->status, ['PENDING', 'UNAVAILABLE'], true)) {
                $hasPendingOrUnavailable = true;
            }
            if ($assessment->score !== null && (float) $assessment->score > $worstScore) {
                $worstScore = (float) $assessment->score;
            }
        }

        if ($worst === null) {
            $worst = ($hasClear && ! $hasPendingOrUnavailable) ? 'CLEAR' : 'PENDING';
        }

        $attrs = [
            'risk_status' => $worst,
            'risk_score'  => $worstScore > 0 ? $worstScore : null,
        ];

        if ($assessable instanceof AppUser) {
            $attrs['risk_tags'] = $this->userTags($assessments);
        }

        $assessable->update($attrs);
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    private function updateEntityTags(Model $assessable, RiskAssessment $assessment): void
    {
        $attrs = [];

        if ($assessment->type === 'AML') {
            $attrs = [
                'aml_status'   => $assessment->status,
                'aml_score'    => $assessment->score,
                'aml_case_ref' => $assessment->tms_case_ref,
            ];
        } else {
            $attrs = [
                'antifraud_status' => $assessment->status,
                'antifraud_score'  => $assessment->score,
            ];
        }

        if ($assessable instanceof AppUser) {
            $attrs['aml_screened_at'] = now();
        } else {
            $attrs['risk_screened_at'] = now();
        }

        $assessable->update($attrs);

        $this->aggregate($assessable);
    }

    private function createAlert(Model $assessable, string $type, string $status, RiskAssessment $assessment): RiskAlert
    {
        // Dedupe webhook redeliveries for the same screening call.
        if ($assessment->tms_call_ref) {
            $existing = RiskAlert::where('assessable_type', $assessable->getMorphClass())
                ->where('assessable_id', $assessable->getKey())
                ->where('type', $type)
                ->where('tms_call_ref', $assessment->tms_call_ref);

            if ($existing->exists()) {
                return $existing->first();
            }
        }

        $severity = $this->severityFor($type, $status, $assessment->score);
        $userId = $assessable instanceof AppUser
            ? $assessable->getKey()
            : ($assessable->user_id ?? null);

        $summary = sprintf(
            '%s %s — %s%s',
            $type === 'ANTIFRAUD' ? 'Antifraud' : 'AML',
            $status,
            $this->describeEntity($assessable),
            $assessment->score !== null ? ' (score '.$assessment->score.')' : '',
        );

        return RiskAlert::create([
            'assessable_type' => $assessable->getMorphClass(),
            'assessable_id'   => $assessable->getKey(),
            'user_id'         => $userId,
            'type'            => $type,
            'severity'        => $severity,
            'status'          => 'NEW',
            'summary'         => $summary,
            'detail'          => $assessment->payload ?: null,
            'tms_case_ref'    => $assessment->tms_case_ref,
            'tms_call_ref'    => $assessment->tms_call_ref,
        ]);
    }

    private function severityFor(string $type, string $status, ?float $score): string
    {
        if ($status === 'BLOCKED') {
            return 'CRITICAL';
        }

        if ($type === 'ANTIFRAUD') {
            return $score !== null && $score >= 80 ? 'HIGH' : 'MEDIUM';
        }

        return match (true) {
            $score >= 90 => 'CRITICAL',
            $score >= 85 => 'HIGH',
            $score >= 70 => 'MEDIUM',
            default      => 'LOW',
        };
    }

    private function describeEntity(Model $entity): string
    {
        if ($entity instanceof AppUser) {
            return 'user '.($entity->email ?? $entity->phone ?? $entity->getKey());
        }

        if ($entity instanceof Transfer) {
            return 'transfer '.$entity->transfer_reference;
        }

        if ($entity instanceof VtpassPayment) {
            return 'payment '.$entity->payment_reference;
        }

        if ($entity instanceof NinePsbTransaction) {
            return '9psb txn '.$entity->transaction_id;
        }

        return class_basename($entity).' '.$entity->getKey();
    }

    /** Build a flat list of human-readable tags from a TMS payload. */
    private function extractTags(string $type, array $payload): array
    {
        $tags = [];

        if ($type === 'AML') {
            $screening = $payload['screening_results'] ?? $payload['results'] ?? [];

            foreach ($screening as $kind => $data) {
                if (! is_array($data)) {
                    continue;
                }

                $score = (float) ($data['match_score'] ?? $data['highest_score'] ?? 0);
                $status = $data['result_status'] ?? $data['status'] ?? null;

                if ($score >= 70 || in_array($status, ['match', 'potential_match'], true)) {
                    $tags[] = strtolower((string) $kind).'_match';
                    if (! empty($data['matched_name'])) {
                        $tags[] = 'matched:'.(string) $data['matched_name'];
                    }
                    if (! empty($data['source'])) {
                        $tags[] = 'source:'.(string) $data['source'];
                    }
                }
            }
        } else {
            if (($payload['status'] ?? '') === 'flagged') {
                $tags[] = 'anomaly';
            }
            if (! empty($payload['aml_match']['type']) && $payload['aml_match']['type'] !== 'none') {
                $tags[] = 'aml_match:'.$payload['aml_match']['type'];
            }
            if (! empty($payload['anomaly']['reason'])) {
                $tags[] = (string) $payload['anomaly']['reason'];
            }
        }

        return array_values(array_unique(array_filter($tags)));
    }

    private function userTags(iterable $assessments): array
    {
        $tags = [];

        foreach ($assessments as $assessment) {
            foreach ($assessment->tags ?? [] as $tag) {
                $tags[] = (string) $tag;
            }
        }

        return array_values(array_unique(array_filter($tags)));
    }
}

