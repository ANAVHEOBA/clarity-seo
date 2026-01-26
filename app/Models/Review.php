<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Automation\Triggers\TriggerEvaluator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'platform',
        'external_id',
        'author_name',
        'author_image',
        'rating',
        'content',
        'published_at',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(ReviewResponse::class);
    }

    public function sentiment(): HasOne
    {
        return $this->hasOne(ReviewSentiment::class);
    }

    public function aiResponseHistories(): HasMany
    {
        return $this->hasMany(AIResponseHistory::class);
    }

    public function hasSentiment(): bool
    {
        return $this->sentiment()->exists();
    }

    public function hasResponse(): bool
    {
        return $this->response()->exists();
    }

    public function isPositive(): bool
    {
        return $this->rating >= 4;
    }

    public function isNegative(): bool
    {
        return $this->rating <= 3;
    }

    protected static function booted(): void
    {
        static::created(function (Review $review) {
            // Trigger automation workflows when a new review is created
            try {
                $triggerEvaluator = app(TriggerEvaluator::class);
                $triggerEvaluator->handleReviewReceived($review);
            } catch (\Exception $e) {
                // Log error but don't fail review creation
                \Illuminate\Support\Facades\Log::error('Automation trigger failed for review', [
                    'review_id' => $review->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
