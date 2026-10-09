<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'owner@sanjgarva.in',
            'password' => 'ChangeMe@123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'expires_at',
                    'user' => ['id', 'name', 'email', 'mobile'],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('owner@sanjgarva.in', $response->json('data.user.email'));
    }

    public function test_login_with_mobile_number_succeeds(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => '9999999999',
            'password' => 'ChangeMe@123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.mobile', '9999999999');
    }

    public function test_login_with_invalid_password_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'owner@sanjgarva.in',
            'password' => 'IncorrectPassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION')
            ->assertJsonValidationErrors(['login']);
    }

    public function test_login_with_nonexistent_user_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'nonexistent@sanjgarva.in',
            'password' => 'AnyPassword123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION')
            ->assertJsonValidationErrors(['login']);
    }

    public function test_login_with_missing_credentials_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['login', 'password']);
    }

    public function test_authenticated_user_can_access_me_and_logout(): void
    {
        $loginRes = $this->postJson('/api/auth/login', [
            'login' => 'owner@sanjgarva.in',
            'password' => 'ChangeMe@123',
        ])->assertOk();

        $token = $loginRes->json('data.token');

        // Access /api/auth/me
        $meRes = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertSame('owner@sanjgarva.in', $meRes->json('data.email'));

        // Logout
        $logoutRes = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $logoutRes->assertJsonPath('success', true);
    }

    public function test_health_endpoint_reports_ready_when_operational(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('database.status', 'connected')
            ->assertJsonPath('database.has_users_table', true)
            ->assertJsonPath('database.error_category', 'healthy');
    }
}
