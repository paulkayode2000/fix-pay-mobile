<?php

namespace App\Console\Commands;

use App\Models\PaymentJournalEntry;
use App\Models\VtpassPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Escalates bill payments that could not be resolved by the requery job.
 *
 * <p><strong>Money-safe:</strong> this command NEVER reverses or releases a
 * hold. Payments whose provider outcome is still unknown after the requery
 * window are marked {@code REQUIRES_RECONCILIATION} and the wallet hold is KEPT
 * — the reserved funds belong to neither side until a human reconciles the
 * provider statement. Releasing here would risk a real loss whenever the
 * provider actually delivered.</p>
 */
class TimeoutStalePaymentsCommand extends Command
{
    protected $signature = 'payments:timeout-stale
                            {--dry-run : Print stale payments without escalating}';

    protected $description = 'Escalate unresolved bill payments to manual reconciliation (hold kept)';

    public function handle(): int
    {
        $ttlSeconds = (int) env('PAYMENT_TIMEOUT_SECONDS', 300);
        $cutoff = now()->subSeconds($ttlSeconds);

        $stale = VtpassPayment::whereIn('payment_status', ['PROCESSING', 'PENDING'])
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale payments found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stale->count()} stale payment(s).");

        if ($this->option('dry-run')) {
            $stale->each(fn ($p) => $this->line("  [{$p->id}] {$p->service_id} {$p->amount_kobo}k status={$p->payment_status}"));
            return self::SUCCESS;
        }

        $escalated = 0;
        foreach ($stale as $payment) {
            try {
                $payment->update([
                    'payment_status' => 'REQUIRES_RECONCILIATION',
                ]);
                PaymentJournalEntry::create([
                    'payment_id' => $payment->id,
                    'step' => 'TIMEOUT_ESCALATION',
                    'status' => 'REQUIRES_RECONCILIATION',
                    'metadata' => ['hold_kept_kobo' => $payment->amount_kobo + $payment->fee_kobo],
                    'actor' => 'system',
                ]);
                $escalated++;
                Log::error("Payment {$payment->id} escalated to REQUIRES_RECONCILIATION — wallet hold KEPT (manual reconciliation).");
            } catch (\Throwable $e) {
                Log::error("Failed to escalate payment {$payment->id}: {$e->getMessage()}");
                $this->error("  Error on [{$payment->id}]: {$e->getMessage()}");
            }
        }

        $this->info("Done. Escalated {$escalated}/{$stale->count()} payments (holds kept).");
        return self::SUCCESS;
    }
}
