<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            
            // Workflow metadata
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // Higher number = higher priority
            
            // Trigger configuration
            $table->string('trigger_type'); // review_received, negative_review, listing_discrepancy, scheduled, manual
            $table->json('trigger_config'); // Platform, rating thresholds, schedule, etc.
            
            // Conditions (optional filters)
            $table->json('conditions')->nullable(); // Location filters, time filters, etc.
            
            // Actions to execute
            $table->json('actions'); // Array of actions with their configurations
            
            // Execution tracking
            $table->integer('execution_count')->default(0);
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamp('last_successful_execution_at')->nullable();
            
            // AI settings
            $table->boolean('ai_enabled')->default(false);
            $table->json('ai_config')->nullable(); // AI-specific settings
            
            $table->timestamps();
            
            $table->index(['tenant_id', 'is_active']);
            $table->index(['trigger_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflows');
    }
};