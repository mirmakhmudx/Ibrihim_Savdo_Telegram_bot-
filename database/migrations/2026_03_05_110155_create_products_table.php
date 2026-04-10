<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->decimal('price', 12, 2);
            $table->string('image')->nullable()->comment('Telegram file_id');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('category')->nullable();

            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('products');



    }
};
