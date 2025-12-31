<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_id',
        'user_id',
        'content',
        'status',
        'ai_generated',
        'brand_voice_id',
        'tone',
        'language',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'published_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ai_generated' => 'boolean',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brandVoice(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function approve(User $user): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    public function reject(string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);
    }

    public function publish(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
