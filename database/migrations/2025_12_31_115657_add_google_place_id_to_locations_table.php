<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('google_place_id')->nullable()->after('status');
            $table->string('yelp_business_id')->nullable()->after('google_place_id');
            $table->timestamp('reviews_synced_at')->nullable()->after('yelp_business_id');

            $table->index('google_place_id');
            $table->index('yelp_business_id');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['google_place_id']);
            $table->dropIndex(['yelp_business_id']);
            $table->dropColumn(['google_place_id', 'yelp_business_id', 'reviews_synced_at']);
        });
    }
};
