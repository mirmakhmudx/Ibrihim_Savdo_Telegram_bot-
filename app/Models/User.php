<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = [
        'telegram_id',
        'name',
        'username',
        'phone',
        'is_admin',
    ];

    protected $casts = [
        'is_admin'    => 'boolean',
        'telegram_id' => 'integer',
    ];

    // Foydalanuvchining savatchasi
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // Foydalanuvchining sevimli mahsulotlari
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }
}
