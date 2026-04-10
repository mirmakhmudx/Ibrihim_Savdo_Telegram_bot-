<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'status',
        'payment_method',
        'total',
        'screenshot',
        'address',
        'location_lat',
        'location_lng',
    ];

    protected $casts = [
        'total' => 'float',
    ];

    // Buyurtma statuslari
    const STATUS_PENDING         = 'pending';          // Yangi buyurtma (naqd)
    const STATUS_PENDING_PAYMENT = 'pending_payment';  // To'lov kutilmoqda (karta)
    const STATUS_DELIVERED       = 'delivered';        // Yetkazildi
    const STATUS_CANCELLED       = 'cancelled';        // Bekor qilindi

    // To'lov usullari
    const PAYMENT_CASH = 'cash';
    const PAYMENT_CARD = 'card';

    // Buyurtma egasi (user)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Buyurtma tarkibidagi mahsulotlar
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Status matnini chiroyli ko'rinishda qaytaradi
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING         => '⏳ Kutilmoqda',
            self::STATUS_PENDING_PAYMENT => '💳 To\'lov kutilmoqda',
            self::STATUS_DELIVERED       => '✅ Yetkazildi',
            self::STATUS_CANCELLED       => '❌ Bekor qilindi',
            default                      => $this->status,
        };
    }

    // To'lov usuli matnini qaytaradi
    public function getPaymentLabelAttribute(): string
    {
        return match ($this->payment_method) {
            self::PAYMENT_CASH => '💵 Naqd',
            self::PAYMENT_CARD => '💳 Karta',
            default            => $this->payment_method,
        };
    }
}
