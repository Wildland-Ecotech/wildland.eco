<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a contact's emails, phone numbers, or addresses. A detail that
 * changes is marked removed rather than deleted, so matching still finds a
 * previous number or address. `normalized` is set from the value on every
 * save, for matching.
 *
 * A contact has at most one current address and at most one primary detail
 * of each type, which the database enforces. Change them through Contact's
 * methods, which keep to both.
 */
#[Fillable([
    'type',
    'value',
    'label',
])]
class ContactDetail extends Model
{
    public const TYPE_EMAIL = 'email';

    public const TYPE_PHONE = 'phone';

    public const TYPE_ADDRESS = 'address';

    public const TYPES = [self::TYPE_EMAIL, self::TYPE_PHONE, self::TYPE_ADDRESS];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'removed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ContactDetail $detail) {
            $detail->value = trim($detail->value);
            $detail->normalized = self::normalize($detail->type, $detail->value);
        });
    }

    /**
     * A detail's value in the form used for matching, or null when there's
     * nothing to match on:
     *
     * - email: trimmed and lowercase
     * - phone: a US number's 10 digits, without a leading country code 1;
     *   null without all 10, such as a number without an area code
     * - address: lowercase, with commas and periods as spaces and spacing
     *   collapsed, so "123 Example St., Fort Collins" matches
     *   "123 example st fort collins"
     */
    public static function normalize(string $type, ?string $value): ?string
    {
        $value = trim((string) $value);

        $normalized = match ($type) {
            self::TYPE_EMAIL => mb_strtolower($value),
            self::TYPE_PHONE => self::phoneDigits($value),
            self::TYPE_ADDRESS => trim(preg_replace('/[\s,.]+/u', ' ', mb_strtolower($value))),
        };

        return $normalized === '' ? null : $normalized;
    }

    /**
     * A US phone number's 10 digits, or null.
     */
    private static function phoneDigits(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    /**
     * Details still in use, not marked removed.
     *
     * @param  Builder<ContactDetail>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * The contact the detail belongs to, even if deleted.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }
}
