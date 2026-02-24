<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('apple_app_store_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('apple_app_store_account_id')->nullable()->constrained('apple_app_store_accounts')->nullOnDelete();
            $table->string('name');
            $table->string('app_store_id')->nullable();
            $table->string('bundle_id')->nullable();
            $table->string('country_code', 2)->default('US');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'app_store_id'], 'apple_app_store_apps_unique_app_store_id');
            $table->unique(['tenant_id', 'bundle_id'], 'apple_app_store_apps_unique_bundle_id');
            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apple_app_store_apps');
    }
};
