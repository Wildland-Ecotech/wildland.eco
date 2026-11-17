<?php

namespace App\Reports\Traits;

use App\Models\ContactActivity;

/**
 * How admin pages describe a leaf ledger entry, so the Leaf Ledger, its CSV,
 * and the Leaves dashboard agree: the donor, the contact's name or Unknown
 * for drop-offs with no contact yet, and the drop-off site.
 */
trait DescribesLedgerEntries
{
    /**
     * The name shown for drop-offs with no contact yet.
     */
    protected const UNKNOWN_DONOR = 'Unknown';

    /**
     * The site shown for drop-offs logged before drop-off sites were
     * recorded.
     */
    protected const SITE_NOT_RECORDED = 'Not recorded';

    /**
     * The donor's name, or Unknown.
     */
    protected function ledgerEntryName(ContactActivity $entry): string
    {
        return $entry->isUnknown() ? self::UNKNOWN_DONOR : $entry->contact->name;
    }

    /**
     * The donor's name linking to the contact's page, or Unknown, for a
     * table cell.
     *
     * @return string|array{text: string, url: string}
     */
    protected function ledgerEntryNameCell(ContactActivity $entry): string|array
    {
        return $entry->isUnknown()
            ? self::UNKNOWN_DONOR
            : ['text' => $entry->contact->name, 'url' => route('admin.contacts.show', $entry->contact_id)];
    }

    /**
     * The drop-off site the bags were left at, Not recorded for drop-offs
     * with no site, or empty for pickups.
     */
    protected function ledgerEntrySite(ContactActivity $entry): string
    {
        if ($entry->type !== ContactActivity::TYPE_DROP_OFF) {
            return '';
        }

        return $entry->dropOffSite?->name ?? self::SITE_NOT_RECORDED;
    }
}
