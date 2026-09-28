<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\NotifyAdminsOfPendingTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BLUE_BUY  = 1180.0;
    private const BLUE_SELL = 1200.0;

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = User::factory()->admin()->create();
        $this->member = User::factory()->create(['balance_usd' => 100]);
    }

    /** Fakea la respuesta de Bluelytics con la cotización blue. */
    private function fakeBlueRate(float $buy = self::BLUE_BUY, float $sell = self::BLUE_SELL): void
    {
        Http::fake([
            'api.bluelytics.com.ar/*' => Http::response([
                'blue'        => ['value_buy' => $buy, 'value_sell' => $sell],
                'last_update' => now()->toIso8601String(),
            ]),
        ]);
    }

    private function fakeBlueDown(): void
    {
        Http::fake([
            'api.bluelytics.com.ar/*' => Http::response(null, 500),
        ]);
    }

    // =========================================================================
    // GET /api/transactions
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_returns_only_own_transactions(): void
    {
        Transaction::create([
            'user_id'    => $this->member->id,
            'created_by' => $this->member->id,
            'type'       => TransactionType::Deposit,
            'status'     => TransactionStatus::Pending,
            'amount_ars' => 1000,
            'amount_usd' => 1,
            'exchange_rate' => 1000,
        ]);

        $other = User::factory()->create();
        Transaction::create([
            'user_id'    => $other->id,
            'created_by' => $other->id,
            'type'       => TransactionType::Deposit,
            'status'     => TransactionStatus::Pending,
            'amount_ars' => 5000,
            'amount_usd' => 5,
            'exchange_rate' => 1000,
        ]);

        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('user.id', $this->member->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_requires_authentication(): void
    {
        $this->getJson('/api/transactions')->assertUnauthorized();
    }

    // =========================================================================
    // POST /api/transactions — member
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function member_deposit_is_pending_and_uses_server_sell_rate(): void
    {
        $this->fakeBlueRate();

        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/transactions', [
                'type'       => 'deposit',
                'amount_ars' => 120000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.exchange_rate', '1200.00')
            ->assertJsonPath('data.amount_usd', '100.00');

        // El saldo no cambia hasta que el admin confirme el depósito
        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function member_withdrawal_uses_buy_rate_and_reserves_balance(): void
    {
        $this->fakeBlueRate();

        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/transactions', [
                'type'       => 'withdrawal',
                'amount_ars' => 59000, // 59000 / 1180 = 50 USD
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.exchange_rate', '1180.00')
            ->assertJsonPath('data.amount_usd', '50.00');

        // El retiro descuenta el saldo inmediatamente como reserva
        $this->assertEquals('50.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function member_cannot_set_their_own_exchange_rate(): void
    {
        $this->fakeBlueRate();

        // Intenta retirar con TC inflado para que se le descuenten menos dólares
        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/transactions', [
                'type'          => 'withdrawal',
                'amount_ars'    => 59000,
                'exchange_rate' => 1000000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate', '1180.00')
            ->assertJsonPath('data.amount_usd', '50.00');

        $this->assertEquals('50.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function member_withdrawal_with_insufficient_balance_is_rejected(): void
    {
        $this->fakeBlueRate();

        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/transactions', [
                'type'       => 'withdrawal',
                'amount_ars' => 118000 + 1180, // 101 USD > 100 de saldo
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function member_transaction_fails_with_503_when_rate_api_is_down(): void
    {
        $this->fakeBlueDown();

        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/transactions', [
                'type'       => 'deposit',
                'amount_ars' => 120000,
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'No se pudo obtener la cotización del dólar en este momento. Volvé a intentarlo en unos minutos.');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_validates_required_fields(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/transactions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'amount_ars']);
    }

    // =========================================================================
    // POST /api/transactions — admin
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_deposit_is_confirmed_and_credits_balance_immediately(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/transactions', [
                'type'          => 'deposit',
                'amount_ars'    => 125000,
                'exchange_rate' => 1250,
                'user_id'       => $this->member->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.amount_usd', '100.00');

        $this->assertEquals('200.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_withdrawal_is_confirmed_and_debits_balance_immediately(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/transactions', [
                'type'          => 'withdrawal',
                'amount_ars'    => 59000,
                'exchange_rate' => 1180,
                'user_id'       => $this->member->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals('50.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_withdrawal_with_insufficient_balance_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/transactions', [
                'type'          => 'withdrawal',
                'amount_ars'    => 200000,
                'exchange_rate' => 1000, // 200 USD > 100 de saldo
                'user_id'       => $this->member->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_must_provide_user_id_and_exchange_rate(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/transactions', [
                'type'       => 'deposit',
                'amount_ars' => 120000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'exchange_rate']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_create_transaction_for_inactive_user(): void
    {
        $inactive = User::factory()->inactive()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/transactions', [
                'type'          => 'deposit',
                'amount_ars'    => 120000,
                'exchange_rate' => 1200,
                'user_id'       => $inactive->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseCount('transactions', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_cannot_create_transaction_for_another_admin(): void
    {
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/transactions', [
                'type'          => 'deposit',
                'amount_ars'    => 120000,
                'exchange_rate' => 1200,
                'user_id'       => $otherAdmin->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseCount('transactions', 0);
    }

    // =========================================================================
    // Notificación push al admin (en queue)
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function member_transaction_queues_admin_notification(): void
    {
        Queue::fake();
        $this->fakeBlueRate();

        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/transactions', [
                'type'       => 'deposit',
                'amount_ars' => 120000,
            ])
            ->assertCreated();

        Queue::assertPushed(NotifyAdminsOfPendingTransaction::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_transaction_does_not_queue_notification(): void
    {
        Queue::fake();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/transactions', [
                'type'          => 'deposit',
                'amount_ars'    => 120000,
                'exchange_rate' => 1200,
                'user_id'       => $this->member->id,
            ])
            ->assertCreated();

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // DELETE /api/transactions/{transaction}
    // =========================================================================

    private function createPendingWithdrawal(User $member, float $usd = 50, float $ars = 59000): Transaction
    {
        // Simula el estado post-creación: saldo ya descontado como reserva
        $member->decrement('balance_usd', $usd);

        return Transaction::create([
            'user_id'       => $member->id,
            'created_by'    => $member->id,
            'type'          => TransactionType::Withdrawal,
            'status'        => TransactionStatus::Pending,
            'amount_ars'    => $ars,
            'amount_usd'    => $usd,
            'exchange_rate' => $ars / $usd,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cancel_pending_withdrawal_restores_balance(): void
    {
        $tx = $this->createPendingWithdrawal($this->member);
        $this->assertEquals('50.00', $this->member->fresh()->balance_usd);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/transactions/{$tx->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cancelled_transactions_are_distinguishable_from_rejected(): void
    {
        $tx = $this->createPendingWithdrawal($this->member);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/transactions/{$tx->id}")
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Cancelada');

        // Cancelación del member: sin revisor, a diferencia del reject del admin
        $this->assertNull($tx->fresh()->confirmed_by);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cancel_pending_deposit_does_not_change_balance(): void
    {
        $tx = Transaction::create([
            'user_id'       => $this->member->id,
            'created_by'    => $this->member->id,
            'type'          => TransactionType::Deposit,
            'status'        => TransactionStatus::Pending,
            'amount_ars'    => 120000,
            'amount_usd'    => 100,
            'exchange_rate' => 1200,
        ]);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/transactions/{$tx->id}")
            ->assertOk();

        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cannot_cancel_someone_elses_transaction(): void
    {
        $other = User::factory()->create(['balance_usd' => 100]);
        $tx = $this->createPendingWithdrawal($other);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/transactions/{$tx->id}")
            ->assertForbidden();

        $this->assertEquals('50.00', $other->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cannot_cancel_a_confirmed_transaction(): void
    {
        $tx = Transaction::create([
            'user_id'       => $this->member->id,
            'created_by'    => $this->member->id,
            'type'          => TransactionType::Withdrawal,
            'status'        => TransactionStatus::Confirmed,
            'amount_ars'    => 59000,
            'amount_usd'    => 50,
            'exchange_rate' => 1180,
        ]);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/transactions/{$tx->id}")
            ->assertStatus(422);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function double_cancel_refunds_only_once(): void
    {
        $tx = $this->createPendingWithdrawal($this->member);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/transactions/{$tx->id}")
            ->assertOk();

        // Segundo intento: la transacción ya no está pendiente
        $this->actingAs($this->member, 'sanctum')
            ->deleteJson("/api/transactions/{$tx->id}")
            ->assertStatus(422);

        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }
}
