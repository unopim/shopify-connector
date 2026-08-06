<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wk_shopify_metaobject_definitions', function (Blueprint $table) {
            $table->json('options')->nullable()->after('fields');
        });
    }

    public function down(): void
    {
        Schema::table('wk_shopify_metaobject_definitions', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
