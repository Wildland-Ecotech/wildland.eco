<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates locations pasted on the admin Map page (LocationImport reads
 * them), to preview or, with `confirm`, to save. Only admins reach it,
 * through the admin routes' middleware.
 */
class SaveLocationsRequest extends FormRequest
{
    /**
     * Enough for a few thousand lines.
     */
    public const MAX_LENGTH = 200000;

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
            'lines' => ['required', 'string', 'max:' . self::MAX_LENGTH],
            // The Save button, after a preview
            'confirm' => ['nullable', 'boolean'],
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
            'lines.max' => 'Paste fewer lines at a time.',
            'lines' => 'Paste the lines the geocoder printed.',
            'confirm' => 'Choose Preview or Save.',
        ];
    }
}
