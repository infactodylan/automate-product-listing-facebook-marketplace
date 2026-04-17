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
        Schema::create('listing_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('storage_key')->unique();
            $table->string('delivery_token_hash', 64)->unique();
            $table->string('listing_page_url');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->json('discovered_urls')->nullable();
            $table->longText('scraped_products')->nullable();
            $table->string('zip_relative_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_exports');
    }
};
