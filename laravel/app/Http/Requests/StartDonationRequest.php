<?php

namespace App\Http\Requests;

use App\Models\Checkout;
use App\Services\ProofOfWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a gift submitted from the donate page (/donate), before the
 * donor is sent to the payment gateway to pay.
 */
class StartDonationRequest extends FormRequest
{
    /**
     * The smallest and largest gifts accepted online, in dollars; the donate
     * form's JavaScript uses the same limits. Larger gifts are welcome by
     * check or bank transfer.
     */
    public const MIN_AMOUNT = 1;

    public const MAX_AMOUNT = 9999;

    /**
     * Shown when the proof of work is missing or invalid, usually because
     * JavaScript is off or the page was open for more than two hours.
     */
    public const PROOF_OF_WORK_MESSAGE = 'We could not verify your browser. Please try again with JavaScript turned on, or email us at contact@wildland.eco.';

    /**
     * Anyone can donate.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Work out the amount before validation: the custom amount when one is
     * entered, otherwise the suggested amount chosen for the frequency. Each
     * frequency's suggested amounts have their own field (amount_one_time,
     * amount_monthly, amount_annual). The custom amount is cleaned first, so
     * "$1,250.00" or " 25 " is accepted.
     */
    protected function prepareForValidation(): void
    {
        $custom = preg_replace('/[\s$,]/', '', (string) $this->input('custom_amount', ''));
        $frequency = (string) $this->input('frequency', '');

        $this->merge([
            'amount' => $custom !== '' ? $custom : $this->input("amount_{$frequency}"),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'frequency' => ['required', Rule::in(Checkout::FREQUENCIES)],
            // Dollars, with at most two decimal places
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'min:' . self::MIN_AMOUNT, 'max:' . self::MAX_AMOUNT],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // Hidden from people; spam bots fill in every field
            'website' => ['prohibited'],
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
     * The validated amount in cents, calculated exactly from the decimal
     * string rather than through a float.
     */
    public function amountCents(): int
    {
        [$dollars, $cents] = explode('.', $this->validated('amount') . '.');

        return (int) $dollars * 100 + (int) str_pad($cents, 2, '0');
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $range = 'Enter an amount from $' . self::MIN_AMOUNT . ' to $' . number_format(self::MAX_AMOUNT) . '. For larger gifts, email us at contact@wildland.eco.';

        return [
            'frequency.required' => 'Choose one-time, monthly, or annual.',
            'frequency.in' => 'Choose one-time, monthly, or annual.',
            'amount.required' => 'Choose an amount, or enter your own.',
            'amount.regex' => 'Enter an amount in dollars, such as 25 or 25.50.',
            'amount.numeric' => 'Enter an amount in dollars, such as 25 or 25.50.',
            'amount.min' => $range,
            'amount.max' => $range,
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address, such as name@example.com.',
            'website.prohibited' => 'Something went wrong. Please try again.',
            'pow_challenge.required' => self::PROOF_OF_WORK_MESSAGE,
            'pow_nonce.required' => self::PROOF_OF_WORK_MESSAGE,
        ];
    }
}
