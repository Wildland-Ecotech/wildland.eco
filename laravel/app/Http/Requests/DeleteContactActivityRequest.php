<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates deleting a timeline entry from its admin edit page: the box
 * confirming it, and where to go afterwards. Only admins reach it, through
 * the admin routes' middleware.
 */
class DeleteContactActivityRequest extends FormRequest
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
            // Where to go after deleting: an admin page the entry was opened from
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
        return [
            'confirm' => 'Check the box to confirm, then press Delete Entry.',
            'return_to' => 'Open the entry from its contact or the Leaf Ledger, then delete it again.',
        ];
    }
}
