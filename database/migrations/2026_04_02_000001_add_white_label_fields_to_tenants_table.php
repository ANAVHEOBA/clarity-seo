<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('brand_name')->nullable()->after('name');
            $table->string('logo_url')->nullable()->after('logo');
            $table->string('favicon_url')->nullable()->after('logo_url');
            $table->string('primary_color')->nullable()->after('favicon_url');
            $table->string('secondary_color')->nullable()->after('primary_color');
            $table->string('support_email')->nullable()->after('secondary_color');
            $table->string('reply_to_email')->nullable()->after('support_email');
            $table->string('custom_domain')->nullable()->unique()->after('reply_to_email');
            $table->timestamp('custom_domain_verified_at')->nullable()->after('custom_domain');
            $table->boolean('public_signup_enabled')->default(false)->after('custom_domain_verified_at');
            $table->boolean('hide_vendor_branding')->default(false)->after('public_signup_enabled');
        });

        DB::table('tenants')
            ->where('white_label_enabled', true)
            ->update(['hide_vendor_branding' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn([
                'brand_name',
                'logo_url',
                'favicon_url',
                'primary_color',
                'secondary_color',
                'support_email',
                'reply_to_email',
                'custom_domain',
                'custom_domain_verified_at',
                'public_signup_enabled',
                'hide_vendor_branding',
            ]);
        });
    }
};
