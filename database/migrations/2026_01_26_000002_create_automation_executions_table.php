<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            
            // Trigger context
            $table->json('trigger_data'); // The data that triggered this execution
            $table->string('trigger_source')->nullable(); // review_id, listing_id, etc.
            
            // Execution status
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            
            // Results
            $table->json('results')->nullable(); // Results of each action
            $table->integer('actions_completed')->default(0);
            $table->integer('actions_failed')->default(0);
            
            // AI execution details
            $table->boolean('ai_involved')->default(false);
            $table->json('ai_decisions')->nullable(); // AI decision logs
            
            $table->timestamps();
            
            $table->index(['workflow_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
    }
};