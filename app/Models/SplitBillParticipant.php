<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SplitBillParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'split_bill_id',
        'name',
        'phone_number',
        'is_creator',
        'amount_owed',
        'amount_paid',
        'status',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'is_creator'  => 'boolean',
        'amount_owed' => 'float',
        'amount_paid' => 'float',
        'paid_at'     => 'datetime',
    ];

    public function splitBill(): BelongsTo
    {
        return $this->belongsTo(SplitBill::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(SplitBillItem::class, 'split_bill_item_participants')
            ->withPivot('split_fraction')
            ->withTimestamps();
    }
}
