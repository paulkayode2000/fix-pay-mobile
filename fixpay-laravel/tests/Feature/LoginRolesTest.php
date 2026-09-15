<?php

namespace Tests\Feature;

use App\Models\AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The login response must echo the user's Spatie roles so the PWA can gate the
 * platform admin console without any Keycloak/JWT role claim.
 */
class LoginRolesTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): AppUser
    {
        return AppUser::factory()->create([
            'password_hash' => Hash::make('Secret123!'),
            'status' => 'ACTIVE',
        ]);
    }

    public function test_login_echoes_the_admin_role(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $admin = $this->makeUser();
        $admin->assignRole('admin');

        $response = $this->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/auth/login', [
                'identifier' => $admin->email,
                'password'   => 'Secret123!',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.roles', ['admin']);
    }

    public function test_login_does_not_echo_admin_for_consumer_users(): void
    {
        Role::firstOrCreate(['name' => 'consumer', 'guard_name' => 'web']);

        $user = $this->makeUser();

        $response = $this->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/auth/login', [
                'identifier' => $user->email,
                'password'   => 'Secret123!',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.roles', []);
    }
}
