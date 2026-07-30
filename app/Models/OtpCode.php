<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = ['email', 'code', 'type', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Periksa apakah OTP sudah kadaluarsa.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
