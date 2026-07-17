<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Offer extends Model
{
    protected $fillable = [
        'product_id',
        'store_id',
        'price',
        'old_price',
        'unit_price',
        'stock',
        'in_stock',
        'last_seen_at',
    ];

    protected $casts = [
        'in_stock' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function discount(): HasOne
    {
        return $this->hasOne(Discount::class);
    }
}
