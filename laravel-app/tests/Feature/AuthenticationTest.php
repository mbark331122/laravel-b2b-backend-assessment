<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_user_a_can_authenticate(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => UserSeeder::USER_A_EMAIL,
            'password' => UserSeeder::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', UserSeeder::USER_A_EMAIL)
            ->assertJsonPath('user.name', 'User A')
            ->assertJsonPath('user.is_admin', false)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_user_b_can_authenticate(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => UserSeeder::USER_B_EMAIL,
            'password' => UserSeeder::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', UserSeeder::USER_B_EMAIL)
            ->assertJsonPath('user.name', 'User B')
            ->assertJsonStructure(['token']);
    }

    public function test_admin_user_can_authenticate(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => UserSeeder::ADMIN_EMAIL,
            'password' => UserSeeder::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', UserSeeder::ADMIN_EMAIL)
            ->assertJsonPath('user.name', 'Admin User')
            ->assertJsonPath('user.is_admin', true)
            ->assertJsonStructure(['token']);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->postJson('/api/login', [
            'email' => UserSeeder::USER_A_EMAIL,
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_view_their_profile(): void
    {
        $user = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', UserSeeder::USER_A_EMAIL)
            ->assertJsonPath('user.company.name', 'Company A');
    }

    public function test_unauthenticated_user_cannot_view_profile(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_login_does_not_use_company_id_from_the_request(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => UserSeeder::USER_A_EMAIL,
            'password' => UserSeeder::PASSWORD,
            'company_id' => 999,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.company.name', 'Company A')
            ->assertJsonPath('user.email', UserSeeder::USER_A_EMAIL);
    }
}
