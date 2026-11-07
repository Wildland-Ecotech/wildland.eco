<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Adding contacts by hand (/admin/contacts/new) and editing them
 * (/admin/contacts/{id}/edit): name, notes, and drop-off sites; adding,
 * making main, and removing phone numbers, emails, and the address; and
 * deleting a contact with no applications.
 */
class AdminContactFormsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Render pages without requiring built assets in public/build
        $this->withoutVite();

        $this->travelTo(Carbon::parse('2026-11-05 19:30', 'America/Denver'));

        $this->admin = User::factory()->admin()->withPassword()->create();
    }

    /**
     * A contact with a primary phone number and email.
     */
    private function contact(string $name = 'Jamie Rivera', string $phone = '(970) 555-0123', string $email = 'jamie@example.com'): Contact
    {
        $contact = Contact::create(['name' => $name]);
        $contact->usePrimary(ContactDetail::TYPE_PHONE, $phone);
        $contact->usePrimary(ContactDetail::TYPE_EMAIL, $email);

        return $contact;
    }

    /**
     * A pickup application belonging to the contact.
     */
    private function application(Contact $contact): LeafApplication
    {
        $application = new LeafApplication;
        $application->forceFill([
            'type' => LeafApplication::TYPE_PICKUP,
            'name' => $contact->name,
            'email' => 'jamie@example.com',
            'phone' => '(970) 555-0123',
            'address' => '123 Example St, Fort Collins, CO 80521',
            'estimated_bag_count' => 30,
            'ready_now' => true,
            'terms_accepted_at' => now(),
            'contact_id' => $contact->id,
        ])->save();

        return $application;
    }

    /**
     * The contact's current details of a type, as [value, primary] pairs.
     *
     * @return list<array{string, bool}>
     */
    private function current(Contact $contact, string $type): array
    {
        return $contact->details()->current()->where('type', $type)->orderBy('id')->get()
            ->map(fn (ContactDetail $detail) => [$detail->value, $detail->is_primary])
            ->all();
    }

    public function test_only_signed_in_admins_can_add_or_edit_contacts(): void
    {
        $contact = $this->contact();
        $detail = $contact->primary(ContactDetail::TYPE_PHONE);

        $requests = [
            ['get', '/admin/contacts/new'],
            ['post', '/admin/contacts'],
            ['get', "/admin/contacts/{$contact->id}/edit"],
            ['post', "/admin/contacts/{$contact->id}"],
            ['post', "/admin/contacts/{$contact->id}/details"],
            ['post', "/admin/contacts/{$contact->id}/details/{$detail->id}/primary"],
            ['post', "/admin/contacts/{$contact->id}/details/{$detail->id}/remove"],
            ['post', "/admin/contacts/{$contact->id}/delete"],
        ];

        // Every request as a guest first, since signing in lasts for the rest of the test
        foreach ($requests as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        $donor = User::factory()->withPassword()->create();

        foreach ($requests as [$method, $url]) {
            $this->actingAs($donor)->{$method}($url)->assertForbidden();
        }
    }

    public function test_the_list_and_contact_pages_link_to_the_forms(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->admin)
            ->get('/admin/contacts')
            ->assertSee('<a class="button dark small" href="' . route('admin.contacts.create') . '">New Contact</a>', false);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}")
            ->assertSee('<a class="button dark small" href="' . route('admin.contacts.edit', $contact->id) . '">Edit Contact</a>', false);
    }

    public function test_adds_a_contact_with_their_details(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/contacts/new')
            ->assertOk()
            ->assertSee('<h1>New Contact</h1>', false)
            ->assertSee('action="' . route('admin.contacts.store') . '"', false)
            // Only offered when another contact has the same details
            ->assertDontSee('Add as a separate contact anyway');

        $response = $this->actingAs($this->admin)->post('/admin/contacts', [
            'name' => ' Pat Morgan ',
            'phone' => '(970) 555-0177',
            'email' => 'Pat@Example.com',
            'address' => '789 Sample Ct, Fort Collins, CO 80525',
            'notes' => 'Texted Oct 30 about a pickup',
        ]);

        $contact = Contact::sole();
        $response->assertRedirect(route('admin.contacts.show', $contact->id))->assertSessionHas('status', 'Added Pat Morgan.');

        $this->assertSame('Pat Morgan', $contact->name);
        $this->assertSame('Texted Oct 30 about a pickup', $contact->notes);
        $this->assertSame([['(970) 555-0177', true]], $this->current($contact, ContactDetail::TYPE_PHONE));
        $this->assertSame('pat@example.com', $contact->primary(ContactDetail::TYPE_EMAIL)->normalized);
        $this->assertSame('789 Sample Ct, Fort Collins, CO 80525', $contact->address()->value);

        $this->actingAs($this->admin)
            ->get(route('admin.contacts.show', $contact->id))
            ->assertSee('Added Pat Morgan.');
    }

    public function test_only_a_name_is_needed(): void
    {
        $this->actingAs($this->admin)->post('/admin/contacts', ['name' => 'Pat Morgan', 'phone' => '', 'email' => '', 'address' => '', 'notes' => ''])
            ->assertSessionHasNoErrors();

        $contact = Contact::sole();
        $this->assertSame(0, $contact->details()->count());
        $this->assertNull($contact->notes);
    }

    public function test_errors_are_shown_and_input_is_kept(): void
    {
        $this->actingAs($this->admin)
            ->from('/admin/contacts/new')
            ->post('/admin/contacts', ['name' => '', 'phone' => 'call me', 'email' => 'not an email', 'address' => '123 Example St'])
            ->assertRedirect('/admin/contacts/new')
            ->assertSessionHasErrors([
                'name' => 'Enter the contact’s name.',
                'phone' => 'Enter a phone number using digits, such as (970) 555-0123.',
                'email' => 'Enter a valid email address, such as name@example.com.',
            ]);

        $this->assertSame(0, Contact::count());

        // On a request of its own, since asserting session errors leaves none for the next one
        $this->actingAs($this->admin)
            ->from('/admin/contacts/new')
            ->followingRedirects()
            ->post('/admin/contacts', ['name' => '', 'address' => '123 Example St'])
            ->assertSee('Enter the contact’s name.')
            ->assertSee('value="123 Example St"', false);
    }

    public function test_a_phone_number_already_on_a_contact_stops_and_names_them(): void
    {
        $jamie = $this->contact();

        $this->actingAs($this->admin)
            ->from('/admin/contacts/new')
            ->followingRedirects()
            ->post('/admin/contacts', ['name' => 'Pat Morgan', 'phone' => '970-555-0123'])
            ->assertSee('970-555-0123 is already Jamie Rivera’s phone number.')
            ->assertSee('<a href="' . route('admin.contacts.show', $jamie->id) . '">Open Jamie Rivera</a>', false)
            ->assertSee('Add as a separate contact anyway')
            ->assertSee('value="Pat Morgan"', false);

        $this->assertSame(1, Contact::count());
    }

    public function test_a_contact_can_be_added_separately_anyway(): void
    {
        $this->contact();

        $this->actingAs($this->admin)
            ->post('/admin/contacts', ['name' => 'Pat Morgan', 'email' => 'JAMIE@example.com', 'separate' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Contact::count());
        $this->assertSame('JAMIE@example.com', Contact::where('name', 'Pat Morgan')->sole()->primary(ContactDetail::TYPE_EMAIL)->value);
    }

    public function test_a_previous_email_is_named_as_previous(): void
    {
        $jamie = $this->contact();
        $jamie->removeDetail($jamie->primary(ContactDetail::TYPE_EMAIL));

        $this->actingAs($this->admin)
            ->from('/admin/contacts/new')
            ->followingRedirects()
            ->post('/admin/contacts', ['name' => 'Pat Morgan', 'email' => 'jamie@example.com'])
            ->assertSee('jamie@example.com was Jamie Rivera’s email.');
    }

    public function test_deleted_contacts_are_not_duplicates(): void
    {
        $this->contact()->delete();

        $this->actingAs($this->admin)
            ->post('/admin/contacts', ['name' => 'Pat Morgan', 'phone' => '(970) 555-0123'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('duplicates');

        $this->assertSame(1, Contact::count());
    }

    public function test_the_edit_page_shows_the_details_with_their_buttons(): void
    {
        $contact = $this->contact();
        $second = $contact->addDetail(ContactDetail::TYPE_PHONE, '(970) 555-0188');
        $contact->moveTo('123 Example St, Fort Collins, CO 80521');

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}/edit")
            ->assertOk()
            ->assertSee('<h1>Jamie Rivera</h1>', false)
            ->assertSee('value="Jamie Rivera"', false)
            ->assertSeeInOrder(['Phone Numbers', '(970) 555-0123', '· Main', '(970) 555-0188', 'Make Main', 'Add a phone number'], false)
            ->assertSee('action="' . route('admin.contacts.details.primary', [$contact->id, $second->id]) . '"', false)
            // The main one has no Make Main button
            ->assertDontSee('action="' . route('admin.contacts.details.primary', [$contact->id, $contact->primary(ContactDetail::TYPE_PHONE)->id]) . '"', false)
            ->assertSeeInOrder(['Address', '123 Example St, Fort Collins, CO 80521', 'New address', 'Change Address', 'The current address is kept as a previous address.'])
            ->assertSee('← Back to Jamie Rivera');
    }

    public function test_saves_the_name_and_notes(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}", ['name' => 'Jamie R. Rivera', 'notes' => 'Prefers texts after 5pm'])
            ->assertRedirect(route('admin.contacts.edit', $contact->id))
            ->assertSessionHas('status', 'Saved the name and notes.');

        $contact->refresh();
        $this->assertSame('Jamie R. Rivera', $contact->name);
        $this->assertSame('Prefers texts after 5pm', $contact->notes);
    }

    public function test_a_drop_off_site_is_marked_when_added_and_edited(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/contacts/new')
            // Unchecked sends 0
            ->assertSee('<input type="hidden" name="is_drop_off_site" value="0">', false)
            ->assertSee('name="is_drop_off_site" value="1" aria-describedby="is_drop_off_site-hint" >', false);

        $this->actingAs($this->admin)
            ->post('/admin/contacts', ['name' => 'North Drop-off Site', 'phone' => '(970) 555-0100', 'is_drop_off_site' => '1'])
            ->assertSessionHasNoErrors();

        $site = Contact::sole();
        $this->assertTrue($site->is_drop_off_site);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$site->id}")
            ->assertSeeInOrder(['<dt>Drop-off site</dt>', '<dd>Yes</dd>'], false);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$site->id}/edit")
            ->assertSee('name="is_drop_off_site" value="1" aria-describedby="is_drop_off_site-hint" checked>', false);

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$site->id}", ['name' => 'North Drop-off Site', 'notes' => '', 'is_drop_off_site' => '0'])
            ->assertRedirect(route('admin.contacts.edit', $site->id));

        $this->assertFalse($site->fresh()->is_drop_off_site);
        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$site->id}")
            ->assertDontSee('<dt>Drop-off site</dt>', false);
    }

    public function test_adds_a_phone_number_without_changing_the_main_one(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/details", ['type' => 'phone', 'phone' => '(970) 555-0188'])
            ->assertRedirect(route('admin.contacts.edit', $contact->id))
            ->assertSessionHas('status', 'Added (970) 555-0188.');

        $this->assertSame([['(970) 555-0123', true], ['(970) 555-0188', false]], $this->current($contact, ContactDetail::TYPE_PHONE));
    }

    public function test_the_first_email_becomes_the_main_one(): void
    {
        $contact = Contact::create(['name' => 'Pat Morgan']);

        $this->actingAs($this->admin)->post("/admin/contacts/{$contact->id}/details", ['type' => 'email', 'email' => 'pat@example.com']);

        $this->assertSame([['pat@example.com', true]], $this->current($contact, ContactDetail::TYPE_EMAIL));
    }

    public function test_adding_a_number_already_on_the_contact_changes_nothing(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/details", ['type' => 'phone', 'phone' => '970.555.0123'])
            ->assertSessionHas('status', '(970) 555-0123 is already on this contact.');

        $this->assertSame(2, $contact->details()->count());
    }

    public function test_adding_a_removed_number_puts_it_back(): void
    {
        $contact = $this->contact();
        $contact->addDetail(ContactDetail::TYPE_PHONE, '(970) 555-0188');
        $contact->removeDetail($contact->findDetail(ContactDetail::TYPE_PHONE, '9705550188'));

        $this->actingAs($this->admin)->post("/admin/contacts/{$contact->id}/details", ['type' => 'phone', 'phone' => '970-555-0188']);

        $this->assertSame([['(970) 555-0123', true], ['(970) 555-0188', false]], $this->current($contact, ContactDetail::TYPE_PHONE));
        $this->assertSame(3, $contact->details()->count());
    }

    public function test_an_invalid_detail_shows_an_error_by_its_own_box(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/details", ['type' => 'email', 'email' => 'not an email'])
            ->assertSessionHasErrors(['email' => 'Enter a valid email address, such as name@example.com.'])
            ->assertSessionDoesntHaveErrors(['phone', 'address']);

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/details", ['type' => 'phone', 'phone' => ''])
            ->assertSessionHasErrors(['phone' => 'Enter the phone number to add.']);
    }

    public function test_changing_the_address_keeps_the_previous_one(): void
    {
        $contact = $this->contact();
        $old = $contact->moveTo('123 Example St, Fort Collins, CO 80521');

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/details", ['type' => 'address', 'address' => '456 Sample Ave, Fort Collins, CO 80524'])
            ->assertSessionHas('status', 'Changed the address. The previous one is kept as a previous address.');

        $this->assertSame('456 Sample Ave, Fort Collins, CO 80524', $contact->address()->value);
        $this->assertTrue($old->fresh()->isRemoved());
    }

    public function test_makes_a_phone_number_the_main_one(): void
    {
        $contact = $this->contact();
        $second = $contact->addDetail(ContactDetail::TYPE_PHONE, '(970) 555-0188');

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/details/{$second->id}/primary")
            ->assertSessionHas('status', '(970) 555-0188 is now the main phone number.');

        $this->assertSame([['(970) 555-0123', false], ['(970) 555-0188', true]], $this->current($contact, ContactDetail::TYPE_PHONE));
    }

    public function test_removing_the_main_one_makes_another_main_and_keeps_it(): void
    {
        $contact = $this->contact();
        $main = $contact->primary(ContactDetail::TYPE_PHONE);
        $contact->addDetail(ContactDetail::TYPE_PHONE, '(970) 555-0188');

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/details/{$main->id}/remove")
            ->assertSessionHas('status', 'Removed (970) 555-0123. It’s kept as a previous phone number, so applications still match it.');

        $this->assertSame([['(970) 555-0188', true]], $this->current($contact, ContactDetail::TYPE_PHONE));
        $this->assertTrue($main->fresh()->isRemoved());

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}")
            ->assertSeeInOrder(['Previous phone number', '(970) 555-0123']);
    }

    public function test_removes_the_address(): void
    {
        $contact = $this->contact();
        $address = $contact->moveTo('123 Example St, Fort Collins, CO 80521');

        $this->actingAs($this->admin)->post("/admin/contacts/{$contact->id}/details/{$address->id}/remove");

        $this->assertNull($contact->address());
        $this->assertTrue($address->fresh()->isRemoved());
    }

    public function test_another_contacts_detail_is_not_found(): void
    {
        $contact = $this->contact();
        $other = $this->contact('Alex Moreno', '(970) 555-0199', 'alex@example.com');
        $theirs = $other->primary(ContactDetail::TYPE_PHONE);

        $this->actingAs($this->admin)->post("/admin/contacts/{$contact->id}/details/{$theirs->id}/primary")->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/contacts/{$contact->id}/details/{$theirs->id}/remove")->assertNotFound();
        $this->assertFalse($theirs->fresh()->isRemoved());
    }

    public function test_a_removed_detail_cannot_be_made_main(): void
    {
        $contact = $this->contact();
        $old = $contact->primary(ContactDetail::TYPE_PHONE);
        $contact->removeDetail($old);

        $this->actingAs($this->admin)->post("/admin/contacts/{$contact->id}/details/{$old->id}/primary")->assertNotFound();
    }

    public function test_deletes_a_contact_without_applications(): void
    {
        $contact = $this->contact('Test Contact');
        $contact->moveTo('123 Example St, Fort Collins, CO 80521');

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}/edit")
            ->assertSeeInOrder(['Address', '<h2 id="delete">Delete Contact</h2>', 'action="' . route('admin.contacts.destroy', $contact->id) . '"', 'Delete Test Contact', 'For contacts added by mistake or as a test.', 'Delete Contact</button>', '← Back to Test Contact'], false)
            ->assertSee('<input type="checkbox" name="confirm" value="1" aria-describedby="confirm-hint"', false);

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/delete", ['confirm' => '1'])
            ->assertRedirect(route('admin.contacts'))
            ->assertSessionHas('status', 'Deleted Test Contact.');

        // Only soft-deleted, with their details kept
        $this->assertSoftDeleted($contact);
        $this->assertSame(3, $contact->details()->count());

        $this->actingAs($this->admin)->get('/admin/contacts')->assertSee('<p class="status" role="status">Deleted Test Contact.</p>', false);
        $this->actingAs($this->admin)->get('/admin/contacts?show=all')->assertDontSee('Test Contact');
        $this->actingAs($this->admin)->get("/admin/contacts/{$contact->id}")->assertNotFound();
        $this->actingAs($this->admin)->get("/admin/contacts/{$contact->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/contacts/{$contact->id}/delete", ['confirm' => '1'])->assertNotFound();
    }

    public function test_deleting_needs_the_box_checked(): void
    {
        $contact = $this->contact();

        $this->actingAs($this->admin)
            ->from("/admin/contacts/{$contact->id}/edit")
            ->post("/admin/contacts/{$contact->id}/delete")
            ->assertRedirect(route('admin.contacts.edit', $contact->id) . '#delete')
            ->assertSessionHasErrors(['confirm' => 'Check the box to confirm, then press Delete Contact.']);

        $this->assertNotSoftDeleted($contact);

        // On a request of its own, since asserting session errors leaves none for the next one
        $response = $this->actingAs($this->admin)
            ->from("/admin/contacts/{$contact->id}/edit")
            ->followingRedirects()
            ->post("/admin/contacts/{$contact->id}/delete")
            ->assertSee('<p class="field-error" id="confirm-error">Check the box to confirm, then press Delete Contact.</p>', false);

        // Blade keeps the spaces on both sides of @error, so allow any spacing
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="confirm" value="1" aria-describedby="confirm-hint confirm-error"\s+aria-invalid="true"\s*>/', $response->getContent());
    }

    public function test_a_contact_with_applications_cannot_be_deleted(): void
    {
        $contact = $this->contact();
        $this->application($contact);

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}/edit")
            ->assertSeeInOrder(['<h2 id="delete">Delete Contact</h2>', 'Jamie Rivera has an application, so they can’t be deleted. If this is a duplicate, merge it into the other contact from Shares Details With.'], false)
            ->assertDontSee('action="' . route('admin.contacts.destroy', $contact->id) . '"', false);

        // Even when the page was opened before the application was linked
        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/delete", ['confirm' => '1'])
            ->assertRedirect(route('admin.contacts.edit', $contact->id) . '#delete')
            ->assertSessionHasErrors(['confirm' => 'Jamie Rivera has an application, so they can’t be deleted. If this is a duplicate, merge it into the other contact from Shares Details With.']);

        $this->assertNotSoftDeleted($contact);
    }

    public function test_deleted_applications_still_keep_a_contact(): void
    {
        $contact = $this->contact();
        $this->application($contact);
        $this->application($contact)->delete();

        $this->actingAs($this->admin)
            ->get("/admin/contacts/{$contact->id}/edit")
            ->assertSee('Jamie Rivera has 2 applications, so they can’t be deleted.');

        $this->actingAs($this->admin)
            ->post("/admin/contacts/{$contact->id}/delete", ['confirm' => '1'])
            ->assertSessionHasErrors('confirm');

        $this->assertNotSoftDeleted($contact);
    }
}
