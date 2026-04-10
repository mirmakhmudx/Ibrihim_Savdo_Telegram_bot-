<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('site_settings')) {
            Schema::create('site_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
        $defaults = [
            'shop_name'       => 'Ibrohim Savdo',
            'shop_address'    => "Toshkent, O'zbekiston",
            'shop_phone'      => '+998 95 093 53 53',
            'shop_hours'      => '08:00–22:00',
            'shop_card'       => '9860170101175710',
            'shop_card_owner' => 'Ibrohim Sultonov',
            'shop_lat'        => '41.2995',
            'shop_lng'        => '69.2401',
            'shop_about'      => "Mahalliy oziq-ovqat va kundalik ehtiyoj mahsulotlarini yetkazib beramiz.",
        ];
        foreach ($defaults as $key => $value) {
            DB::table('site_settings')->insertOrIgnore(['key' => $key, 'value' => $value]);
        }
    }
    public function down(): void { Schema::dropIfExists('site_settings'); }
};
