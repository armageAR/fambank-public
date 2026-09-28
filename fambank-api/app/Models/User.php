<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\PushSubscription;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int                 $id
 * @property string              $name
 * @property string              $username
 * @property string              $email
 * @property UserRole            $role
 * @property string|null         $balance_usd
 * @property bool                $active
 * @property \Carbon\Carbon|null $last_login_at
 * @property string              $formatted_balance   Accessor: saldo formateado "USD 1,234.56"
 * @property bool                $is_admin            Accessor
 * @property bool                $is_member           Accessor
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'balance_usd',
        'active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'balance_usd'       => 'decimal:2',
            'role'              => UserRole::class,
            'active'            => 'boolean',
            'last_login_at'     => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /** Transacciones propias del miembro. */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Transacciones que este admin confirmó o rechazó. */
    public function reviewedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'confirmed_by');
    }

    /** Archivos adjuntos que subió este usuario. */
    public function uploadedAttachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'uploaded_by');
    }

    /** Suscripciones push del usuario. */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** Solo administradores. */
    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', UserRole::Admin);
    }

    /** Solo miembros (hijos). */
    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('role', UserRole::Member);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /** "USD 1,234.56" — listo para mostrar en UI. */
    protected function formattedBalance(): Attribute
    {
        return Attribute::make(
            get: fn () => 'USD ' . number_format((float) ($this->balance_usd ?? 0), 2),
        );
    }

    protected function isAdmin(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->role === UserRole::Admin,
        );
    }

    protected function isMember(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->role === UserRole::Member,
        );
    }
}
