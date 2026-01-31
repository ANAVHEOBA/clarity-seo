<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo',
        'plan',
        'white_label_enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'white_label_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->slug)) {
                $tenant->slug = Str::slug($tenant->name);
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TenantInvitation::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'owner');
    }

    public function admins(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'admin');
    }

    public function members(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'member');
    }

    public function hasUser(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    public function getUserRole(User $user): ?string
    {
        $membership = $this->users()->where('user_id', $user->id)->first();

        return $membership?->pivot->role;
    }

    public function isOwner(User $user): bool
    {
        return $this->getUserRole($user) === 'owner';
    }

    public function isAdmin(User $user): bool
    {
        return $this->getUserRole($user) === 'admin';
    }

    public function isMember(User $user): bool
    {
        return $this->getUserRole($user) === 'member';
    }

    public function canManageMembers(User $user): bool
    {
        return in_array($this->getUserRole($user), ['owner', 'admin']);
    }

    public function canManageSettings(User $user): bool
    {
        return in_array($this->getUserRole($user), ['owner', 'admin']);
    }

    public function canDelete(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function isWhiteLabelEnabled(): bool
    {
        return $this->white_label_enabled === true;
    }

    public function isPremium(): bool
    {
        return in_array($this->plan, ['premium', 'enterprise']);
    }
}
