<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Generate external_id for reviews that have NULL
        DB::table('reviews')
            ->whereNull('external_id')
            ->orderBy('id')
            ->chunk(100, function ($reviews) {
                foreach ($reviews as $review) {
                    // Generate a unique external_id based on review data
                    $externalId = $this->generateExternalId($review);
                    
                    DB::table('reviews')
                        ->where('id', $review->id)
                        ->update(['external_id' => $externalId]);
                }
            });

        // Step 2: Remove duplicate reviews (keep the oldest one)
        $duplicates = DB::table('reviews')
            ->select('location_id', 'platform', 'external_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('location_id', 'platform', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('reviews')
                ->where('location_id', $duplicate->location_id)
                ->where('platform', $duplicate->platform)
                ->where('external_id', $duplicate->external_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        // Step 3: Make external_id NOT NULL
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('external_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('external_id')->nullable()->change();
        });
    }

    private function generateExternalId($review): string
    {
        // Generate a unique ID based on multiple fields to avoid collisions
        $components = [
            $review->location_id,
            $review->platform,
            $review->author_name ?? 'anonymous',
            $review->published_at ?? $review->created_at,
            $review->rating,
            substr($review->content ?? '', 0, 50), // First 50 chars of content
        ];
        
        return md5(implode('|', $components));
    }
};
