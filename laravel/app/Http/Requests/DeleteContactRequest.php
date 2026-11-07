<?php

namespace App\Http\Requests;

use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates deleting a contact on the admin edit page: the box confirming
 * it, and that no application belongs to them, even a deleted one, so only
 * contacts added by mistake or as a test can be deleted. Only admins reach
 * it, through the admin routes' middleware.
 */
class DeleteContactRequest extends FormRequest
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
            'confirm' => ['accepted'],
        ];
    }

    /**
     * Check that no application belongs to the contact, in case one was
     * linked since the page was opened.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Contact $contact */
                $contact = $this->route('contact');
                $count = $contact->applications()->withTrashed()->count();

                if ($count > 0) {
                    $validator->errors()->add('confirm', self::hasApplications($contact, $count));
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
            'confirm' => 'Check the box to confirm, then press Delete Contact.',
        ];
    }

    /**
     * Back to the Delete Contact section of the edit page, so the error is
     * in view.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.contacts.edit', $this->route('contact')->id) . '#delete';
    }

    /**
     * Why a contact with applications can't be deleted, and what to do
     * instead, for the edit page and the error.
     */
    public static function hasApplications(Contact $contact, int $count): string
    {
        $applications = $count === 1 ? 'an application' : number_format($count) . ' applications';

        return "{$contact->name} has {$applications}, so they can’t be deleted. If this is a duplicate, merge it into the other contact from Shares Details With.";
    }
}
