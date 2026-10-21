<?php

namespace App\Http\Requests;

use App\Models\LeafApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validates an application to donate leaves from /leaves/apply.
 */
class StoreLeafApplicationRequest extends FormRequest
{
    /**
     * The leaf program runs in Fort Collins, so "today" is the local date there
     * rather than the server's UTC date, which changes in the evening.
     */
    public const TIMEZONE = 'America/Denver';

    /**
     * Today's date where the leaf program runs.
     */
    public static function today(): Carbon
    {
        return Carbon::today(self::TIMEZONE);
    }

    /**
     * Anyone can apply.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Clean the bag count before validation, so "1,200" or " 12 " is accepted.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('estimated_bag_count'))) {
            $this->merge([
                'estimated_bag_count' => preg_replace('/[\s,]/', '', $this->input('estimated_bag_count')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(LeafApplication::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^\+?[0-9\s().-]{7,}$/'],
            'address' => ['required', 'string', 'max:500'],
            'estimated_bag_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'ready_now' => ['required', 'boolean'],
            // Only asked when the bags are not ready yet: the soonest date they will be
            'ready_date' => ['exclude_if:ready_now,1', 'required', 'date_format:Y-m-d', 'after:' . self::today()->toDateString()],
            'referral_source' => ['nullable', 'string', 'max:2000'],
            'questions' => ['nullable', 'string', 'max:5000'],
            'terms' => ['accepted'],
            // Hidden from people; spam bots fill in every field
            'website' => ['prohibited'],
        ];
    }

    /**
     * Get custom names for attributes in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone' => 'phone number',
            'estimated_bag_count' => 'number of bags',
            'ready_now' => 'whether your bags are ready',
            'ready_date' => 'date your bags will be ready',
            'referral_source' => 'how you heard about us',
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Choose drop-off or pickup.',
            'type.in' => 'Choose drop-off or pickup.',
            'phone.regex' => 'Enter a phone number using digits, such as (970) 555-0123.',
            'estimated_bag_count.integer' => 'Enter the number of bags as a whole number. An estimate is fine.',
            'ready_now.required' => 'Tell us whether your bags are ready now.',
            'ready_date.required' => 'Tell us when your bags will be ready, or choose that they are ready now.',
            'ready_date.after' => 'Choose a date after today, or choose that your bags are ready now.',
            'terms.accepted' => 'Please agree to the terms to apply.',
            'website.prohibited' => 'Something went wrong. Please try again.',
        ];
    }
}
