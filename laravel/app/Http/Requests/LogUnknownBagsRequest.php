<?php

namespace App\Http\Requests;

/**
 * Validates bags found at drop-off sites with no known donor, from the
 * admin Log Unknown Bags page: when, the drop-off site, the
 * bags, their brand, and notes. Times and cleanup work as on the timeline
 * form (LogContactActivityRequest). Only admins reach it, through the admin
 * routes' middleware.
 */
class LogUnknownBagsRequest extends LogContactActivityRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'happened_at' => ['required', 'string', 'date_format:' . self::TIME_FORMAT],
            'drop_off_site_id' => ['required', 'integer', self::dropOffSite()],
            'bag_count' => ['required', 'integer', 'min:1', 'max:' . self::MAX_BAGS],
            'bag_brand' => ['nullable', 'string', 'max:' . self::BRAND_MAX_LENGTH],
            'body' => ['nullable', 'string', 'max:' . self::BODY_MAX_LENGTH],
            // The Save and Add Another button
            'add_another' => ['nullable', 'boolean'],
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
            'happened_at' => 'Enter the date and time the bags were found.',
            'drop_off_site_id.required' => 'Choose the drop-off site the bags were left at.',
            'drop_off_site_id' => 'Choose one of the drop-off sites.',
            'bag_count.required' => 'Enter how many bags.',
            'bag_count' => 'Enter how many bags as a whole number, such as 25.',
            'bag_brand' => 'Keep the brand to ' . self::BRAND_MAX_LENGTH . ' characters or fewer.',
            'body' => 'Keep the notes to ' . number_format(self::BODY_MAX_LENGTH) . ' characters or fewer.',
            'add_another' => 'Choose Save or Save and Add Another.',
        ];
    }
}
