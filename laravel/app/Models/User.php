<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;

/**
 * A donor or an administrator. Donors are created automatically from the
 * email address entered on the donate page, with no password. Board members
 * are administrators until they need different permissions. Deleting a user
 * soft-deletes it, so it is hidden but never lost.
 *
 * A user with transaction history, a payment we actually received (even if
 * refunded later), cannot be deleted at all, soft or hard. Soft-deleting a
 * user without it, such as a spam account from a carding attack, also
 * soft-deletes their failed donations, unpaid checkouts, and subscriptions
 * that never collected a payment, keeping them out of reports.
 *
 * The role is not mass assignable, so form input can never change it.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_DONOR = 'donor';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_DONOR, self::ROLE_ADMIN];

    /**
     * Default attribute values, matching the column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => self::ROLE_DONOR,
    ];

    /**
     * Enforce the deletion rules in docs/06-planned-features.md#deletion.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            if ($user->hasTransactionHistory()) {
                throw new LogicException("User {$user->id} has transaction history and cannot be deleted.");
            }

            // A hard delete is a deliberate one-off; foreign keys refuse it
            // while any related row remains
            if ($user->isForceDeleting()) {
                return;
            }

            // Without transaction history, none of these were ever paid
            $user->donations()->delete();
            $user->checkouts()->delete();
            $user->subscriptions()->delete();
        });
    }

    /**
     * Whether we have actually received a payment from the user, including
     * one refunded or soft-deleted later. Failed payments, unpaid checkouts,
     * and subscriptions that never collected a payment do not count.
     */
    public function hasTransactionHistory(): bool
    {
        return $this->donations()->withTrashed()->received()->exists();
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
        ];
    }

    /**
     * Store email addresses lowercase, so finding a user by email is
     * case-insensitive.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => mb_strtolower(trim($value)),
        );
    }

    /**
     * The donor's customer records at payment gateways.
     *
     * @return HasMany<GatewayCustomer, $this>
     */
    public function gatewayCustomers(): HasMany
    {
        return $this->hasMany(GatewayCustomer::class);
    }

    /**
     * The donor's attempts to donate, including ones never paid.
     *
     * @return HasMany<Checkout, $this>
     */
    public function checkouts(): HasMany
    {
        return $this->hasMany(Checkout::class);
    }

    /**
     * The donor's recurring donations.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The donor's payments, one-time and recurring, including failed ones.
     *
     * @return HasMany<Donation, $this>
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    /**
     * The tax receipts issued to the donor.
     *
     * @return HasMany<DonationReceipt, $this>
     */
    public function donationReceipts(): HasMany
    {
        return $this->hasMany(DonationReceipt::class);
    }
}
