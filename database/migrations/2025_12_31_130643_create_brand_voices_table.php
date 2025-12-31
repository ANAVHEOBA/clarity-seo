<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_voices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('tone', ['professional', 'friendly', 'apologetic', 'empathetic'])->default('professional');
            $table->text('guidelines');
            $table->json('example_responses')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_voices');
    }
};
