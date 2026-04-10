<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
    ];

    protected $casts = [
        'price'    => 'float',
        'quantity' => 'integer',
    ];

    // Tegishli buyurtma
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // Tegishli mahsulot
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Ushbu qator uchun jami narx
    public function getSubtotalAttribute(): float
    {
        return $this->quantity * $this->price;
    }
}
