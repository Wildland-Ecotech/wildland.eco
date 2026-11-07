<?php

namespace App\Http\Requests;

use App\Models\TrailerLoad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Validates marking a planned trailer load done on its Mark Done page: when
 * the pickups happened, the stops picked up (`stops[]`, application IDs),
 * and the bags from each (`bags[ID]`). Only stops whose pickup can be logged
 * (TrailerLoad::loggableApplications()) can be checked. Only admins reach
 * it, through the admin routes' middleware.
 */
class MarkTrailerLoadDoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Bag counts are typed by people, so commas and spaces are allowed. A
     * stop left unchecked isn't logged, so its bags are left out and never
     * checked. The form keeps them as typed, since old input is flashed
     * before this cleanup.
     */
    protected function prepareForValidation(): void
    {
        $bags = $this->input('bags');
        $stops = $this->input('stops');

        if (is_array($bags)) {
            $chosen = is_array($stops) ? array_map('strval', array_filter($stops, 'is_scalar')) : [];

            $this->merge(['bags' => collect($bags)
                ->filter(fn ($value, $id) => in_array((string) $id, $chosen, true))
                ->map(fn ($value) => is_string($value) ? preg_replace('/[\s,]/', '', $value) : $value)
                ->all()]);
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
            'finished_at' => ['required', 'string', 'date_format:' . LogContactActivityRequest::TIME_FORMAT],
            'stops' => ['nullable', 'array'],
            'stops.*' => ['integer'],
            'bags' => ['nullable', 'array'],
            'bags.*' => ['nullable', 'integer', 'min:1', 'max:' . LogContactActivityRequest::MAX_BAGS],
        ];
    }

    /**
     * Pickups that have already happened, from stops that can still be
     * logged, each with its bags.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $validator->errors()->has('finished_at') && $this->finishedAt()->isFuture()) {
                    $validator->errors()->add('finished_at', 'Enter a time that has already happened.');
                }

                if ($validator->errors()->has('stops') || $validator->errors()->has('stops.*')) {
                    return;
                }

                /** @var TrailerLoad $load */
                $load = $this->route('load');
                $loggable = $load->loggableApplications()->pluck('id')->all();

                if (array_diff($this->chosen(), $loggable) !== []) {
                    $validator->errors()->add('stops', 'A stop changed since this page opened. Check the list, then try again.');

                    return;
                }

                foreach ($this->chosen() as $id) {
                    if (($this->input("bags.{$id}") ?? '') === '' && ! $validator->errors()->has("bags.{$id}")) {
                        $validator->errors()->add("bags.{$id}", 'Enter how many bags were picked up.');
                    }
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
            'finished_at' => 'Enter the date and time of the pickups.',
            'stops' => 'Choose the stops picked up from the list.',
            'stops.*' => 'Choose the stops picked up from the list.',
            'bags' => 'Enter how many bags as a whole number, such as 25.',
            'bags.*' => 'Enter how many bags as a whole number, such as 25.',
        ];
    }

    /**
     * Back to the Mark Done page, with the form as it was.
     */
    protected function getRedirectUrl(): string
    {
        /** @var TrailerLoad $load */
        $load = $this->route('load');

        return route('admin.map.loads.done', $load->id);
    }

    /**
     * The IDs of the stops checked, without repeats. Only for valid input.
     *
     * @return list<int>
     */
    public function chosen(): array
    {
        return array_values(array_unique(array_map('intval', $this->input('stops', []) ?? [])));
    }

    /**
     * The bags picked up from each stop checked, by application ID. Only
     * for valid input.
     *
     * @return array<int, int>
     */
    public function pickups(): array
    {
        return collect($this->chosen())->mapWithKeys(fn (int $id) => [$id => (int) $this->input("bags.{$id}")])->all();
    }

    /**
     * When the pickups happened, from the form's Fort Collins time, in the
     * app's time zone (UTC), as LogContactActivityRequest::happenedAt().
     * Only for valid input.
     */
    public function finishedAt(): Carbon
    {
        return Carbon::createFromFormat(LogContactActivityRequest::TIME_FORMAT, $this->input('finished_at'), LogContactActivityRequest::TIMEZONE)
            ->startOfMinute()
            ->setTimezone(config('app.timezone'));
    }
}
