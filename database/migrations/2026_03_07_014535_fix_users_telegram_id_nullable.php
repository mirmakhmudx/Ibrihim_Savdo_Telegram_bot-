<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // telegram_id = 0 bo'lgan userlarni NULL ga o'zgartirish
        DB::table('users')->where('telegram_id', 0)->update(['telegram_id' => null]);

        Schema::table('users', function (Blueprint $table) {
            // UNIQUE indexni olib tashlaymiz
            $table->dropUnique(['telegram_id']);
            // telegram_id ni nullable qilamiz
            $table->bigInteger('telegram_id')->nullable()->change();
            // Nullable unique index — NULL qiymatlar unique tekshirilmaydi
            $table->unique('telegram_id');
        });

        // phone uchun ham unique index (bir xil telefon bilan 2 user bo'lmasin)
        // Avval dublikatlarni tekshiramiz
        $dupes = DB::select("
            SELECT phone, COUNT(*) as cnt FROM users
            WHERE phone IS NOT NULL
            GROUP BY phone HAVING cnt > 1
        ");
        foreach ($dupes as $dupe) {
            // Telegram ID si bor userdan tashqarisini o'chirish
            DB::table('users')
                ->where('phone', $dupe->phone)
                ->whereNull('telegram_id')
                ->orderBy('id', 'desc')
                ->skip(1)->take(999)
                ->delete();
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('telegram_id')->default(0)->change();
        });
    }
};
