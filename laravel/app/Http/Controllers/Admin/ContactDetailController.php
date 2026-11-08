<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddContactDetailRequest;
use App\Models\Contact;
use App\Models\ContactDetail;
use Illuminate\Http\RedirectResponse;

/**
 * Editing a contact's phone numbers, emails, and address on the admin
 * contact edit page (/admin/contacts/{contact}/edit): adding one, making a
 * phone number or email the main one, and removing one. Removing keeps the
 * detail as a previous one, so applications still match it. Each goes back
 * to the edit page with a message.
 */
class ContactDetailController extends Controller
{
    /**
     * How each type of detail is named in messages.
     */
    public const KINDS = [
        ContactDetail::TYPE_PHONE => 'phone number',
        ContactDetail::TYPE_EMAIL => 'email',
        ContactDetail::TYPE_ADDRESS => 'address',
    ];

    /**
     * Add a phone number or email, or change the address, keeping the
     * previous address.
     */
    public function store(AddContactDetailRequest $request, Contact $contact): RedirectResponse
    {
        $type = $request->validated('type');
        $value = $request->value();
        $existing = $contact->findDetail($type, $value);

        if ($existing !== null && ! $existing->isRemoved()) {
            return $this->back($contact, $type === ContactDetail::TYPE_ADDRESS
                ? 'That’s already the address.'
                : "{$existing->displayValue()} is already on this contact.");
        }

        if ($type === ContactDetail::TYPE_ADDRESS) {
            $hadAddress = $contact->address() !== null;
            $contact->moveTo($value);

            return $this->back($contact, $hadAddress ? 'Changed the address. The previous one is kept as a previous address.' : 'Added the address.');
        }

        $detail = $contact->addDetail($type, $value);

        return $this->back($contact, "Added {$detail->displayValue()}.");
    }

    /**
     * Make a phone number or email the main one of its type.
     */
    public function primary(Contact $contact, ContactDetail $detail): RedirectResponse
    {
        abort_if($detail->isRemoved() || $detail->type === ContactDetail::TYPE_ADDRESS, 404);

        $contact->usePrimary($detail->type, $detail->value);

        return $this->back($contact, "{$detail->displayValue()} is now the main " . self::KINDS[$detail->type] . '.');
    }

    /**
     * Remove a phone number, email, or address, keeping it as a previous
     * one.
     */
    public function remove(Contact $contact, ContactDetail $detail): RedirectResponse
    {
        if (! $detail->isRemoved()) {
            $contact->removeDetail($detail);
        }

        return $this->back($contact, "Removed {$detail->displayValue()}. It’s kept as a previous " . self::KINDS[$detail->type] . ', so applications still match it.');
    }

    /**
     * Back to the contact's edit page with a message.
     */
    private function back(Contact $contact, string $status): RedirectResponse
    {
        return redirect()->route('admin.contacts.edit', $contact->id)->with('status', $status);
    }
}
