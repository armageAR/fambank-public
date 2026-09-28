<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL    = 'user@example.com';
    private const USERNAME = 'testuser';
    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:' . self::USERNAME);
    }

    // =========================================================================
    // POST /api/auth/login
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_returns_token_and_user_on_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $this->postJson('/api/auth/login', [
            'username'    => self::USERNAME,
            'password'    => self::PASSWORD,
            'device_name' => 'Chrome Android',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'user' => ['id', 'name', 'username', 'email', 'role']],
                'message',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_updates_last_login_at(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $this->assertNull($user->last_login_at);

        $this->postJson('/api/auth/login', [
            'username'    => self::USERNAME,
            'password'    => self::PASSWORD,
            'device_name' => 'test',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_returns_401_on_wrong_password(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $this->postJson('/api/auth/login', [
            'username'    => self::USERNAME,
            'password'    => 'wrong-password',
            'device_name' => 'test',
        ])->assertUnauthorized();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_validates_required_fields(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'password', 'device_name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_returns_403_for_inactive_user(): void
    {
        User::factory()->inactive()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $this->postJson('/api/auth/login', [
            'username'    => self::USERNAME,
            'password'    => self::PASSWORD,
            'device_name' => 'test',
        ])->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_rate_limits_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $payload = ['username' => self::USERNAME, 'password' => 'wrong', 'device_name' => 'test'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_429_response_includes_available_in_seconds(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $payload = ['username' => self::USERNAME, 'password' => 'wrong', 'device_name' => 'test'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', $payload);
        }

        $this->postJson('/api/auth/login', $payload)
            ->assertStatus(429)
            ->assertJsonPath('data.available_in', fn ($v) => $v > 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_clears_rate_limiter_on_success(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $wrong   = ['username' => self::USERNAME, 'password' => 'wrong',    'device_name' => 'test'];
        $correct = ['username' => self::USERNAME, 'password' => self::PASSWORD, 'device_name' => 'test'];

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', $wrong)->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', $correct)->assertOk();

        // After clearing, 5 new wrong attempts should be allowed before a 429
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::clear('login:' . self::USERNAME);
        }

        $this->assertEquals(0, RateLimiter::attempts('login:' . self::USERNAME));
    }

    // =========================================================================
    // POST /api/auth/logout
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function logout_revokes_current_token(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function logout_returns_401_without_token(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    // =========================================================================
    // GET /api/auth/me
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function me_returns_401_without_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    // =========================================================================
    // POST /api/auth/forgot-password
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function forgot_password_returns_200_for_existing_email(): void
    {
        Notification::fake();

        User::factory()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $this->postJson('/api/auth/forgot-password', ['email' => self::EMAIL])
            ->assertOk()
            ->assertJsonPath('message', fn ($v) => str_contains($v, 'Si el email'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function forgot_password_returns_200_for_nonexistent_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'noexiste@example.com'])
            ->assertOk()
            ->assertJsonPath('message', fn ($v) => str_contains($v, 'Si el email'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function forgot_password_sends_notification_to_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => self::EMAIL, 'username' => self::USERNAME]);

        $this->postJson('/api/auth/forgot-password', ['email' => self::EMAIL]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function forgot_password_does_not_send_notification_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'ghost@example.com']);

        Notification::assertNothingSent();
    }

    // =========================================================================
    // POST /api/auth/reset-password
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function reset_password_resets_password_with_valid_token(): void
    {
        $user  = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertOk();

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('newpassword1', $user->fresh()->password)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reset_password_revokes_all_user_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('device-1');
        $user->createToken('device-2');

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reset_password_returns_422_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/reset-password', [
            'token'                 => 'invalid-token',
            'email'                 => $user->email,
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertUnprocessable();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reset_password_returns_422_when_passwords_do_not_match(): void
    {
        $user  = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'newpassword1',
            'password_confirmation' => 'different123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function login_username_is_case_insensitive(): void
    {
        User::factory()->create(['username' => self::USERNAME]);

        $this->postJson('/api/auth/login', [
            'username'    => 'TestUser',
            'password'    => self::PASSWORD,
            'device_name' => 'test',
        ])->assertOk();
    }

    // =========================================================================
    // PUT /api/auth/profile
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function profile_update_changes_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', ['email' => 'nuevo@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'nuevo@example.com');

        $this->assertEquals('nuevo@example.com', $user->fresh()->email);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function profile_update_changes_password_with_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', [
                'password'              => 'nuevapass123',
                'password_confirmation' => 'nuevapass123',
                'current_password'      => self::PASSWORD,
            ])
            ->assertOk();

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('nuevapass123', $user->fresh()->password)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function profile_update_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', [
                'password'              => 'nuevapass123',
                'password_confirmation' => 'nuevapass123',
                'current_password'      => 'incorrecta',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function profile_update_requires_current_password_to_change_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', [
                'password'              => 'nuevapass123',
                'password_confirmation' => 'nuevapass123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function profile_password_change_revokes_other_tokens_but_keeps_current(): void
    {
        $user    = User::factory()->create();
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('otro-dispositivo');

        $this->withToken($current)
            ->putJson('/api/auth/profile', [
                'password'              => 'nuevapass123',
                'password_confirmation' => 'nuevapass123',
                'current_password'      => self::PASSWORD,
            ])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertEquals('current', $user->tokens()->first()->name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function profile_update_cannot_change_username_name_or_role(): void
    {
        $user = User::factory()->create(['username' => 'original', 'name' => 'Original']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', [
                'email'    => 'nuevo@example.com',
                'username' => 'hacker',
                'name'     => 'Hacker',
                'role'     => 'admin',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertEquals('original', $fresh->username);
        $this->assertEquals('Original', $fresh->name);
        $this->assertEquals(\App\Enums\UserRole::Member, $fresh->role);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function profile_update_rejects_duplicate_email(): void
    {
        $other = User::factory()->create(['email' => 'tomado@example.com']);
        $user  = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', ['email' => 'tomado@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function profile_update_requires_authentication(): void
    {
        $this->putJson('/api/auth/profile', ['email' => 'x@example.com'])->assertUnauthorized();
    }
}
