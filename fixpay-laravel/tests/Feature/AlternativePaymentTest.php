<?php

namespace Tests\Feature;

use App\Models\AlternativePayment;
use App\Models\AppUser;
use App\Models\VtpassPayment;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Pay with Payfixy" must let ANY user pay a bill — with or without a wallet —
 * by routing a normal merchant bill payment through the Payfixy Gateway.
 *
 * Wallet-funded bills keep their own money path (wallet hold, recorded in
 * `vtpass_payments`); the alternative rail records in `alternative_payments`
 * and never touches a wallet balance.
 */
class AlternativePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY_PAY = '*/api/v1/mobile/bills/pay';

    /** Fake the gateway (and any TMS call the risk jobs make). */
    private function fakeGateway(?array $body = null, int $status = 200): void
    {
        Http::fake([
            self::GATEWAY_PAY => Http::response($body ?? [
                'status' => true,
                'data' => [
                    'reference' => 'GW-REF-1001',
                    'status' => 'success',
                    'responseCode' => '00',
                ],
            ], $status),
            '*' => Http::response(['status' => 'clear'], 200),
        ]);
    }

    private function initiate(AppUser $user, array $payload = [], ?string $key = null)
    {
        return $this->actingAs($user)
            ->withHeader('X-Idempotency-Key', $key ?? Str::uuid()->toString())
            ->postJson('/api/payments/alternative/initiate', array_merge([
                'service_id'  => 'mtn',
                'amount_kobo' => 10000,
                'phone'       => '08011111111',
            ], $payload));
    }

    private function actingUser(): AppUser
    {
        return AppUser::factory()->create(['status' => 'ACTIVE']);
    }

    private function activeWallet(AppUser $user, int $balanceKobo = 500000): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'balance_kobo' => $balanceKobo,
            'ledger_balance_kobo' => $balanceKobo,
            'currency' => 'NGN',
            'status' => 'ACTIVE',
            'virtual_account_number' => '1234567890',
            'virtual_account_bank' => 'Providus Bank',
            'virtual_account_bank_code' => '101',
            'virtual_account_reference' => 'ref-123',
        ]);
    }

    public function test_a_user_without_a_wallet_can_pay_with_payfixy(): void
    {
        $this->fakeGateway();
        $user = $this->actingUser();

        $response = $this->initiate($user);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('payment_method', 'payfixy')
            ->assertJsonPath('vtpass_code', '00')
            ->assertJsonStructure([
                'payment_reference',
                'gateway_reference',
                'amount_kobo',
                'fee_kobo',
            ]);

        $payment = AlternativePayment::where('user_id', $user->id)->firstOrFail();

        $this->assertNull($payment->wallet_id, 'The alternative rail must not require a wallet.');
        $this->assertSame('payfixy', $payment->payment_method);
        $this->assertSame('mtn', $payment->service_id);
        $this->assertSame(10000, $payment->amount_kobo);
        $this->assertSame('COMPLETED', $payment->payment_status);
        $this->assertSame('GW-REF-1001', $payment->gateway_reference);

        // The gateway must receive the canonical merchant bill payload.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/mobile/bills/pay')
                && $request['payment_method'] === 'payfixy'
                && $request['service_id'] === 'mtn'
                && (int) $request['amount'] === 100
                && ! array_key_exists('wallet_provider', $request->data());
        });
    }

    public function test_the_wallet_rail_needs_a_wallet_but_payfixy_does_not(): void
    {
        $user = $this->actingUser();

        // Wallet rail: unchanged policy — a user without an active wallet is refused.
        $this->actingAs($user)
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/payments/vtpass', [
                'service_id'  => 'mtn',
                'amount_kobo' => 10000,
                'phone'       => '08011111111',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Wallet not available.');

        $this->assertSame(0, VtpassPayment::where('user_id', $user->id)->count());

        // Alternative rail: the same user can pay.
        $this->fakeGateway();
        $this->initiate($user)->assertStatus(200);

        $this->assertSame(1, AlternativePayment::where('user_id', $user->id)->count());
    }

    public function test_a_wallet_holder_can_pay_without_debiting_the_wallet(): void
    {
        $this->fakeGateway();
        $user = $this->actingUser();
        $wallet = $this->activeWallet($user, 500000);

        $this->initiate($user)->assertStatus(200);

        $payment = AlternativePayment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($wallet->id, $payment->wallet_id, 'The wallet is recorded for audit only.');

        // No hold, no debit: the gateway settles the bill on its own rail.
        $this->assertSame(500000, $wallet->fresh()->balance_kobo);
        $this->assertSame(0, $wallet->ledgerEntries()->count());
    }

    public function test_a_rejected_gateway_payment_is_recorded_as_failed(): void
    {
        $this->fakeGateway(['status' => false, 'message' => 'Insufficient provider float'], 400);
        $user = $this->actingUser();

        $this->initiate($user)->assertStatus(422);

        $payment = AlternativePayment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('FAILED', $payment->payment_status);
        $this->assertNotNull($payment->failed_at);
        $this->assertNull($payment->wallet_id);
    }

    public function test_verify_replays_the_recorded_outcome_for_that_user_only(): void
    {
        $this->fakeGateway();
        $user = $this->actingUser();
        $other = $this->actingUser();

        $reference = $this->initiate($user)->json('gateway_reference');

        $this->actingAs($user)
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/payments/alternative/verify', ['gateway_reference' => $reference])
            ->assertStatus(200)
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('gateway_reference', $reference);

        // Another user can never read it, even with the exact reference.
        $this->actingAs($other)
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/payments/alternative/verify', ['gateway_reference' => $reference])
            ->assertStatus(404);
    }

    public function test_replaying_the_same_idempotency_key_charges_once(): void
    {
        $this->fakeGateway();
        $user = $this->actingUser();
        $key = Str::uuid()->toString();

        $first = $this->initiate($user, [], $key);
        $second = $this->initiate($user, [], $key);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame($first->getContent(), $second->getContent());

        $this->assertSame(1, AlternativePayment::where('user_id', $user->id)->count());
    }

    public function test_amount_bounds_are_enforced_on_the_alternative_rail(): void
    {
        $this->fakeGateway();
        $user = $this->actingUser();

        // ₦1 of airtime is below the ₦50 floor the wallet rail enforces too.
        $this->initiate($user, ['amount_kobo' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_kobo');

        $this->assertSame(0, AlternativePayment::where('user_id', $user->id)->count());
    }

    public function test_the_rail_is_unavailable_when_the_gateway_is_disabled(): void
    {
        $this->fakeGateway();
        config(['services.gateway.enabled' => false]);

        $this->initiate($this->actingUser())->assertStatus(503);

        $this->assertSame(0, AlternativePayment::count());
    }

    public function test_registration_does_not_open_a_wallet_and_login_reports_that(): void
    {
        Http::fake(['*' => Http::response(['status' => 'clear'], 200)]);

        $email = 'optional.wallet.'.Str::random(6).'@fixpay.test';

        $this->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/auth/register', [
                'phone'      => '+23480'.random_int(10000000, 99999999),
                'email'      => $email,
                'first_name' => 'Optional',
                'last_name'  => 'Wallet',
                'password'   => 'Password123!',
            ])->assertStatus(201)->assertJsonPath('has_wallet', false);

        $user = AppUser::where('email', $email)->firstOrFail();
        $this->assertNull($user->wallet, 'Registration must not create a wallet any more.');
        $this->assertFalse($user->hasActiveWallet());

        $this->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/auth/login', [
                'identifier' => $email,
                'password'   => 'Password123!',
            ])->assertStatus(200)
            ->assertJsonPath('has_wallet', false)
            ->assertJsonPath('wallet_status', 'INACTIVE');

        // …and a wallet holder is reported as such.
        $this->activeWallet($user);

        $this->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/auth/login', [
                'identifier' => $email,
                'password'   => 'Password123!',
            ])->assertStatus(200)
            ->assertJsonPath('has_wallet', true)
            ->assertJsonPath('wallet_status', 'ACTIVE');
    }
}
