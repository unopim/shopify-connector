<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wk_shopify_metaobject_entry_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entry_id');
            $table->string('api_url');
            $table->string('gid');
            $table->timestamps();

            $table->unique(['entry_id', 'api_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wk_shopify_metaobject_entry_mappings');
    }
};
