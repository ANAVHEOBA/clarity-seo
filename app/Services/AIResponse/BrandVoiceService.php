<?php

declare(strict_types=1);

namespace App\Services\AIResponse;

use App\Models\BrandVoice;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class BrandVoiceService
{
    public function listForTenant(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $query = BrandVoice::query()
            ->where('tenant_id', $tenant->id);

        if (isset($filters['tone'])) {
            $query->where('tone', $filters['tone']);
        }

        if (isset($filters['search'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        $sortField = $filters['sort'] ?? 'created_at';
        $sortDirection = $filters['direction'] ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    public function findForTenant(Tenant $tenant, int $brandVoiceId): ?BrandVoice
    {
        return BrandVoice::where('tenant_id', $tenant->id)
            ->find($brandVoiceId);
    }

    public function create(Tenant $tenant, array $data): BrandVoice
    {
        $brandVoice = BrandVoice::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'tone' => $data['tone'],
            'guidelines' => $data['guidelines'],
            'example_responses' => $data['example_responses'] ?? [],
            'is_default' => $data['is_default'] ?? false,
        ]);

        if ($brandVoice->is_default) {
            $brandVoice->markAsDefault();
        }

        return $brandVoice;
    }

    public function update(BrandVoice $brandVoice, array $data): BrandVoice
    {
        $brandVoice->update($data);

        if (isset($data['is_default']) && $data['is_default']) {
            $brandVoice->markAsDefault();
        }

        return $brandVoice->fresh();
    }

    public function delete(BrandVoice $brandVoice): void
    {
        $brandVoice->delete();
    }

    public function getDefault(Tenant $tenant): ?BrandVoice
    {
        return BrandVoice::where('tenant_id', $tenant->id)
            ->where('is_default', true)
            ->first();
    }

    public function setDefault(BrandVoice $brandVoice): BrandVoice
    {
        $brandVoice->markAsDefault();

        return $brandVoice->fresh();
    }
}
