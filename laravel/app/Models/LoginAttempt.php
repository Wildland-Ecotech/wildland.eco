<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * One attempt to sign in at /login, recorded by RecordLoginAttempt whether
 * it worked or not, for spotting someone trying to get in: the email
 * address as typed, the admin account it belongs to (if any), the IP
 * address and browser, and what happened. The password is never recorded.
 * Kept indefinitely. Holds email and IP addresses: never paste them into
 * issues or chat.
 */
class LoginAttempt extends Model
{
    public const OUTCOME_SIGNED_IN = 'signed_in';

    /**
     * A wrong password, or an email address without an admin account.
     */
    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_RATE_LIMITED = 'rate_limited';

    /**
     * The proof of work was missing or wrong.
     */
    public const OUTCOME_UNVERIFIED = 'unverified';

    /**
     * The email address or password was missing, or the address wasn't one.
     */
    public const OUTCOME_INCOMPLETE = 'incomplete';

    /**
     * The page had expired (a CSRF token from an old session).
     */
    public const OUTCOME_EXPIRED = 'expired';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOMES = [
        self::OUTCOME_SIGNED_IN,
        self::OUTCOME_FAILED,
        self::OUTCOME_RATE_LIMITED,
        self::OUTCOME_UNVERIFIED,
        self::OUTCOME_INCOMPLETE,
        self::OUTCOME_EXPIRED,
        self::OUTCOME_ERROR,
    ];

    /**
     * Where RecordLoginAttempt leaves the attempt on the request, for the
     * controller to say what happened.
     */
    public const REQUEST_ATTRIBUTE = 'login_attempt';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }

    /**
     * The admin account the email address belongs to, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Say what happened to the attempt being recorded for a request.
     * RecordLoginAttempt saves it once the response is ready. The attempt is
     * the same object on a form request, whose attributes are a copy of the
     * request's, so setting it there reaches the middleware.
     */
    public static function outcomeFor(Request $request, string $outcome): void
    {
        $attempt = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($attempt instanceof self) {
            $attempt->outcome = $outcome;
        }
    }

    /**
     * Whether it signed someone in.
     */
    public function succeeded(): bool
    {
        return $this->outcome === self::OUTCOME_SIGNED_IN;
    }

    /**
     * What happened, for admins: a failure is "Wrong password" for an admin's
     * email address and "No admin account" for any other, which the sign-in
     * page itself never tells apart.
     */
    public function label(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_SIGNED_IN => 'Signed in',
            self::OUTCOME_FAILED => $this->user_id !== null ? 'Wrong password' : 'No admin account',
            self::OUTCOME_RATE_LIMITED => 'Too many attempts',
            self::OUTCOME_UNVERIFIED => 'No proof of work',
            self::OUTCOME_INCOMPLETE => 'Missing email or password',
            self::OUTCOME_EXPIRED => 'Page expired',
            default => 'Error',
        };
    }
}
