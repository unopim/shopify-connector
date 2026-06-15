<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wk_shopify_category_taxonomy_mapping', function (Blueprint $table) {
            $table->id();
            // categories.id is unsigned int (legacy schema)
            $table->unsignedInteger('unopim_category_id')->unique();
            $table->foreign('unopim_category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('taxonomy_id');   // Shopify GID: gid://shopify/TaxonomyCategory/...
            $table->string('taxonomy_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wk_shopify_category_taxonomy_mapping');
    }
};
