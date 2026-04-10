<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('phone');
            $table->enum('status', ['pending', 'pending_payment', 'accepted', 'delivered', 'cancelled'])->default('pending');
            $table->enum('payment_method', ['cash', 'card'])->default('cash');
            $table->decimal('total', 12, 2)->default(0);
            $table->string('screenshot')->nullable()->comment('Telegram file_id for card payment proof');
            $table->string('address')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('orders');

    }
};
