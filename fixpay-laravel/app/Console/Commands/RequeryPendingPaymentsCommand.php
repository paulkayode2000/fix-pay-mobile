<?php

namespace App\Console\Commands;

use App\Models\PaymentJournalEntry;
use App\Models\VtpassPayment;
use App\Models\Wallet;
use App\Services\Payment\VtpassService;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves bill payments whose outcome is unknown (submit timeout / provider
 * no-response). Money-safe policy:
 *
 *   - requery says delivered → COMPLETED + commit the hold (customer is debited
 *     for a service the provider delivered)
 *   - requery says failed     → FAILED + release the hold (customer gets the
 *     reserved funds back)
 *   - requery says pending    → keep the hold, requery again (bounded)
 *   - provider unreachable / not found → keep the hold and, after the bounded
 *     requery window, escalate to REQUIRES_RECONCILIATION — the funds stay
 *     reserved so neither the customer nor FixPay is left holding a loss.
 *
 * The hold is NEVER released or committed on an ambiguous/unknown outcome.
 */
class RequeryPendingPaymentsCommand extends Command
{
    protected $signature = 'payments:requery-pending
                            {--dry-run : Print candidates without resolving}';

    protected $description = 'Requery unknown-outcome bill payments and resolve holds safely';

    /** After this many requery attempts an unresolved payment is escalated. */
    protected const MAX_REQUERY_ATTEMPTS = 7;

    public function __construct(
        private readonly VtpassService $vtpass,
        private readonly WalletService $wallet,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Payments still in-flight are allowed a short grace period before the
        // first requery (the provider may have responded within it).
        $graceCutoff = now()->subSeconds((int) env('REQUERY_FIRST_ATTEMPT_AFTER_SECONDS', 90));
        $recheckCutoff = now()->subSeconds((int) env('REQUERY_RECHECK_INTERVAL_SECONDS', 120));

        $candidates = VtpassPayment::whereIn('payment_status', ['PROCESSING', 'PENDING'])
            ->where('requery_count', '<', self::MAX_REQUERY_ATTEMPTS)
            ->where(function ($q) use ($graceCutoff, $recheckCutoff) {
                $q->whereNull('last_requeried_at')
                    ->where('created_at', '<', $graceCutoff)
                    ->orWhereNotNull('last_requeried_at')
                    ->where('last_requeried_at', '<', $recheckCutoff);
            })
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No unknown-outcome payments to requery.');
            return self::SUCCESS;
        }

        $this->info("Requerying {$candidates->count()} payment(s)...");

        foreach ($candidates as $payment) {
            $this->resolve($payment);
        }

        return self::SUCCESS;
    }

    protected function resolve(VtpassPayment $payment): void
    {
        if ($this->option('dry-run')) {
            $this->line("  [{$payment->id}] {$payment->service_id} {$payment->amount_kobo}k status={$payment->payment_status}");
            return;
        }

        $result = $this->vtpass->requery($payment);
        $outcome = $result['outcome'] ?? 'unknown';

        try {
            DB::transaction(function () use ($payment, $outcome, $result) {
                $wallet = Wallet::find($payment->wallet_id);
                $totalKobo = $payment->amount_kobo + $payment->fee_kobo;

                switch ($outcome) {
                    case 'delivered':
                        $payment->update([
                            'payment_status' => 'COMPLETED',
                            'provider_code' => $result['body']['code'] ?? '000',
                            'response_payload' => $result['body'],
                            'requery_count' => $payment->requery_count + 1,
                            'last_requeried_at' => now(),
                            'completed_at' => now(),
                        ]);
                        if ($wallet && $totalKobo > 0) {
                            $this->wallet->commitHold(
                                $wallet, $totalKobo, $payment->payment_reference,
                                "Bill payment confirmed by requery: {$payment->service_id}"
                            );
                        }
                        $this->journal($payment, 'REQUERY', 'COMPLETED', ['outcome' => 'delivered']);
                        $this->info("  [{$payment->id}] DELIVERED → COMPLETED, hold committed.");
                        break;

                    case 'failed':
                        $payment->update([
                            'payment_status' => 'FAILED',
                            'provider_code' => $result['body']['code'] ?? null,
                            'response_payload' => $result['body'],
                            'requery_count' => $payment->requery_count + 1,
                            'last_requeried_at' => now(),
                            'failed_at' => now(),
                        ]);
                        if ($wallet && $totalKobo > 0) {
                            $this->wallet->releaseHold($wallet, $totalKobo);
                        }
                        $this->journal($payment, 'REQUERY', 'FAILED', ['outcome' => 'failed', 'refunded_kobo' => $totalKobo]);
                        $this->info("  [{$payment->id}] FAILED → hold released.");
                        break;

                    case 'pending':
                        $payment->update([
                            'requery_count' => $payment->requery_count + 1,
                            'last_requeried_at' => now(),
                        ]);
                        $this->journal($payment, 'REQUERY', 'PENDING', ['attempt' => $payment->requery_count]);
                        $this->info("  [{$payment->id}] PENDING — hold kept, requery later.");
                        break;

                    default: // not_found / unknown — ambiguous, never resolve the hold.
                        $attempts = $payment->requery_count + 1;
                        if ($attempts >= self::MAX_REQUERY_ATTEMPTS) {
                            $payment->update([
                                'payment_status' => 'REQUIRES_RECONCILIATION',
                                'requery_count' => $attempts,
                                'last_requeried_at' => now(),
                            ]);
                            $this->journal($payment, 'REQUERY', 'ESCALATED',
                                ['outcome' => $outcome, 'hold_kept_kobo' => $payment->amount_kobo + $payment->fee_kobo]);
                            Log::error("Payment {$payment->id} unresolved after {$attempts} requeries — HOLD KEPT, manual reconciliation required.");
                            $this->error("  [{$payment->id}] UNRESOLVED ({$outcome}) after {$attempts} attempts — hold kept, escalated.");
                        } else {
                            $payment->update([
                                'requery_count' => $attempts,
                                'last_requeried_at' => now(),
                            ]);
                            $this->journal($payment, 'REQUERY', 'RETRY',
                                ['outcome' => $outcome, 'attempt' => $attempts]);
                            $this->info("  [{$payment->id}] {$outcome} — hold kept, requery later ({$attempts}/" . self::MAX_REQUERY_ATTEMPTS . ').');
                        }
                        break;
                }
            });
        } catch (\Throwable $e) {
            Log::error("Requery resolution failed for payment {$payment->id}: {$e->getMessage()}");
            $this->error("  Error on [{$payment->id}]: {$e->getMessage()}");
        }
    }

    private function journal(VtpassPayment $payment, string $step, string $status, array $metadata = []): void
    {
        PaymentJournalEntry::create([
            'payment_id' => $payment->id,
            'step' => $step,
            'status' => $status,
            'metadata' => $metadata,
            'actor' => 'system',
        ]);
    }
}

