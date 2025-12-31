<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // google, yelp, facebook, etc.
            $table->string('external_id')->nullable(); // ID from the platform
            $table->string('author_name')->nullable();
            $table->string('author_image')->nullable();
            $table->unsignedTinyInteger('rating'); // 1-5
            $table->text('content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable(); // Extra platform-specific data
            $table->timestamps();

            $table->unique(['location_id', 'platform', 'external_id']);
            $table->index(['location_id', 'platform']);
            $table->index(['location_id', 'rating']);
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
