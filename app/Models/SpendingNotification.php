<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpendingNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'period_type',
        'period_label',
        'spent_percent',
        'message',
        'is_read',
    ];

    protected $casts = [
        'spent_percent' => 'decimal:2',
        'is_read' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
