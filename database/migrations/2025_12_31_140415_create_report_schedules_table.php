<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type'); // reviews, sentiment, summary, trends, location_comparison, reviews_detailed
            $table->string('format')->default('pdf'); // pdf, excel, csv
            $table->string('frequency'); // daily, weekly, monthly
            $table->string('day_of_week')->nullable(); // monday, tuesday, etc. for weekly
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1-28 for monthly
            $table->time('time_of_day')->default('09:00:00');
            $table->string('timezone')->default('UTC');
            $table->string('period')->default('last_30_days'); // last_7_days, last_30_days, last_quarter, year_to_date
            $table->json('location_ids')->nullable();
            $table->json('filters')->nullable();
            $table->json('branding')->nullable();
            $table->json('options')->nullable();
            $table->json('recipients')->nullable(); // email addresses to send to
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
