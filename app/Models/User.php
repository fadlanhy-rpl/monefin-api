<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'photo',
        'google_id',
        'provider',
        'email_verified_at',
        'phone',
        'occupation',
        'bio',
        'preferences',
        'two_factor_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Append computed attribute
    protected $appends = ['has_password'];

    /**
     * Apakah user sudah memiliki password (false untuk user Google yang belum set password).
     */
    public function getHasPasswordAttribute(): bool
    {
        return !is_null($this->password);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at'   => 'datetime',
            'password'            => 'hashed',
            'preferences'         => 'array',
            'two_factor_enabled'  => 'boolean',
        ];
    }

    // ─── Relationships ──────────────────────────────────────

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function incomeSettings(): HasMany
    {
        return $this->hasMany(IncomeSetting::class);
    }

    public function activeIncomeSetting(): HasOne
    {
        return $this->hasOne(IncomeSetting::class)->where('is_active', true)->latestOfMany();
    }

    public function spendingThreshold(): HasOne
    {
        return $this->hasOne(SpendingThreshold::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SpendingNotification::class);
    }
}
