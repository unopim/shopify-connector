<?php

namespace Webkul\Shopify\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopifySettingConfigurationValuesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('shopify_setting_configuration_values')->insert([
            [
                'mapping'    => null,
                'extras'     => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'mapping'    => null,
                'extras'     => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
