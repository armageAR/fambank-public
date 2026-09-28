<?php

namespace Tests\Feature\Admin;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = User::factory()->admin()->create();
        $this->member = User::factory()->create(['balance_usd' => 100]);
    }

    /** Depósito pendiente: el saldo todavía no fue acreditado. */
    private function pendingDeposit(float $ars = 120000, float $rate = 1200): Transaction
    {
        return Transaction::create([
            'user_id'       => $this->member->id,
            'created_by'    => $this->member->id,
            'type'          => TransactionType::Deposit,
            'status'        => TransactionStatus::Pending,
            'amount_ars'    => $ars,
            'amount_usd'    => round($ars / $rate, 2),
            'exchange_rate' => $rate,
        ]);
    }

    /** Retiro pendiente: el saldo ya fue descontado como reserva al crearse. */
    private function pendingWithdrawal(float $ars = 59000, float $rate = 1180): Transaction
    {
        $usd = round($ars / $rate, 2);
        $this->member->decrement('balance_usd', $usd);

        return Transaction::create([
            'user_id'       => $this->member->id,
            'created_by'    => $this->member->id,
            'type'          => TransactionType::Withdrawal,
            'status'        => TransactionStatus::Pending,
            'amount_ars'    => $ars,
            'amount_usd'    => $usd,
            'exchange_rate' => $rate,
        ]);
    }

    // =========================================================================
    // GET /api/admin/transactions
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_lists_only_pending_transactions(): void
    {
        $this->pendingDeposit();
        Transaction::create([
            'user_id'       => $this->member->id,
            'created_by'    => $this->member->id,
            'type'          => TransactionType::Deposit,
            'status'        => TransactionStatus::Confirmed,
            'amount_ars'    => 1000,
            'amount_usd'    => 1,
            'exchange_rate' => 1000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_is_forbidden_for_members(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/admin/transactions')
            ->assertForbidden();
    }

    // =========================================================================
    // PUT /api/admin/transactions/{transaction}/confirm
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function confirm_deposit_credits_balance(): void
    {
        $tx = $this->pendingDeposit(); // 100 USD

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals('200.00', $this->member->fresh()->balance_usd);
        $this->assertEquals($this->admin->id, $tx->fresh()->confirmed_by);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function confirm_deposit_with_new_rate_recalculates_usd(): void
    {
        $tx = $this->pendingDeposit(120000, 1200); // 100 USD al TC original

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm", ['exchange_rate' => 1000])
            ->assertOk()
            ->assertJsonPath('data.amount_usd', '120.00');

        // Se acreditan los 120 USD recalculados, no los 100 originales
        $this->assertEquals('220.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function confirm_withdrawal_without_rate_change_keeps_balance(): void
    {
        $tx = $this->pendingWithdrawal(); // reservó 50 USD → saldo 50

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm")
            ->assertOk();

        // El saldo ya había sido descontado al crear: no debe moverse de nuevo
        $this->assertEquals('50.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function confirm_withdrawal_with_higher_rate_refunds_difference(): void
    {
        $tx = $this->pendingWithdrawal(59000, 1180); // reservó 50 USD → saldo 50

        // TC sube a 1475: 59000 / 1475 = 40 USD → refund de 10
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm", ['exchange_rate' => 1475])
            ->assertOk()
            ->assertJsonPath('data.amount_usd', '40.00');

        $this->assertEquals('60.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function confirm_withdrawal_with_lower_rate_debits_difference(): void
    {
        $tx = $this->pendingWithdrawal(59000, 1180); // reservó 50 USD → saldo 50

        // TC baja a 1000: 59000 / 1000 = 59 USD → descuento adicional de 9
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm", ['exchange_rate' => 1000])
            ->assertOk()
            ->assertJsonPath('data.amount_usd', '59.00');

        $this->assertEquals('41.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function confirm_non_pending_transaction_is_rejected(): void
    {
        $tx = $this->pendingDeposit();
        $tx->update(['status' => TransactionStatus::Rejected]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm")
            ->assertStatus(422);

        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function double_confirm_credits_only_once(): void
    {
        $tx = $this->pendingDeposit(); // 100 USD

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm")
            ->assertStatus(422);

        $this->assertEquals('200.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function confirm_is_forbidden_for_members(): void
    {
        $tx = $this->pendingDeposit();

        $this->actingAs($this->member, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/confirm")
            ->assertForbidden();
    }

    // =========================================================================
    // PUT /api/admin/transactions/{transaction}/reject
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function reject_withdrawal_restores_balance(): void
    {
        $tx = $this->pendingWithdrawal(); // reservó 50 USD → saldo 50

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reject_deposit_does_not_change_balance(): void
    {
        $tx = $this->pendingDeposit();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/reject")
            ->assertOk();

        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function double_reject_refunds_only_once(): void
    {
        $tx = $this->pendingWithdrawal(); // reservó 50 USD → saldo 50

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/reject")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/reject")
            ->assertStatus(422);

        $this->assertEquals('100.00', $this->member->fresh()->balance_usd);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reject_is_forbidden_for_members(): void
    {
        $tx = $this->pendingWithdrawal();

        $this->actingAs($this->member, 'sanctum')
            ->putJson("/api/admin/transactions/{$tx->id}/reject")
            ->assertForbidden();
    }
}
