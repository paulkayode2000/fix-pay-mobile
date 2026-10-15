<?php

namespace App\Support;

/**
 * Server-side source of truth for bill-payment amount limits, in kobo.
 *
 * Mirrors the limits the mobile app (fixpay-pwa) enforces on its payment
 * screens so a direct API caller cannot bypass them. Shared by every bill
 * entry point (wallet rail in VtpassPaymentController and the non-wallet
 * Payfixy rail in AlternativePaymentController).
 *
 * `max => null` means "no ceiling"; the bounds come from config/payments.php
 * against the incoming `service_id`.
 */
class BillAmounts
{
    /**
     * Resolve the kobo bounds for a bill service id.
     *
     * @return array{min:int, max:int|null}
     */
    public static function bounds(?string $serviceId): array
    {
        $defaults = ['min' => 100, 'max' => null];

        if ($serviceId === null) {
            return $defaults;
        }

        if (in_array($serviceId, (array) config('payments.airtime_services', []), true)) {
            $key = 'airtime';
        } elseif (in_array($serviceId, (array) config('payments.electricity_services', []), true)) {
            $key = 'electricity';
        } else {
            $key = 'default';
        }

        return (array) config("payments.amount_bounds.{$key}", $defaults) + $defaults;
    }

    /**
     * Build the `amount_kobo` validation rules for the given service.
     *
     * @return array<int, string>
     */
    public static function rules(?string $serviceId): array
    {
        $bounds = self::bounds($serviceId);

        $rules = ['required', 'integer', 'min:'.$bounds['min']];

        if ($bounds['max'] !== null) {
            $rules[] = 'max:'.$bounds['max'];
        }

        return $rules;
    }
}
