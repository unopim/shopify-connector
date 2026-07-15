<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wk_shopify_metaobject_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('api_url');
            $table->string('type');
            $table->string('gid');
            $table->string('name');
            $table->json('fields')->nullable();
            $table->timestamps();

            $table->unique(['api_url', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wk_shopify_metaobject_mappings');
    }
};
