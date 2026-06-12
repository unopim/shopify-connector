<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exists = DB::table('shopify_setting_configuration_values')->where('id', 4)->exists();

        if (! $exists) {
            DB::table('shopify_setting_configuration_values')->insert([
                'id' => 4,
                'mapping' => null,
                'extras' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('shopify_setting_configuration_values')->where('id', 4)->delete();
    }
};
