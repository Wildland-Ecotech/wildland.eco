<?php

namespace App\Http\Requests;

use App\Models\LeafApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates marking a leaf application as a test or spam on its admin page:
 * which one, and an optional note. Only admins reach it, through the admin
 * routes' middleware.
 */
class FlagLeafApplicationRequest extends FormRequest
{
    /**
     * The longest note, in characters.
     */
    public const NOTE_MAX_LENGTH = 1000;

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
            'flag' => ['required', Rule::in(LeafApplication::FLAGS)],
            'note' => ['nullable', 'string', 'max:' . self::NOTE_MAX_LENGTH],
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
            'flag' => 'Choose spam or test.',
            'note' => 'Keep the note to ' . number_format(self::NOTE_MAX_LENGTH) . ' characters or fewer.',
        ];
    }

    /**
     * Back to the Spam or Test section of the application's page, so the
     * error is in view.
     */
    protected function getRedirectUrl(): string
    {
        /** @var LeafApplication $application */
        $application = $this->route('application');

        return route('admin.leaves.show', $application->id) . '#mark';
    }
}
