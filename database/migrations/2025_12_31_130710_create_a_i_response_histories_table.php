<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_response_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->enum('tone', ['professional', 'friendly', 'apologetic', 'empathetic'])->default('professional');
            $table->string('language', 10)->default('en');
            $table->foreignId('brand_voice_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_response_histories');
    }
};
