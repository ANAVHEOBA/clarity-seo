<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'brand_name',
        'slug',
        'description',
        'logo',
        'logo_url',
        'favicon_url',
        'primary_color',
        'secondary_color',
        'support_email',
        'reply_to_email',
        'custom_domain',
        'custom_domain_verified_at',
        'domain_verification_token',
        'domain_verification_requested_at',
        'public_signup_enabled',
        'hide_vendor_branding',
        'plan',
        'white_label_enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'white_label_enabled' => 'boolean',
            'public_signup_enabled' => 'boolean',
            'hide_vendor_branding' => 'boolean',
            'custom_domain_verified_at' => 'datetime',
            'domain_verification_requested_at' => 'datetime',
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

    public function credentials(): HasMany
    {
        return $this->hasMany(PlatformCredential::class);
    }

    public function appStoreAccounts(): HasMany
    {
        return $this->hasMany(AppleAppStoreAccount::class);
    }

    public function appStoreApps(): HasMany
    {
        return $this->hasMany(AppleAppStoreApp::class);
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
        return $this->hide_vendor_branding === true || $this->white_label_enabled === true;
    }

    public function isPremium(): bool
    {
        return in_array($this->plan, ['premium', 'enterprise']);
    }

    public function brandDisplayName(): string
    {
        return $this->brand_name ?: $this->name ?: config('app.name');
    }

    public function resolvedLogoUrl(): ?string
    {
        return $this->logo_url ?: $this->logo;
    }

    public function hasVerifiedCustomDomain(): bool
    {
        return ! empty($this->custom_domain) && $this->custom_domain_verified_at !== null;
    }

    public function matchesCustomDomain(string $host): bool
    {
        return $this->hasVerifiedCustomDomain() && strcasecmp((string) $this->custom_domain, trim($host)) === 0;
    }

    public function portalBaseUrl(): string
    {
        if (! $this->hasVerifiedCustomDomain()) {
            return rtrim((string) config('app.url'), '/');
        }

        $scheme = app()->environment(['local', 'testing']) ? 'http' : 'https';

        return $scheme.'://'.$this->custom_domain;
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function brandingDefaults(): array
    {
        $branding = [
            'company_name' => $this->brandDisplayName(),
            'logo_url' => $this->resolvedLogoUrl(),
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'white_label' => $this->isWhiteLabelEnabled(),
            'footer_text' => $this->defaultFooterText(),
        ];

        return Arr::where($branding, static fn ($value) => $value !== null && $value !== '');
    }

    public function defaultFooterText(): string
    {
        if ($this->isWhiteLabelEnabled()) {
            if ($this->support_email) {
                return 'Prepared by '.$this->brandDisplayName().' | '.$this->support_email;
            }

            return 'Prepared by '.$this->brandDisplayName();
        }

        return 'Generated by '.config('app.name');
    }
}
