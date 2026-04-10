<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'title',
        'price',
        'image',
        'description',
        'is_active',
        'category'
    ];

    protected $casts = [
        'price'     => 'float',
        'is_active' => 'boolean',
    ];

    // Savatchadagi yozuvlar
    public function cartItems(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    // Buyurtma tarkibidagi yozuvlar
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Sevimlilarga qo'shganlar
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }
}
