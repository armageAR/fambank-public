<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function payload(string $endpoint = self::ENDPOINT, string $auth = 'auth-key-1'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => 'p256dh-key', 'auth' => $auth],
        ];
    }

    // =========================================================================
    // POST /api/push-subscriptions
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_creates_subscription(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/push-subscriptions', $this->payload())
            ->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id'  => $this->user->id,
            'endpoint' => self::ENDPOINT,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resubscribing_same_endpoint_updates_instead_of_duplicating(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/push-subscriptions', $this->payload(auth: 'auth-key-1'))
            ->assertOk();

        // Mismo endpoint, claves nuevas (re-suscripción del browser)
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/push-subscriptions', $this->payload(auth: 'auth-key-2'))
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertEquals('auth-key-2', PushSubscription::first()->auth);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_requires_authentication(): void
    {
        $this->postJson('/api/push-subscriptions', $this->payload())
            ->assertUnauthorized();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_validates_required_fields(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/push-subscriptions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    // =========================================================================
    // DELETE /api/push-subscriptions
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function destroy_deletes_own_subscription_by_endpoint(): void
    {
        PushSubscription::create([
            'user_id'  => $this->user->id,
            'endpoint' => self::ENDPOINT,
            'p256dh'   => 'p256dh-key',
            'auth'     => 'auth-key-1',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/push-subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    // =========================================================================
    // POST /api/push-subscriptions/test
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_endpoint_returns_ok_when_notification_is_delivered(): void
    {
        $this->mock(PushNotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyUser')
                ->once()
                ->andReturn(['sent' => 1, 'failed' => 0, 'errors' => []]);
        });

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/push-subscriptions/test')
            ->assertOk()
            ->assertJsonPath('data.sent', 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_endpoint_returns_422_when_user_has_no_subscriptions(): void
    {
        $this->mock(PushNotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyUser')
                ->once()
                ->andReturn(['sent' => 0, 'failed' => 0, 'errors' => []]);
        });

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/push-subscriptions/test')
            ->assertStatus(422);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_endpoint_returns_422_with_reason_when_delivery_fails(): void
    {
        $this->mock(PushNotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyUser')
                ->once()
                ->andReturn(['sent' => 0, 'failed' => 1, 'errors' => ['403 VapidPkHashMismatch']]);
        });

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/push-subscriptions/test')
            ->assertStatus(422)
            ->assertJsonFragment(['data' => ['sent' => 0, 'failed' => 1, 'errors' => ['403 VapidPkHashMismatch']]]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_endpoint_returns_503_when_vapid_is_not_configured(): void
    {
        // Sin claves VAPID el constructor del servicio falla
        config(['app.vapid_public_key' => null, 'app.vapid_private_key' => null]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/push-subscriptions/test')
            ->assertStatus(503);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/push-subscriptions/test')->assertUnauthorized();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function destroy_does_not_delete_other_users_subscription(): void
    {
        $other = User::factory()->create();
        PushSubscription::create([
            'user_id'  => $other->id,
            'endpoint' => self::ENDPOINT,
            'p256dh'   => 'p256dh-key',
            'auth'     => 'auth-key-1',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/push-subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
    }
}
