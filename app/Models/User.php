<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MemberStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'first_name', 'middle_name', 'last_name', 'profile_pic',
    'phone', 'phone_verified_at', 'email', 'password', 'status', 'disabled_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Full display name. Setting it splits the value into first / middle / last name,
     * so the API can keep accepting and returning a single `name`.
     *
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): string => trim(implode(' ', array_filter([
                $attributes['first_name'] ?? null,
                $attributes['middle_name'] ?? null,
                $attributes['last_name'] ?? null,
            ]))),
            set: function (string $value): array {
                $parts = preg_split('/\s+/', trim($value)) ?: [];
                $first = array_shift($parts) ?? '';
                $last = count($parts) > 0 ? array_pop($parts) : null;

                return [
                    'first_name' => $first,
                    'middle_name' => count($parts) > 0 ? implode(' ', $parts) : null,
                    'last_name' => $last,
                ];
            },
        );
    }

    /**
     * Events this user created.
     *
     * @return HasMany<Event, $this>
     */
    public function ownedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'owner_id');
    }

    /**
     * Every membership / invitation row linked to this user.
     *
     * @return HasMany<EventMember, $this>
     */
    public function eventMemberships(): HasMany
    {
        return $this->hasMany(EventMember::class);
    }

    /**
     * Events this user has joined (including ones they own).
     *
     * @return BelongsToMany<Event, $this>
     */
    public function joinedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_members')
            ->wherePivot('status', MemberStatus::Joined->value)
            ->withPivot(['id', 'role', 'status', 'added_at'])
            ->withTimestamps();
    }

    /**
     * Invitations waiting for this user's answer.
     *
     * @return HasMany<EventMember, $this>
     */
    public function pendingInvitations(): HasMany
    {
        return $this->eventMemberships()->where('status', MemberStatus::Invited);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Contributions this user paid.
     *
     * @return HasMany<Contribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * @return HasMany<Withdrawal, $this>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /**
     * The user's wallet ledger.
     *
     * @return HasMany<WalletTransaction, $this>
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * @return HasMany<DeviceToken, $this>
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }
}
