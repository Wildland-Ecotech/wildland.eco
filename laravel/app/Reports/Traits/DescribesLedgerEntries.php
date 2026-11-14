<?php

namespace App\Reports\Traits;

use App\Models\ContactActivity;

/**
 * How admin pages name the donor of a leaf ledger entry, so the Leaf Ledger,
 * its CSV, and the Leaves dashboard agree: the contact's name, or Unknown
 * for drop-offs with no contact yet.
 */
trait DescribesLedgerEntries
{
    /**
     * The name shown for drop-offs with no contact yet.
     */
    protected const UNKNOWN_DONOR = 'Unknown';

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
}
