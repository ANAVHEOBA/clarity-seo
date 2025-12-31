<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_responses', function (Blueprint $table) {
            $table->foreignId('brand_voice_id')->nullable()->after('ai_generated')->constrained()->nullOnDelete();
            $table->string('tone', 20)->default('professional')->after('brand_voice_id');
            $table->string('language', 10)->default('en')->after('tone');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('review_responses', function (Blueprint $table) {
            $table->dropForeign(['brand_voice_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'brand_voice_id',
                'tone',
                'language',
                'approved_by',
                'approved_at',
                'rejection_reason',
            ]);
        });
    }
};
