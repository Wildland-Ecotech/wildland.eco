<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Http\Request;

/**
 * One submission of the donate or leaf application form, recorded the moment
 * it arrives by App\Http\Middleware\RecordFormSubmission, before validation
 * or anything else can fail. If something goes wrong, the visitor sees its
 * reference, and we can see what they entered and reach out to them.
 *
 * Holds the visitor's name, email, and form details, even for submissions
 * that failed: never paste them into issues, chat, or the repository. Kept
 * permanently.
 *
 * Admins can mark a submission as spam, such as a bot filling in a form.
 * Who marked it, and when, are set only through markAsSpam(), never from
 * form input.
 */
#[Fillable([
    'reference',
    'form',
    'name',
    'email',
    'data',
    'outcome',
    'errors',
    'checkout_id',
    'leaf_application_id',
    'user_agent',
])]
class FormSubmission extends Model
{
    public const FORM_DONATION = 'donation';

    public const FORM_LEAF_APPLICATION = 'leaf_application';

    public const FORMS = [self::FORM_DONATION, self::FORM_LEAF_APPLICATION];

    /**
     * Recorded, and nothing has happened yet. A submission still received
     * after the request ended stopped without a known outcome.
     */
    public const OUTCOME_RECEIVED = 'received';

    /**
     * The form's rules rejected it; `errors` has the messages shown.
     */
    public const OUTCOME_REJECTED = 'rejected';

    /**
     * The page had expired (a 419), usually because it was left open for
     * hours, and the visitor was asked to reload it.
     */
    public const OUTCOME_EXPIRED = 'expired';

    /**
     * Too many submissions from the same visitor in the last hour.
     */
    public const OUTCOME_RATE_LIMITED = 'rate_limited';

    /**
     * Online donations weren't configured.
     */
    public const OUTCOME_UNAVAILABLE = 'unavailable';

    /**
     * The payment processor couldn't be reached.
     */
    public const OUTCOME_PAYMENT_ERROR = 'payment_error';

    /**
     * The server failed with an error (a 5xx), which is also in the log.
     */
    public const OUTCOME_ERROR = 'error';

    /**
     * The donor was sent to the payment processor to pay; `checkout_id`
     * links the checkout, whose status shows whether they finished.
     */
    public const OUTCOME_SENT_TO_PAYMENT = 'sent_to_payment';

    /**
     * The leaf application was saved; `leaf_application_id` links it.
     */
    public const OUTCOME_SAVED = 'saved';

    public const OUTCOMES = [
        self::OUTCOME_RECEIVED,
        self::OUTCOME_REJECTED,
        self::OUTCOME_EXPIRED,
        self::OUTCOME_RATE_LIMITED,
        self::OUTCOME_UNAVAILABLE,
        self::OUTCOME_PAYMENT_ERROR,
        self::OUTCOME_ERROR,
        self::OUTCOME_SENT_TO_PAYMENT,
        self::OUTCOME_SAVED,
    ];

    /**
     * Outcomes where the visitor didn't get what they came for.
     */
    public const FAILED_OUTCOMES = [
        self::OUTCOME_REJECTED,
        self::OUTCOME_EXPIRED,
        self::OUTCOME_RATE_LIMITED,
        self::OUTCOME_UNAVAILABLE,
        self::OUTCOME_PAYMENT_ERROR,
        self::OUTCOME_ERROR,
    ];

    /**
     * Where the middleware keeps the request's submission for controllers.
     */
    public const REQUEST_ATTRIBUTE = 'form_submission';

    /**
     * Form fields never stored: security tokens, and the name and email,
     * which have their own columns.
     */
    private const NOT_STORED = ['_token', '_method', 'pow_challenge', 'pow_nonce', 'name', 'email'];

    /**
     * Limits on what is stored, since anyone can submit anything: at most
     * this many fields, each cut to this many characters.
     */
    private const MAX_FIELDS = 30;

    private const MAX_VALUE_LENGTH = 5000;

    /**
     * Characters of the reference: digits and capital letters without the
     * lookalikes 0, 1, I, L, and O, so it can be read aloud or typed.
     */
    private const REFERENCE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const REFERENCE_LENGTH = 6;

