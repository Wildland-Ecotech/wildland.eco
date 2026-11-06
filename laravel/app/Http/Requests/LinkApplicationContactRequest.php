<?php

namespace App\Http\Requests;

use App\Models\Contact;
use App\Models\LeafApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates moving or linking a leaf application to a contact on the admin
 * application page: a contact's ID, or `new` for a new contact made from the
 * application. Only admins reach it, through the admin routes' middleware.
 */
class LinkApplicationContactRequest extends FormRequest
{
    public const NEW_CONTACT = 'new';

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
            'contact' => ['required', 'string', 'regex:/^(new|[0-9]+)$/'],
        ];
    }

    /**
     * Check that a chosen contact exists, isn't deleted, and isn't the one
     * the application is already linked to.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('contact') || $this->input('contact') === self::NEW_CONTACT) {
                    return;
                }

                /** @var LeafApplication $application */
                $application = $this->route('application');

                if ((int) $this->input('contact') === $application->contact_id) {
                    $validator->errors()->add('contact', 'The application is already linked to that contact. Choose another, or a new contact.');
                } elseif ($this->contact() === null) {
                    $validator->errors()->add('contact', 'That contact no longer exists. Choose another, or a new contact.');
                }
            },
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
            'contact' => 'Choose a contact from the list, or a new contact.',
        ];
    }

    /**
     * The chosen contact, not deleted, or null for a new contact.
     */
    public function contact(): ?Contact
    {
        $contact = $this->input('contact');

        return $contact === self::NEW_CONTACT ? null : Contact::find((int) $contact);
    }
}
