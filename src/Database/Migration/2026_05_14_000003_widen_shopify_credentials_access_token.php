<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SaaS-installed credentials store a Symfony LexikJWT (~523 chars) in
     * `accessToken`. The original varchar(255) silently truncates them on
     * MySQL, which breaks signature verification on every call. Widen to
     * TEXT so any token length is preserved.
     */
    public function up(): void
    {
        Schema::table('wk_shopify_credentials_config', function (Blueprint $table) {
            $table->text('accessToken')->change();
        });
    }

    public function down(): void
    {
        Schema::table('wk_shopify_credentials_config', function (Blueprint $table) {
            $table->string('accessToken')->change();
        });
    }
};
