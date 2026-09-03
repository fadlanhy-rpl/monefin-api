<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SplitBill extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'bill_date',
        'account_id',
        'category_id',
        'subtotal',
        'tax_percent',
        'tax_amount',
        'service_percent',
        'service_amount',
        'discount_amount',
        'total_amount',
        'split_mode',
        'rounding_mode',
        'payment_info',
        'receipt_image_path',
        'status',
        'my_transaction_id',
    ];

    protected $casts = [
        'bill_date'       => 'date',
        'subtotal'        => 'float',
        'tax_percent'     => 'float',
        'tax_amount'      => 'float',
        'service_percent' => 'float',
        'service_amount'  => 'float',
        'discount_amount' => 'float',
        'total_amount'    => 'float',
        'payment_info'    => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'my_transaction_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SplitBillParticipant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SplitBillItem::class);
    }
}
