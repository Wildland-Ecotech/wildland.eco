<?php

namespace App\Http\Requests;

use App\Services\LocationImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates what `addresses:geocode` printed, pasted on the admin Map page
 * (LocationImport reads it), to preview or, with `confirm`, to save. Only
 * admins reach it, through the admin routes' middleware.
 */
class SaveLocationsRequest extends FormRequest
{
    /**
     * Enough for a few thousand locations.
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
            'results' => ['required', 'string', 'max:' . self::MAX_LENGTH],
            // The Save button, after a preview
            'confirm' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Check that what was pasted is the command's JSON, with its list of
     * locations.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $validator->errors()->has('results') && LocationImport::decode($this->input('results')) === null) {
                    $validator->errors()->add('results', 'Paste everything addresses:geocode printed, from the { at the start to the } at the end.');
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
            'results.max' => 'Paste fewer locations at a time.',
            'results' => 'Paste what addresses:geocode printed.',
            'confirm' => 'Choose Preview or Save.',
        ];
    }
}
