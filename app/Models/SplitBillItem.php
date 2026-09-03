<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SplitBillItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'split_bill_id',
        'name',
        'price',
        'quantity',
        'subtotal',
    ];

    protected $casts = [
        'price'    => 'float',
        'quantity' => 'integer',
        'subtotal' => 'float',
    ];

    public function splitBill(): BelongsTo
    {
        return $this->belongsTo(SplitBill::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(SplitBillParticipant::class, 'split_bill_item_participants')
            ->withPivot('split_fraction')
            ->withTimestamps();
    }
}
