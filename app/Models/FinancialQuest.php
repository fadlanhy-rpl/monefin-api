<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialQuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'title',
        'description',
        'type',
        'target_type',
        'target_count',
        'xp_reward',
        'is_active',
    ];

    protected $casts = [
        'target_count' => 'integer',
        'xp_reward'    => 'integer',
        'is_active'    => 'boolean',
    ];

    public function userQuests(): HasMany
    {
        return $this->hasMany(UserQuest::class, 'quest_id');
    }
}
