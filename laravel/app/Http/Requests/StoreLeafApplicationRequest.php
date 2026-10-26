<?php

namespace App\Http\Requests;

use App\Models\LeafApplication;
use App\Services\ProofOfWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates an application to donate leaves from /leaves/apply.
 */
class StoreLeafApplicationRequest extends FormRequest
{
    /**
     * The leaf program runs in Fort Collins, so "today" is the local date there
     * rather than the server's UTC date, which changes in the evening.
     */
    public const TIMEZONE = 'America/Denver';

    /**
     * Shown when the proof of work is missing or invalid, usually because
     * JavaScript is off or the page was open for more than two hours.
     */
    public const PROOF_OF_WORK_MESSAGE = 'We could not verify your browser. Please try again with JavaScript turned on, or email us at contact@wildland.eco to apply.';

    /**
     * Today's date where the leaf program runs.
     */
    public static function today(): Carbon
    {
        return Carbon::today(self::TIMEZONE);
    }

    /**
     * Anyone can apply.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Clean the bag count before validation, so "1,200" or " 12 " is accepted.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('estimated_bag_count'))) {
            $this->merge([
                'estimated_bag_count' => preg_replace('/[\s,]/', '', $this->input('estimated_bag_count')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(LeafApplication::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^\+?[0-9\s().-]{7,}$/'],
            'address' => ['required', 'string', 'max:500'],
            'estimated_bag_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'ready_now' => ['required', 'boolean'],
            // Only asked when the bags are not ready yet: the soonest date they will be
            'ready_date' => ['exclude_if:ready_now,1', 'required', 'date_format:Y-m-d', 'after:' . self::today()->toDateString()],
            'referral_source' => ['nullable', 'string', 'max:2000'],
            'questions' => ['nullable', 'string', 'max:5000'],
            'terms' => ['accepted'],
            // Proof of work, solved by resources/js/proof-of-work.js; checked in after()
            'pow_challenge' => ['required', 'string', 'max:255'],
            'pow_nonce' => ['required', 'string', 'max:16'],
        ];
    }

    /**
     * Check the proof of work once its fields are present. A challenge is
     * used up even if other fields fail; the form issues a new one.
     *
     * @return array<int, callable>
     */
    public function after(ProofOfWork $proofOfWork): array
    {
        return [
            function (Validator $validator) use ($proofOfWork) {
                if ($validator->errors()->hasAny(['pow_challenge', 'pow_nonce'])) {
                    return;
                }

                if (! $proofOfWork->verify($this->input('pow_challenge'), $this->input('pow_nonce'))) {
                    $validator->errors()->add('pow_nonce', self::PROOF_OF_WORK_MESSAGE);
                }
            },
        ];
    }

    /**
     * Get custom names for attributes in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone' => 'phone number',
            'estimated_bag_count' => 'number of bags',
            'ready_now' => 'whether your bags are ready',
            'ready_date' => 'date your bags will be ready',
            'referral_source' => 'how you heard about us',
        ];
    }

    /**
     * Get custom error messages. Every field has a message under its bare
     * name, which Laravel uses for any rule without its own message, so no
     * one ever sees Laravel's default wording. Each message says what to do
     * (see "Error messages" in docs/08-style-guide.md); FormMessagesTest
     * checks that every field has one.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type' => 'Choose drop-off or pickup.',
            'name.required' => 'Enter your name.',
            'name.max' => 'Enter a name of 255 characters or fewer.',
            'name' => 'Enter your name as text.',
            'email.required' => 'Enter your email address.',
            'email.max' => 'Enter an email address of 255 characters or fewer.',
            'email' => 'Enter a valid email address, such as name@example.com.',
            'phone.required' => 'Enter your phone number, so we can reach you about your leaves.',
            'phone.max' => 'Enter a phone number of 50 characters or fewer.',
            'phone' => 'Enter a phone number using digits, such as (970) 555-0123.',
            'address.required' => 'Enter the address your leaves are coming from.',
            'address.max' => 'Enter an address of 500 characters or fewer.',
            'address' => 'Enter the address your leaves are coming from.',
            'estimated_bag_count.required' => 'Enter about how many bags you have. An estimate is fine.',
            'estimated_bag_count.min' => 'Enter at least 1 bag.',
            'estimated_bag_count.max' => 'Enter 10,000 bags or fewer. For more, email us at contact@wildland.eco.',
            'estimated_bag_count' => 'Enter the number of bags as a whole number. An estimate is fine.',
            'ready_now' => 'Tell us whether your bags are ready now.',
            'ready_date.required' => 'Tell us when your bags will be ready, or choose that they are ready now.',
            'ready_date.after' => 'Choose a date after today, or choose that your bags are ready now.',
            'ready_date' => 'Choose the date your bags will be ready from the calendar.',
            'referral_source' => 'Keep how you heard about us to 2,000 characters or fewer.',
            'questions' => 'Keep your questions to 5,000 characters or fewer, or email them to us at contact@wildland.eco.',
            'terms' => 'Please agree to the terms to apply.',
            'pow_challenge' => self::PROOF_OF_WORK_MESSAGE,
            'pow_nonce' => self::PROOF_OF_WORK_MESSAGE,
        ];
    }
}
