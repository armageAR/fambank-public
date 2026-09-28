<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = User::factory()->admin()->create();
        $this->member = User::factory()->create();
    }

    // =========================================================================
    // GET /api/admin/users
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_returns_all_users_for_admin(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_is_forbidden_for_members(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_requires_authentication(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    // =========================================================================
    // POST /api/admin/users
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_creates_user_and_returns_201(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name'     => 'Nuevo Usuario',
                'username' => 'nuevousuario',
                'email'    => 'nuevo@fambank.com',
                'password' => 'securepass1',
                'role'     => UserRole::Member->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'nuevo@fambank.com')
            ->assertJsonPath('data.role', 'member');

        $this->assertDatabaseHas('users', ['email' => 'nuevo@fambank.com']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_validates_required_fields(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/users', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'username', 'email', 'password', 'role']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_rejects_duplicate_email(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name'     => 'Duplicado',
                'username' => 'duplicado',
                'email'    => $this->member->email,
                'password' => 'securepass1',
                'role'     => UserRole::Member->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_rejects_invalid_role(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name'     => 'Test',
                'username' => 'testrole',
                'email'    => 'test@example.com',
                'password' => 'securepass1',
                'role'     => 'superadmin',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_is_forbidden_for_members(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/admin/users', [])
            ->assertForbidden();
    }

    // =========================================================================
    // PUT /api/admin/users/{user}/password
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_password_changes_password_successfully(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}/password", [
                'password'              => 'nuevapass123',
                'password_confirmation' => 'nuevapass123',
            ])
            ->assertOk();

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('nuevapass123', $this->member->fresh()->password)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_password_revokes_all_tokens_of_target_user(): void
    {
        $this->member->createToken('device-1');
        $this->member->createToken('device-2');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}/password", [
                'password'              => 'nuevapass123',
                'password_confirmation' => 'nuevapass123',
            ])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_password_validates_confirmation(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}/password", [
                'password'              => 'nuevapass123',
                'password_confirmation' => 'diferente456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_password_is_forbidden_for_members(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->member, 'sanctum')
            ->putJson("/api/admin/users/{$other->id}/password", [
                'password'              => 'nuevapass123',
                'password_confirmation' => 'nuevapass123',
            ])
            ->assertForbidden();
    }

    // =========================================================================
    // PUT /api/admin/users/{user}/reset-password
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function send_reset_password_sends_notification(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}/reset-password")
            ->assertOk();

        Notification::assertSentTo(
            $this->member,
            \Illuminate\Auth\Notifications\ResetPassword::class
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function send_reset_password_is_forbidden_for_members(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->putJson("/api/admin/users/{$this->admin->id}/reset-password")
            ->assertForbidden();
    }

    // =========================================================================
    // DELETE /api/admin/users/{user}
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function destroy_deactivates_user_and_revokes_tokens(): void
    {
        $this->member->createToken('device-1');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$this->member->id}")
            ->assertOk();

        $this->assertFalse($this->member->fresh()->active);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function destroy_prevents_admin_from_deactivating_themselves(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$this->admin->id}")
            ->assertUnprocessable();

        $this->assertTrue($this->admin->fresh()->active);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function destroy_is_forbidden_for_members(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/admin/users/{$other->id}")
            ->assertForbidden();
    }

    // =========================================================================
    // PUT /api/admin/users/{user}
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_edits_all_user_data(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}", [
                'name'     => 'Nombre Editado',
                'username' => 'editado',
                'email'    => 'editado@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre Editado')
            ->assertJsonPath('data.username', 'editado')
            ->assertJsonPath('data.email', 'editado@example.com');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_normalizes_username_to_lowercase(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}", ['username' => 'MiUsuario'])
            ->assertOk()
            ->assertJsonPath('data.username', 'miusuario');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_rejects_duplicate_username(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}", ['username' => $this->admin->username])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_allows_keeping_own_username(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}", [
                'username' => $this->member->username,
                'name'     => 'Solo Cambio Nombre',
            ])
            ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_remove_their_own_admin_role(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->admin->id}", ['role' => 'member'])
            ->assertUnprocessable();

        $this->assertEquals(UserRole::Admin, $this->admin->fresh()->role);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_is_forbidden_for_members(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->putJson("/api/admin/users/{$this->member->id}", ['name' => 'X'])
            ->assertForbidden();
    }
}
