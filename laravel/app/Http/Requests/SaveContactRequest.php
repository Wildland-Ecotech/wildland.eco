<?php

namespace App\Http\Requests;

use App\Models\ContactDetail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a contact added or edited on the admin contact pages: a name,
 * and optionally notes, whether they're a drop-off site, and, when adding,
 * a phone number, email, and address. Only admins reach it, through the admin routes' middleware.
 */
class SaveContactRequest extends FormRequest
{
    /**
     * A US phone number with its area code and any extension, as the leaf
     * application form takes it (ContactDetail::PHONE_PATTERN).
     */
    public const PHONE_RULE = 'regex:' . ContactDetail::PHONE_PATTERN;

    public const NOTES_MAX_LENGTH = 2000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', self::PHONE_RULE],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:' . self::NOTES_MAX_LENGTH],
            'is_drop_off_site' => ['nullable', 'boolean'],
            // Add the contact even though another has the same phone number or email
            'separate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get custom error messages. Every field has a message under its bare
     * name, so Laravel's default wording is never shown; FormMessagesTest
     * checks that every field has one.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the contact’s name.',
            'name.max' => 'Enter a name of 255 characters or fewer.',
            'name' => 'Enter the contact’s name as text.',
            'phone.max' => 'Enter a phone number of 50 characters or fewer.',
            'phone' => 'Enter a phone number with its area code, such as (970) 555-0123, and any extension after it, such as x12.',
            'email.max' => 'Enter an email address of 255 characters or fewer.',
            'email' => 'Enter a valid email address, such as name@example.com.',
            'address' => 'Enter an address of 500 characters or fewer.',
            'notes' => 'Keep the notes to ' . number_format(self::NOTES_MAX_LENGTH) . ' characters or fewer.',
            'is_drop_off_site' => 'Check the box if this is one of our drop-off sites, or leave it unchecked.',
            'separate' => 'Check the box to add a separate contact, or leave it unchecked.',
        ];
    }
}
