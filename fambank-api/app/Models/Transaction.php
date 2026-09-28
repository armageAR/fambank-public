<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int                    $id
 * @property int                    $user_id
 * @property TransactionType        $type
 * @property TransactionStatus      $status
 * @property string                 $amount_ars
 * @property string|null            $amount_usd
 * @property string|null            $exchange_rate
 * @property \Carbon\Carbon|null    $confirmed_at
 * @property int|null               $confirmed_by
 * @property string|null            $notes
 * @property string                 $formatted_amount_ars   Accessor: "ARS 12.500,00"
 * @property string                 $formatted_amount_usd   Accessor: "USD 10,00" o "—"
 */
class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'created_by',
        'type',
        'status',
        'amount_ars',
        'amount_usd',
        'exchange_rate',
        'confirmed_at',
        'confirmed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type'          => TransactionType::class,
            'status'        => TransactionStatus::class,
            'amount_ars'    => 'decimal:2',
            'amount_usd'    => 'decimal:2',
            'exchange_rate' => 'decimal:2',
            'confirmed_at'  => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /** El miembro dueño de la transacción. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quién creó la transacción (member o admin). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** El admin que confirmó o rechazó. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Comprobantes adjuntos. */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Pending);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Confirmed);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Rejected);
    }

    public function scopeDeposits(Builder $query): Builder
    {
        return $query->where('type', TransactionType::Deposit);
    }

    public function scopeWithdrawals(Builder $query): Builder
    {
        return $query->where('type', TransactionType::Withdrawal);
    }

    /** Transacciones de un miembro específico. */
    public function scopeByMember(Builder $query, int|User $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    /** Transacciones de los últimos N días. */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /** "ARS 12.500,00" */
    protected function formattedAmountArs(): Attribute
    {
        return Attribute::make(
            get: fn () => 'ARS ' . number_format((float) $this->amount_ars, 2, ',', '.'),
        );
    }

    /** "USD 10,00" si ya fue confirmada, "—" si está pendiente. */
    protected function formattedAmountUsd(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->amount_usd !== null
                ? 'USD ' . number_format((float) $this->amount_usd, 2)
                : '—',
        );
    }
}
