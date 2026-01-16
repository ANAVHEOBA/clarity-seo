<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('platform'); // facebook, google, bing
            $table->string('external_id')->nullable(); // page_id, place_id, etc.
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('token_type')->default('Bearer');
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable(); // Store platform-specific data (page_id, account_id, etc.)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unique constraint includes external_id to support multiple pages per tenant
            $table->unique(['tenant_id', 'platform', 'external_id']);
            $table->index(['platform', 'is_active']);
            $table->index('external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_credentials');
    }
};
