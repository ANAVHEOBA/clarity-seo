<?php

declare(strict_types=1);

namespace App\Http\Resources\Sentiment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TopicCollection extends ResourceCollection
{
    public $collects = null;

    /** @return array<int, array<string, mixed>> */
    public function toArray(Request $request): array
    {
        return $this->collection->toArray();
    }
}
