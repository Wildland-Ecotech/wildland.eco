<?php

namespace App\Models;

use App\Casts\GeographyPoint;
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
 *
 * An address can have a map location, set with locate() once it's
 * geocoded. A changed address is a new detail without one, so a detail's
 * location always belongs to its own address.
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
     * A phone number as every form takes it, the leaf application form and
     * the admin contact forms alike: a US number's 10 digits, with or
     * without a leading country code 1 or +1, typed with any spaces, dots,
     * dashes, or parentheses, and optionally an extension after it
     * ("extension", "ext", "ext.", or "x", then up to 6 digits). Such as
     * "(970) 555-0123", "+1 970.555.0123", or "970-555-0123 ext. 4".
     */
    public const PHONE_PATTERN = '/^(?:\+?1[\s.-]*)?(?:\(\s*\d{3}\s*\)|\d{3})[\s.-]*\d{3}[\s.-]*\d{4}(?:\s*(?:extension|ext\.?|x)\s*\d{1,6})?$/i';

    /**
     * An extension at the end of a phone number, as PHONE_PATTERN takes it,
     * with its digits.
     */
    private const PHONE_EXTENSION = '/\s*(?:extension|ext\.?|x)\s*(\d{1,6})$/i';

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
            'location' => GeographyPoint::class,
            'located_at' => 'datetime',
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
     * - phone: a US number's 10 digits, without a leading country code 1
     *   or any extension, so a number matches with or without one; null
     *   without all 10, such as a number without an area code saved before
     *   the forms required one
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
     * A phone number as admins see it: a US number's 10 digits as
     * 970-555-0123, however it was typed, with any extension after it as
     * "ext. 4", or the number as typed, trimmed, when it doesn't have all
     * 10, such as one saved before the forms required an area code. Null
     * stays null. Only for display: the value is stored as typed, and
     * matching uses `normalized`.
     */
    public static function formatPhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = self::phoneDigits($value);

        if ($digits === null) {
            return trim($value);
        }

        $formatted = substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6);

        return preg_match(self::PHONE_EXTENSION, trim($value), $extension) ? "{$formatted} ext. {$extension[1]}" : $formatted;
    }

    /**
     * The value as admins see it: a phone number as formatPhone() shows it,
     * or an email or address as it is.
     */
    public function displayValue(): string
    {
        return $this->type === self::TYPE_PHONE ? self::formatPhone($this->value) : $this->value;
    }

    /**
     * A phone number without the extension at its end, if it has one, as
     * typed otherwise, such as "970-555-0123" for "970-555-0123 ext. 4".
     */
    public static function withoutPhoneExtension(string $value): string
    {
        return preg_replace(self::PHONE_EXTENSION, '', trim($value));
    }

    /**
     * A US phone number's 10 digits, leaving out any extension, or null.
     */
    private static function phoneDigits(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', self::withoutPhoneExtension($value));

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

    /**
     * Set an address's map location, recording which admin set it and
     * when. The database refuses a location on an email or phone number.
     */
    public function locate(float $latitude, float $longitude, User $admin): void
    {
        $this->forceFill([
            'location' => ['latitude' => $latitude, 'longitude' => $longitude],
            'located_at' => now(),
            'located_by' => $admin->id,
        ])->save();
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
