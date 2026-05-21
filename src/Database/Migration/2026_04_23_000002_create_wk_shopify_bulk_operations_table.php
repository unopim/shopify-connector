<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wk_shopify_bulk_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_track_id');
            $table->unsignedBigInteger('job_track_batch_id')->nullable();
            $table->unsignedBigInteger('credential_id');
            $table->string('phase', 64);
            $table->string('status', 64)->index();
            $table->string('shopify_bulk_operation_id')->nullable()->index();
            $table->string('shopify_status', 64)->nullable()->index();
            $table->string('error_code', 128)->nullable();
            $table->text('staged_upload_path')->nullable();
            $table->text('input_file_path')->nullable();
            $table->text('result_file_path')->nullable();
            $table->text('result_url')->nullable();
            $table->text('partial_data_url')->nullable();
            $table->unsignedBigInteger('object_count')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['job_track_id', 'phase']);
            $table->index(['credential_id', 'phase']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wk_shopify_bulk_operations');
    }
};
