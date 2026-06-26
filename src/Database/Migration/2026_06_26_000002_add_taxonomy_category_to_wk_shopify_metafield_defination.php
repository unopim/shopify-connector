<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wk_shopify_metafield_defination', function (Blueprint $table) {
            $table->string('taxonomy_category', 255)->nullable()->after('apiUrl');
        });
    }

    public function down(): void
    {
        Schema::table('wk_shopify_metafield_defination', function (Blueprint $table) {
            $table->dropColumn('taxonomy_category');
        });
    }
};
