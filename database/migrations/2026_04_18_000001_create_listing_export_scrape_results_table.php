<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_export_scrape_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_export_id')->constrained('listing_exports')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('source_url', 2048);
            $table->json('product')->nullable();
            $table->timestamps();

            $table->unique(['listing_export_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_export_scrape_results');
    }
};
