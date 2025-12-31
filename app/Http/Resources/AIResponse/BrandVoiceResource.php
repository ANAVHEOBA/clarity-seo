<?php

declare(strict_types=1);

namespace App\Http\Resources\AIResponse;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandVoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'tone' => $this->tone,
            'guidelines' => $this->guidelines,
            'example_responses' => $this->example_responses,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
