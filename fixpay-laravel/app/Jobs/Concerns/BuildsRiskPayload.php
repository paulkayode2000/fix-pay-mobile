<?php

namespace App\Jobs\Concerns;

use App\Models\NinePsbTransaction;
use App\Models\Transfer;
use App\Models\VtpassPayment;

/**
 * Builders shared by the TMS risk jobs to normalise the different fixpay
 * transaction models (Transfer / VtpassPayment / NinePsbTransaction) into the
 * payload shape expected by the antifraud-service.
 */
trait BuildsRiskPayload
{
    protected function transactionReference($transaction): string
    {
        return match (true) {
            $transaction instanceof Transfer        => $transaction->transfer_reference,
            $transaction instanceof VtpassPayment    => $transaction->payment_reference,
            $transaction instanceof NinePsbTransaction => $transaction->transaction_id,
            default                                 => (string) $transaction->getKey(),
        };
    }

    protected function transactionAmount($transaction): float
    {
        return match (true) {
            $transaction instanceof Transfer        => (float) $transaction->amount_kobo / 100,
            $transaction instanceof VtpassPayment    => (float) $transaction->amount_kobo / 100,
            $transaction instanceof NinePsbTransaction => (float) $transaction->amount,
            default                                 => 0.0,
        };
    }

    protected function transactionType($transaction): string
    {
        return match (true) {
            $transaction instanceof Transfer        => 'transfer',
            $transaction instanceof VtpassPayment    => $transaction->service_id ?? 'bill_payment',
            $transaction instanceof NinePsbTransaction => $transaction->transaction_type ?? 'wallet',
            default                                 => 'payment',
        };
    }

    protected function transactionCounterparty($transaction): ?string
    {
        return match (true) {
            $transaction instanceof Transfer        => trim(($transaction->account_name ?? '').' '.($transaction->account_number ?? '')),
            $transaction instanceof VtpassPayment    => $transaction->phone ?? $transaction->billersCode,
            $transaction instanceof NinePsbTransaction => $transaction->account_number,
            default                                 => null,
        };
    }

    /** The antifraud payload schema requires an integer customer_id (fits int32). */
    protected function customerIdNumeric(string $userId): int
    {
        return crc32($userId) % 1000000000;
    }
}
