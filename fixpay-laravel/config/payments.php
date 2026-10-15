<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment amount bounds
    |--------------------------------------------------------------------------
    |
    | Server-side source of truth for bill-payment amount limits, in kobo.
    | These mirror the limits the mobile app (fixpay-pwa) enforces on its
    | payment screens so a direct API caller cannot bypass them.
    |
    | `max => null` means "no ceiling". Enforced in
    | App\Http\Controllers\Payment\VtpassPaymentController::pay() against the
    | incoming `service_id`. Adjust here (no code change) if limits change.
    |
    */

    'amount_bounds' => [
        'default'     => ['min' => 100, 'max' => null],       // ₦1 floor
        'airtime'     => ['min' => 5000, 'max' => 20000000],  // ₦50 – ₦200,000
        'electricity' => ['min' => 50000, 'max' => null],     // ₦500 minimum
    ],

    /* VTPass service ids treated as airtime (Buy Airtime screen). */
    'airtime_services' => [
        'airtime', 'mtn', 'airtel', 'glo', 'etisalat', 'foreign-airtime',
    ],

    /* VTPass service ids treated as electricity (Buy Electricity screen). */
    'electricity_services' => [
        'ikeja-electric', 'eko-electric', 'abuja-electric', 'kano-electric',
        'enugu-electric', 'ibadan-electric', 'phed', 'benin-electric',
        'kaduna-electric', 'jos-electric', 'aba-electric', 'yola-electric',
    ],

    /*
    |--------------------------------------------------------------------------
    | Welcome bonus (TEMPORARY — dev funding aid)
    |--------------------------------------------------------------------------
    |
    | Registration no longer opens a wallet, so this bonus follows the wallet
    | creation event instead (the KYC-gated explicit step). It exists so testers
    | have spendable balance; set WELCOME_BONUS_ENABLED=false to switch it off
    | and delete this block once the temporary bonus is retired.
    |
    */

    'welcome_bonus' => [
        'enabled'     => env('WELCOME_BONUS_ENABLED', true),
        'amount_kobo' => (int) env('WELCOME_BONUS_KOBO', 50000 * 100), // ₦50,000
    ],

];
