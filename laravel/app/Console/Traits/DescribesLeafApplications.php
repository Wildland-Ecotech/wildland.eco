<?php

namespace App\Console\Traits;

use App\Models\LeafApplication;

/**
 * How report commands describe a leaf program application, so
 * leaves:applications, its CSV, and leaves:view show the same details.
 */
trait DescribesLeafApplications
{
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
     * An application's fields, keyed by LEAF_APPLICATION_LABELS.
     *
     * @return array<string, string>
     */
    protected function leafApplicationFields(LeafApplication $application): array
    {
        return array_combine(self::LEAF_APPLICATION_LABELS, [
            $this->leafApplicationType($application),
            $application->name,
            $application->email,
            $application->phone,
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
