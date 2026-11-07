<?php

namespace App\Http\Requests;

use App\Models\ContactActivity;
use App\Models\LeafApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates an entry logged on a contact's timeline from the admin Log
 * Activity page: what happened, who reached out (for texts and calls), when,
 * what was said, bags (for pickups and drop-offs), the application it was
 * about, and when to follow up. Only admins reach it, through the admin
 * routes' middleware.
 */
class LogContactActivityRequest extends FormRequest
{
    /**
     * Times on the form are Fort Collins times.
     */
    public const TIMEZONE = 'America/Denver';

    /**
     * The format of the form's date and time field (datetime-local).
     */
    public const TIME_FORMAT = 'Y-m-d\TH:i';

    public const BODY_MAX_LENGTH = 5000;

    public const MAX_BAGS = 10000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Bag counts are typed by people, so commas and spaces are allowed.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('bag_count'))) {
            $this->merge(['bag_count' => preg_replace('/[\s,]/', '', $this->input('bag_count'))]);
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
            'type' => ['required', 'string', Rule::in(ContactActivity::TYPES)],
            'direction' => ['nullable', 'required_if:type,' . implode(',', ContactActivity::MESSAGE_TYPES), 'string', Rule::in(ContactActivity::DIRECTIONS)],
            'happened_at' => ['required', 'string', 'date_format:' . self::TIME_FORMAT],
            'body' => ['nullable', 'string', 'max:' . self::BODY_MAX_LENGTH],
            'bag_count' => ['nullable', 'required_if:type,' . implode(',', ContactActivity::COMPLETING_TYPES), 'integer', 'min:1', 'max:' . self::MAX_BAGS],
            // One of the contact's applications, not deleted
            'leaf_application_id' => [
                'nullable',
                'integer',
                Rule::exists(LeafApplication::class, 'id')->where('contact_id', $this->route('contact')?->id)->whereNull('deleted_at'),
            ],
            'follow_up_on' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:' . Carbon::today(self::TIMEZONE)->toDateString()],
            // The Save and Add Another button
            'add_another' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Check that the time isn't in the future.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $validator->errors()->has('happened_at') && $this->happenedAt()->isFuture()) {
                    $validator->errors()->add('happened_at', 'Enter a time that has already happened.');
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
            'type' => 'Choose what happened: a text, call, visit, note, pickup, or drop-off.',
            'direction' => 'Choose who reached out: we did, or they did.',
            'happened_at' => 'Enter the date and time it happened.',
            'body' => 'Keep it to ' . number_format(self::BODY_MAX_LENGTH) . ' characters or fewer.',
            'bag_count.required_if' => 'Enter how many bags.',
            'bag_count' => 'Enter how many bags as a whole number, such as 25.',
            'leaf_application_id' => 'Choose one of this contact’s applications, or None.',
            'follow_up_on.after_or_equal' => 'Choose today or a later date to follow up.',
            'follow_up_on' => 'Enter the date to follow up, such as Nov 13.',
            'add_another' => 'Choose Save or Save and Add Another.',
        ];
    }

    /**
     * When it happened, from the form's Fort Collins time, in the app's time
     * zone (UTC), since Eloquent saves a time as it is without converting
     * it. Only for valid input.
     */
    public function happenedAt(): Carbon
    {
        return Carbon::createFromFormat(self::TIME_FORMAT, $this->input('happened_at'), self::TIMEZONE)
            ->startOfMinute()
            ->setTimezone(config('app.timezone'));
    }
}
