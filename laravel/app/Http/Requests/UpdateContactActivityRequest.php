<?php

namespace App\Http\Requests;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates editing a timeline entry from the admin edit page: every field
 * of the timeline form (LogContactActivityRequest) except follow-ups, plus
 * the contact, or none for drop-offs with no contact yet. Entries with no
 * contact must be drop-offs with no application, as the database requires,
 * and drop-offs need a drop-off site, older drop-offs included. Only admins
 * reach it, through the admin routes' middleware.
 */
class UpdateContactActivityRequest extends LogContactActivityRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['follow_up_on'], $rules['add_another']);

        return [
            // A contact, not deleted, or none
            'contact_id' => ['nullable', 'integer', Rule::exists(Contact::class, 'id')->whereNull('deleted_at')],
            ...$rules,
            // One of the chosen contact's applications, not deleted
            'leaf_application_id' => [
                'nullable',
                'integer',
                Rule::exists(LeafApplication::class, 'id')->where('contact_id', $this->integer('contact_id') ?: null)->whereNull('deleted_at'),
            ],
            // Where to go after saving: an admin page this came from
            'return_to' => ['nullable', 'string', 'max:2000', 'regex:#^/admin(/|\?|$)#'],
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
        $messages = parent::messages();
        unset($messages['follow_up_on.after_or_equal'], $messages['follow_up_on'], $messages['add_another']);

        return [
            ...$messages,
            'contact_id' => 'Choose one of the contacts, or Unknown.',
            'return_to' => 'Open the entry from its contact or the Leaf Ledger, then save again.',
        ];
    }

    /**
     * Check the time, as on the timeline form, and that entries with no
     * contact are drop-offs with no application.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                if ($validator->errors()->has('contact_id') || $this->input('contact_id') !== null) {
                    return;
                }

                if ($this->input('type') !== ContactActivity::TYPE_DROP_OFF) {
                    $validator->errors()->add('contact_id', 'Choose a contact. Only drop-offs can be Unknown.');
                } elseif ($this->input('leaf_application_id') !== null) {
                    $validator->errors()->add('leaf_application_id', 'Choose None for Unknown drop-offs, or choose a contact.');
                }
            },
        ];
    }
}
