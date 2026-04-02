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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('domain_verification_token')->nullable()->after('custom_domain_verified_at');
            $table->timestamp('domain_verification_requested_at')->nullable()->after('domain_verification_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'domain_verification_token',
                'domain_verification_requested_at',
            ]);
        });
    }
};
