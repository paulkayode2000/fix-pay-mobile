<?php

use App\Console\Commands\RequeryPendingPaymentsCommand;
use App\Console\Commands\TimeoutStalePaymentsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Resolve unknown-outcome bill payments by requerying the provider (money-safe:
// holds are only committed on delivery or released on confirmed failure).
Schedule::command(RequeryPendingPaymentsCommand::class)->everyTwoMinutes();

// Escalate (hold KEPT) any payment still unresolved past the timeout window.
Schedule::command(TimeoutStalePaymentsCommand::class)->everyFiveMinutes();

// Poll TMS for still-pending AML screenings (webhook fallback)
Schedule::job(\App\Jobs\CheckTmsCallsJob::class)->everyThreeMinutes();

