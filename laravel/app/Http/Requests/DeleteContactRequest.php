<?php

namespace App\Http\Requests;

use App\Models\Contact;
use App\Models\ContactActivity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates deleting a contact on the admin edit page: the box confirming
 * it, that no application belongs to them, even a deleted one, other than
 * applications marked as a test or spam, and that the Leaf Ledger has no
 * pickups or drop-offs of theirs and no drop-offs left at them as a
 * drop-off site, so only contacts added by mistake or as a test, or made by
 * bogus applications, can be deleted, and no bags drop out of the ledger.
 * Only admins reach it, through the admin routes' middleware.
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
     * Check that the contact can still be deleted, in case an application
     * was linked or bags were logged since the page was opened.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $reason = self::whyNot($this->route('contact'));

                if ($reason !== null) {
                    $validator->errors()->add('confirm', $reason);
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
     * Why a contact can't be deleted, for the edit page and the error, or
     * null when it can be: applications other than test and spam ones, even
     * deleted ones; pickups or drop-offs in the Leaf Ledger; or, for a
     * drop-off site, drop-offs left there.
     */
    public static function whyNot(Contact $contact): ?string
    {
        if ($contact->applications()->withTrashed()->notFlagged()->exists()) {
            return "Contacts with applications can't be deleted.";
        }

        if ($contact->activities()->whereIn('type', ContactActivity::COMPLETING_TYPES)->exists()) {
            return "Contacts with pickups or drop-offs in the Leaf Ledger can't be deleted.";
        }

        if ($contact->siteDropOffs()->exists()) {
            return "Drop-off sites with drop-offs in the Leaf Ledger can't be deleted.";
        }

        return null;
    }
}
