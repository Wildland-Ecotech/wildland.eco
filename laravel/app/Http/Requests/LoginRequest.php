<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the sign-in form at /login.
 */
class LoginRequest extends FormRequest
{
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
        ];
    }
}
