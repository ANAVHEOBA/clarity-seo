<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_sentiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->enum('sentiment', ['positive', 'negative', 'neutral', 'mixed']);
            $table->decimal('sentiment_score', 4, 3); // 0.000 to 1.000
            $table->json('emotions')->nullable(); // {"happy": 0.8, "satisfied": 0.9}
            $table->json('topics')->nullable(); // [{"topic": "service", "sentiment": "positive", "score": 0.9}]
            $table->json('keywords')->nullable(); // ["friendly", "helpful", "quick"]
            $table->string('language', 10)->nullable(); // Detected language code
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique('review_id');
            $table->index('sentiment');
            $table->index('sentiment_score');
            $table->index('analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_sentiments');
    }
};
