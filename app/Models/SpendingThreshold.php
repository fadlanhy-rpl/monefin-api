<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpendingThreshold extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'hemat_max_percent',
        'boros_min_percent',
    ];

    protected $casts = [
        'hemat_max_percent' => 'decimal:2',
        'boros_min_percent' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
