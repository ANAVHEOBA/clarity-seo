<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('automation_executions')->cascadeOnDelete();
            
            // Log details
            $table->enum('level', ['debug', 'info', 'warning', 'error'])->default('info');
            $table->string('message');
            $table->json('context')->nullable(); // Additional context data
            
            // Source tracking
            $table->string('action_type')->nullable(); // Which action generated this log
            $table->integer('action_index')->nullable(); // Which action in the sequence
            
            $table->timestamp('created_at');
            
            $table->index(['workflow_id', 'level']);
            $table->index(['execution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_logs');
    }
};