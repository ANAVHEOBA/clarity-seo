<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIResponseHistory extends Model
{
    use HasFactory;

    protected $table = 'ai_response_histories';

    protected $fillable = [
        'review_id',
        'user_id',
        'content',
        'tone',
        'language',
        'brand_voice_id',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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
}
