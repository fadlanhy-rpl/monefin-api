<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserQuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quest_id',
        'period_key',
        'current_count',
        'is_completed',
        'is_claimed',
        'completed_at',
        'claimed_at',
    ];

    protected $casts = [
        'current_count' => 'integer',
        'is_completed'  => 'boolean',
        'is_claimed'    => 'boolean',
        'completed_at'  => 'datetime',
        'claimed_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quest(): BelongsTo
    {
        return $this->belongsTo(FinancialQuest::class, 'quest_id');
    }
}
