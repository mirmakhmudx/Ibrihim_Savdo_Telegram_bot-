<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value'];
    protected $table    = 'site_settings';

    private static function ensure(): bool
    {
        try {
            if (Schema::hasTable('site_settings')) return true;
            DB::statement("CREATE TABLE IF NOT EXISTS site_settings (
                id integer primary key autoincrement,
                `key` varchar(255) not null,
                `value` text,
                created_at timestamp null,
                updated_at timestamp null
            )");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ste_key ON site_settings(`key`)");
            return true;
        } catch (\Exception $e) { return false; }
    }

    public static function get(string $key, string $default = ''): string
    {
        try {
            if (!self::ensure()) return $default;
            return (string)(DB::table('site_settings')->where('key', $key)->value('value') ?? $default);
        } catch (\Exception $e) { return $default; }
    }

    public static function set(string $key, string $value): void
    {
        try {
            if (!self::ensure()) return;
            DB::table('site_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()]
            );
        } catch (\Exception $e) {}
    }

    public static function all_settings(): array
    {
        try {
            if (!self::ensure()) return [];
            return DB::table('site_settings')->pluck('value', 'key')->toArray();
        } catch (\Exception $e) { return []; }
    }

    // Eloquent pluck override - ShopController uchun
    public static function pluck($column, $key = null)
    {
        try {
            if (!self::ensure()) return collect([]);
            return DB::table('site_settings')->pluck($column, $key);
        } catch (\Exception $e) { return collect([]); }
    }
}
