<?php

namespace Tests\Feature;

use App\Models\AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The API must enforce the same amount bounds as the mobile app so a direct
 * caller cannot bypass them (config/payments.php + VtpassPaymentController).
 */
class PaymentAmountBoundsTest extends TestCase
{
    use RefreshDatabase;

    private function postVtpass(array $payload)
    {
        $user = AppUser::factory()->create(['status' => 'ACTIVE']);

        return $this->actingAs($user)
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/payments/vtpass', $payload);
    }

    public function test_airtime_below_the_50_naira_floor_is_rejected(): void
    {
        // ₦1 airtime: allowed by the old `min:100` rule, blocked by the new policy.
        $response = $this->postVtpass([
            'service_id'  => 'mtn',
            'amount_kobo' => 100,
            'phone'       => '08011111111',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount_kobo');
    }

    public function test_airtime_above_the_200k_cap_is_rejected(): void
    {
        $response = $this->postVtpass([
            'service_id'  => 'mtn',
            'amount_kobo' => 20000001, // ₦200,000.01
            'phone'       => '08011111111',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount_kobo');
    }

    public function test_electricity_below_the_500_naira_floor_is_rejected(): void
    {
        $response = $this->postVtpass([
            'service_id'  => 'ikeja-electric',
            'amount_kobo' => 10000, // ₦100
            'phone'       => '08011111111',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount_kobo');
    }

    public function test_unlisted_services_keep_the_one_naira_floor(): void
    {
        $response = $this->postVtpass([
            'service_id'   => 'dstv',
            'amount_kobo'  => 50,
            'phone'        => '08011111111',
            'billers_code' => '1234567890',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount_kobo');
    }
}
