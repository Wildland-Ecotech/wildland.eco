<?php

namespace App\Http\Requests;

use App\Models\LeafApplication;
use App\Models\TrailerLoad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a trailer load chosen on the admin Map page, new or edited: the
 * day it's planned for, the trailer's capacity in bags, and its stops,
 * pending pickup applications not already in another planned load, in
 * route order. A load can be over its capacity; the page warns in red. Only
 * admins reach it, through the admin routes' middleware.
 */
class SaveTrailerLoadRequest extends FormRequest
{
    public const MAX_CAPACITY = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The capacity as typed, such as "1,000" or " 80 ", and each stop once:
     * a pickup's box is on the page twice, in the table and in the list
     * shown on phones.
     */
    protected function prepareForValidation(): void
    {
        $capacity = $this->input('capacity');
        $stops = $this->input('stops');

        $this->merge([
            'capacity' => is_string($capacity) ? preg_replace('/[\s,]/', '', $capacity) : $capacity,
            'stops' => is_array($stops) ? array_values(array_unique(array_map('strval', array_filter($stops, 'is_scalar')))) : $stops,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planned_on' => ['required', 'date_format:Y-m-d'],
            'capacity' => ['required', 'integer', 'min:1', 'max:' . self::MAX_CAPACITY],
            'stops' => ['required', 'array'],
            'stops.*' => ['integer'],
        ];
    }

    /**
     * Check that every stop is a pending pickup, not deleted, and not in
     * another planned load (a load being edited can keep its own), in case
     * something changed since the page was opened.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('stops') || $validator->errors()->has('stops.*')) {
                    return;
                }

                $stops = $this->stops();
                $pickups = LeafApplication::pending()->where('type', LeafApplication::TYPE_PICKUP)->whereIn('id', $stops)->count();

                if ($pickups !== count($stops)) {
                    $validator->errors()->add('stops', 'A pickup you chose has been picked up or removed since the page opened. Check the pickups again.');
                } elseif (array_intersect($stops, $this->scheduledElsewhere()) !== []) {
                    $validator->errors()->add('stops', 'A pickup you chose is already in another planned load. Check the pickups again.');
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
            'planned_on' => 'Enter the day the load is planned for.',
            'capacity.max' => 'Enter a capacity of ' . number_format(self::MAX_CAPACITY) . ' bags or fewer.',
            'capacity' => 'Enter how many bags the trailer holds, such as 80.',
            'stops.required' => 'Choose at least one pickup: click it on the map, or check it in the list.',
            'stops' => 'Choose the pickups from the list.',
            'stops.*' => 'Choose the pickups from the list.',
        ];
    }

    /**
     * The applications in a planned load other than the one being edited.
     *
     * @return list<int>
     */
    private function scheduledElsewhere(): array
    {
        $load = $this->route('load');
        $own = $load instanceof TrailerLoad ? $load->applications()->pluck('leaf_applications.id')->map(fn ($id) => (int) $id)->all() : [];

        return array_values(array_diff(TrailerLoad::scheduledApplicationIds(), $own));
    }

    /**
     * The chosen applications' IDs, in route order.
     *
     * @return list<int>
     */
    public function stops(): array
    {
        return array_map('intval', $this->input('stops', []));
    }
}
