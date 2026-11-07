<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     * Whether it didn't sign anyone in, for whatever reason.
     */
    public function failed(): bool
    {
        return ! $this->succeeded();
    }

    /**
     * The browser, roughly, from its user agent, such as "Firefox on Linux"
     * or "Safari on iPhone". A user agent that isn't a familiar browser,
     * such as a script's "curl/8.5.0", is shown as it is, cut short; it's
     * often the sign of someone guessing passwords. Empty without one.
     */
    public function browser(): string
    {
        $agent = (string) $this->user_agent;

        // In the order that tells them apart: Edge and Opera also say
        // Chrome, Chrome also says Safari, and an iPhone also says Mac OS X
        $browser = match (true) {
            str_contains($agent, 'HeadlessChrome/') => null,
            str_contains($agent, 'Edg/') || str_contains($agent, 'EdgiOS/') || str_contains($agent, 'EdgA/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $browser !== null && $system !== null ? "{$browser} on {$system}" : Str::limit($agent, 60);
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