    /**
     * The visitor's later submission of the same form that went through,
     * for one that didn't. Set by App\Reports\FormSubmissionReport, which
     * looks it up for the submissions it lists; null when there is none.
     */
    public ?FormSubmission $laterSubmission = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'errors' => 'array',
            'marked_spam_at' => 'datetime',
        ];
    }

    /**
     * Record a submission as it arrives, keeping what the visitor typed even
     * if it's invalid.
     */
    public static function start(string $form, Request $request): self
    {
        $email = self::text($request->input('email'), 255);

        return self::create([
            'reference' => self::newReference(),
            'form' => $form,
            'name' => self::text($request->input('name'), 255),
            'email' => $email === null ? null : mb_strtolower($email),
            'data' => self::formData($request),
            'outcome' => self::OUTCOME_RECEIVED,
            'user_agent' => self::text($request->userAgent(), 500),
        ]);
    }

    /**
     * The submission being handled by this request, if its route records
     * submissions.
     */
    public static function forRequest(Request $request): ?self
    {
        return $request->attributes->get(self::REQUEST_ATTRIBUTE);
    }

    /**
     * Record what happened to the submission.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function markAs(string $outcome, array $attributes = []): void
    {
        $this->update(['outcome' => $outcome, ...$attributes]);
    }

    public function failed(): bool
    {
        return in_array($this->outcome, self::FAILED_OUTCOMES, true);
    }

    public function isSpam(): bool
    {
        return $this->marked_spam_at !== null;
    }

    /**
     * Mark the submission as spam, recording which admin did and when. One
     * already marked keeps its first mark.
     */
    public function markAsSpam(User $admin): void
    {
        if ($this->isSpam()) {
            return;
        }

        $this->forceFill(['marked_spam_at' => now(), 'marked_spam_by' => $admin->id])->save();
    }

    /**
     * Undo a mistaken spam mark.
     */
    public function unmarkSpam(): void
    {
        $this->forceFill(['marked_spam_at' => null, 'marked_spam_by' => null])->save();
    }

    /**
     * The phone number entered on the leaf application form, as digits only
     * without the US country code, so different spellings of the same number
     * match: "(970) 555-0123" and "+1 970.555.0123" are both "9705550123".
     * Null without one, or with fewer than 7 digits.
     */
    public function phoneDigits(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($this->data['phone'] ?? ''));

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    /**
     * The admin who marked the submission as spam.
     *
     * @return BelongsTo<User, $this>
     */
    public function markedSpamBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_spam_by')->withTrashed();
    }

    /**
     * The checkout a donation submission started.
     *
     * @return BelongsTo<Checkout, $this>
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    /**
     * The application a leaf application submission saved.
     *
     * @return BelongsTo<LeafApplication, $this>
     */
    public function leafApplication(): BelongsTo
    {
        return $this->belongsTo(LeafApplication::class)->withTrashed();
    }

    /**
     * The donation a donation submission's checkout paid for: its first
     * payment, for a recurring donation.
     *
     * @return HasOneThrough<Donation, Checkout, $this>
     */
    public function donation(): HasOneThrough
    {
        return $this->hasOneThrough(Donation::class, Checkout::class, 'id', 'checkout_id', 'checkout_id', 'id')
            ->withTrashed()
            ->orderBy('donations.id');
    }

    /**
     * The submitted fields, other than NOT_STORED, cut to the storage limits.
     *
     * @return array<string, string>
     */
    private static function formData(Request $request): array
    {
        return collect($request->except(self::NOT_STORED))
            ->take(self::MAX_FIELDS)
            ->map(fn ($value) => self::text($value, self::MAX_VALUE_LENGTH))
            ->reject(fn ($value) => $value === null)
            ->all();
    }

    /**
     * A submitted value as trimmed text, cut to $limit characters, or null
     * when empty. Anything other than text, such as an array a bot sent, is
     * stored as JSON.
     */
    private static function text(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_scalar($value) ? (string) $value : (string) json_encode($value);
        $text = trim($text);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    /**
     * A random reference no other submission has.
     */
    private static function newReference(): string
    {
        do {
            $reference = '';

            for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
                $reference .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }
}
