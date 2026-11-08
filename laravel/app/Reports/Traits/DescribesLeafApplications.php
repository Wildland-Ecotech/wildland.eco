<?php

namespace App\Reports\Traits;

use App\Models\ContactDetail;
use App\Models\LeafApplication;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * How reports describe a leaf program application, so
 * leaves:applications, its CSV, leaves:view, and the admin pages show the
 * same details.
 */
trait DescribesLeafApplications
{
    use FormatsTimes;

    /**
     * The columns of a list of applications: the key details of each.
     */
    protected const LEAF_APPLICATION_TABLE_HEADERS = ['ID', 'Applied', 'Name', 'Type', 'Bags', 'Ready'];

    /**
     * Labels for an application's fields, in order.
     */
    protected const LEAF_APPLICATION_LABELS = [
        'Type',
        'Name',
        'Email',
        'Phone',
        'Address',
        'Estimated bags',
        'Bags ready',
        'Heard about us',
        'Questions',
    ];

    /**
     * An application's row in a list, matching
     * LEAF_APPLICATION_TABLE_HEADERS.
     *
     * @return array<int, mixed>
     */
    protected function leafApplicationTableRow(LeafApplication $application): array
    {
        return [
            $application->id,
            $this->tableTime($application->created_at),
            $application->name,
            $this->leafApplicationType($application),
            $application->estimated_bag_count,
            $this->bagsReady($application),
        ];
    }

    /**
     * The header row of a CSV of applications: every detail but when the
     * terms were accepted.
     *
     * @return array<int, string>
     */
    protected function leafApplicationCsvHeader(): array
    {
        return ['ID', 'Applied', ...self::LEAF_APPLICATION_LABELS];
    }

    /**
     * An application's row in a CSV, matching leafApplicationCsvHeader().
     *
     * @return array<int, mixed>
     */
    protected function leafApplicationCsvRow(LeafApplication $application): array
    {
        return [
            $application->id,
            $this->localTime($application->created_at),
            ...array_values($this->leafApplicationFields($application)),
        ];
    }

    /**
     * What a list of applications holds, such as "applications" or "pickup
     * applications". $types are LeafApplication::TYPES; with none or both,
     * no type is named.
     *
     * @param  array<int, string>  $types
     */
    protected function leafApplicationListName(array $types, int $count): string
    {
        $names = [LeafApplication::TYPE_DROP_OFF => 'drop-off', LeafApplication::TYPE_PICKUP => 'pickup'];
        $which = $types === [] || count($types) === count(LeafApplication::TYPES)
            ? ''
            : Arr::join(array_map(fn (string $type) => $names[$type], $types), ', ', ' and ');

        return trim("{$which} " . Str::plural('application', $count));
    }

    /**
     * An application's fields, keyed by LEAF_APPLICATION_LABELS, with the
     * phone number formatted (ContactDetail::formatPhone()).
     *
     * @return array<string, string>
     */
    protected function leafApplicationFields(LeafApplication $application): array
    {
        return array_combine(self::LEAF_APPLICATION_LABELS, [
            $this->leafApplicationType($application),
            $application->name,
            $application->email,
            ContactDetail::formatPhone($application->phone),
            $application->address,
            (string) $application->estimated_bag_count,
            $this->bagsReady($application),
            (string) $application->referral_source,
            (string) $application->questions,
        ]);
    }

    /**
     * Whether the donor asked for a pickup or will drop the leaves off.
     */
    protected function leafApplicationType(LeafApplication $application): string
    {
        return $application->type === LeafApplication::TYPE_PICKUP ? 'Pickup' : 'Drop-off';
    }

    /**
     * When the bags are ready: now, or the date the donor gave, such as
     * "Tue, Oct 27, 2026".
     */
    protected function bagsReady(LeafApplication $application): string
    {
        return $application->ready_now ? 'Now' : $application->ready_date->format('D, M j, Y');
    }
}
