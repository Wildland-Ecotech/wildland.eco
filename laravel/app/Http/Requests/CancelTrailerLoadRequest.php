<?php

namespace App\Http\Requests;

use App\Models\TrailerLoad;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates canceling a planned trailer load on its page: the box confirming
 * it. Only admins reach it, through the admin routes' middleware.
 */
class CancelTrailerLoadRequest extends FormRequest
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
     * Get custom error messages. Every field has a message under its bare
     * name, so Laravel's default wording is never shown; FormMessagesTest
     * checks that every field has one.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm' => 'Check the box to confirm, then press Cancel Load.',
        ];
    }

    /**
     * Back to the Cancel This Load section of the load's page, so the error
     * is in view.
     */
    protected function getRedirectUrl(): string
    {
        /** @var TrailerLoad $load */
        $load = $this->route('load');

        return route('admin.map', ['load' => $load->id]) . '#cancel';
    }
}
