<?php

namespace App\Models;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'profile_photo_path', 'password', 'must_change_password', 'temporary_password_issued_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $appends = ['avatar'];

    protected $attributes = [
        'must_change_password' => false,
    ];

    public function getAvatarAttribute(): ?string
    {
        $path = $this->attributes['profile_photo_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        $updatedAt = $this->attributes['updated_at'] ?? null;
        $version = $updatedAt ? strtotime((string) $updatedAt) ?: 1 : 1;

        return "/profile-photos/{$this->id}?v={$version}";
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'temporary_password_issued_at' => 'datetime',
        ];
    }

    public function requiresPasswordChange(): bool
    {
        return (bool) $this->must_change_password;
    }

    /**
     * All campus membership records for this user.
     *
     * @return HasMany<CampusMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CampusMembership::class);
    }

    /**
     * Primary / latest campus membership record for this user.
     *
     * @return HasOne<CampusMembership, $this>
     */
    public function primaryMembership(): HasOne
    {
        return $this->hasOne(CampusMembership::class)->latestOfMany();
    }

    /**
     * Return the user's verified CpfRole if their membership is approved, or null otherwise.
     */
    public function verifiedRole(): ?CpfRole
    {
        if (app()->environment(['local', 'testing']) && session()->has('cpf_test_role_override')) {
            return CpfRole::tryFrom((string) session('cpf_test_role_override'));
        }

        $membership = $this->primaryMembership;

        if (! $membership || $membership->status !== MembershipStatus::Approved) {
            return null;
        }

        return $membership->role;
    }

    /**
     * Check if the user is an approved Adviser, President, or Secretary who can add/approve members.
     */
    public function canManageCampusMembers(): bool
    {
        return $this->verifiedRole()?->canApproveMembers() ?? false;
    }

    /**
     * Check if the user is an approved Adviser or President who can confirm the Messenger GC checklist.
     */
    public function canIssuePasskeys(): bool
    {
        return $this->verifiedRole()?->canIssuePasskeys() ?? false;
    }

    public function canConfirmMessengerGc(): bool
    {
        return $this->verifiedRole()?->canConfirmMessengerGc() ?? false;
    }

    /**
     * Check if the user is the Club Adviser (highest authority in charge, no separate Admin role).
     */
    public function canValidatePayments(): bool
    {
        return $this->verifiedRole()?->canValidatePayments() ?? false;
    }

    public function canAuditFinances(): bool
    {
        return $this->verifiedRole()?->canAuditFinances() ?? false;
    }

    public function isClubAdviser(): bool
    {
        return $this->verifiedRole()?->isTopAuthority() ?? false;
    }

    public function canProposeEvents(): bool
    {
        return $this->verifiedRole()?->canProposeEvents() ?? false;
    }
}
