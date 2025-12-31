<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type'); // reviews, sentiment, summary, trends, location_comparison, reviews_detailed
            $table->string('format')->default('pdf'); // pdf, excel, csv
            $table->json('sections')->nullable(); // which sections to include
            $table->json('branding')->nullable(); // logo_url, primary_color, secondary_color, company_name, footer_text
            $table->json('filters')->nullable(); // default filters
            $table->json('options')->nullable(); // other template options
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
