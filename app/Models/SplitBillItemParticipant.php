<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SplitBillItemParticipant extends Model
{
    use HasFactory;

    protected $table = 'split_bill_item_participants';

    protected $fillable = [
        'split_bill_item_id',
        'split_bill_participant_id',
        'split_fraction',
    ];

    protected $casts = [
        'split_fraction' => 'float',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(SplitBillItem::class, 'split_bill_item_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(SplitBillParticipant::class, 'split_bill_participant_id');
    }
}
