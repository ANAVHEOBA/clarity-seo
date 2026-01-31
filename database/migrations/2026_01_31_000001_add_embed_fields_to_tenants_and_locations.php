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
            $table->string('plan')->default('free')->after('logo');
            $table->boolean('white_label_enabled')->default(false)->after('plan');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->string('embed_key')->unique()->nullable()->after('youtube_channel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['plan', 'white_label_enabled']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('embed_key');
        });
    }
};
