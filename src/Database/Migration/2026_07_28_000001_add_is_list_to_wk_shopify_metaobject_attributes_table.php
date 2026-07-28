<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wk_shopify_metaobject_attributes', function (Blueprint $table) {
            $table->boolean('is_list')->default(false)->after('definition_id');
        });
    }

    public function down(): void
    {
        Schema::table('wk_shopify_metaobject_attributes', function (Blueprint $table) {
            $table->dropColumn('is_list');
        });
    }
};
