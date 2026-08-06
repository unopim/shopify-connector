<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wk_shopify_metaobject_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attribute_id')->unique();
            $table->unsignedBigInteger('definition_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wk_shopify_metaobject_attributes');
    }
};
