<?php

namespace App\Http\Requests;

use App\Models\ContactDetail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a phone number, email, or address added to a contact on the
 * admin contact edit page. Each is its own small form with a hidden `type`
 * and a field named after it, so an error shows by the right form. Only
 * admins reach it, through the admin routes' middleware.
 */
class AddContactDetailRequest extends FormRequest
{
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
            'type' => ['required', Rule::in(ContactDetail::TYPES)],
            'phone' => ['exclude_unless:type,phone', 'required', 'string', 'max:50', SaveContactRequest::PHONE_RULE],
            'email' => ['exclude_unless:type,email', 'required', 'string', 'email', 'max:255'],
            'address' => ['exclude_unless:type,address', 'required', 'string', 'max:500'],
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
            'type' => 'Add a phone number, email, or address from its own box.',
            'phone.required' => 'Enter the phone number to add.',
            'phone.max' => 'Enter a phone number of 50 characters or fewer.',
            'phone' => 'Enter a phone number using digits, such as (970) 555-0123.',
            'email.required' => 'Enter the email to add.',
            'email.max' => 'Enter an email address of 255 characters or fewer.',
            'email' => 'Enter a valid email address, such as name@example.com.',
            'address.required' => 'Enter the new address.',
            'address' => 'Enter an address of 500 characters or fewer.',
        ];
    }

    /**
     * The value being added, from the field named after its type.
     */
    public function value(): string
    {
        return $this->validated($this->validated('type'));
    }
}
