<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserGamification extends Model
{
    use HasFactory;

    protected $table = 'user_gamification';

    protected $fillable = [
        'user_id',
        'xp',
        'level',
        'current_streak',
        'longest_streak',
        'last_activity_date',
        'streak_freezes_available',
        'streak_freeze_used_at',
    ];

    protected $casts = [
        'xp'                       => 'integer',
        'level'                    => 'integer',
        'current_streak'           => 'integer',
        'longest_streak'           => 'integer',
        'last_activity_date'       => 'date',
        'streak_freezes_available' => 'integer',
        'streak_freeze_used_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
