<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wk_shopify_metaobject_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('code');
            $table->json('values')->nullable();
            $table->timestamps();

            $table->unique(['type', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wk_shopify_metaobject_entries');
    }
};
