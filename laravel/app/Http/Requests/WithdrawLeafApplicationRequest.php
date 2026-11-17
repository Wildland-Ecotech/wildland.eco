<?php

namespace App\Http\Requests;

use App\Models\LeafApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates withdrawing a leaf application on its admin page: why, and a
 * note with the details, required for Other. Only admins reach it, through
 * the admin routes' middleware.
 */
class WithdrawLeafApplicationRequest extends FormRequest
{
    public const NOTE_MAX_LENGTH = 2000;

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
            'reason' => ['required', Rule::in(LeafApplication::WITHDRAWN_REASONS)],
            'withdrawn_note' => ['nullable', 'required_if:reason,' . LeafApplication::WITHDRAWN_OTHER, 'string', 'max:' . self::NOTE_MAX_LENGTH],
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
            'reason' => 'Choose why the application is withdrawn.',
            'withdrawn_note.required_if' => 'Add a note saying what happened, since the reason is Other.',
            'withdrawn_note' => 'Keep the note to ' . number_format(self::NOTE_MAX_LENGTH) . ' characters or fewer.',
        ];
    }

    /**
     * Back to the Withdraw section of the application's page, so the error
     * is in view.
     */
    protected function getRedirectUrl(): string
    {
        /** @var LeafApplication $application */
        $application = $this->route('application');

        return route('admin.leaves.show', $application->id) . '#withdraw';
    }
}
