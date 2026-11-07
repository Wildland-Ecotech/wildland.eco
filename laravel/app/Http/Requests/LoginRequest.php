<?php

namespace App\Http\Requests;

use App\Services\ProofOfWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the sign-in form at /login, including its proof of work, which
 * makes guessing passwords by script expensive.
 */
class LoginRequest extends FormRequest
{
    /**
     * Shown when the proof of work is missing or invalid, usually because
     * JavaScript is off or the page was open for more than two hours.
     */
    public const PROOF_OF_WORK_MESSAGE = 'We could not verify your browser. Reload the page and try again with JavaScript turned on.';

    /**
     * Anyone can try to sign in; LoginController checks the password.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim and lowercase the email address, since email addresses are stored
     * lowercase and PostgreSQL compares text case-sensitively.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            // Proof of work, solved by resources/js/proof-of-work.js; checked in after()
            'pow_challenge' => ['required', 'string', 'max:255'],
            'pow_nonce' => ['required', 'string', 'max:16'],
        ];
    }

    /**
     * Check the proof of work once its fields are present. A challenge is
     * used up when checked, so it can't sign in twice.
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
     * What to do about each problem, in plain words.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter your email address.',
            'email' => 'Enter a valid email address, such as name@example.com.',
            'password' => 'Enter your password.',
            'pow_challenge' => self::PROOF_OF_WORK_MESSAGE,
            'pow_nonce' => self::PROOF_OF_WORK_MESSAGE,
        ];
    }
}
