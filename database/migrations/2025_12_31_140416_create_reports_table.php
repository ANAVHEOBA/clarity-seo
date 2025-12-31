<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('type'); // reviews, sentiment, summary, trends, location_comparison, reviews_detailed
            $table->string('format'); // pdf, excel, csv
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->integer('progress')->default(0);
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('period')->nullable(); // last_7_days, last_30_days, last_quarter, year_to_date
            $table->json('location_ids')->nullable();
            $table->json('filters')->nullable();
            $table->json('branding')->nullable();
            $table->json('options')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
