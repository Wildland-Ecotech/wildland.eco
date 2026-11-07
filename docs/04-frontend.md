# Frontend

## Stack

- **Templates:** Blade, rendered on the server. Every public page works without JavaScript.
- **Styling:** SCSS compiled by Vite with Dart Sass (`sass`). No CSS framework.
- **JavaScript:** Small vanilla ES modules that enhance server-rendered markup. No frontend framework.
- **Build:** `npm run build` (or `npm run dev` for hot reloading) in `laravel/`, run on the host. Node is only used to build assets, never at runtime in production.

## Structure

All paths are relative to `laravel/`.

| Path | Purpose |
|------|---------|
| `resources/views/components/layout.blade.php` | Page shell (`<x-layout title="...">`): head, menu overlay, top nav |
| `resources/views/components/*.blade.php` | Shared components, used as `<x-top-nav />`, `<x-footer />`, etc. |
| `resources/views/pages/*.blade.php` | One view per page |
| `resources/scss/app.scss` | Sass entry point; `@use`s every partial |
| `resources/scss/_fonts.scss`, `_animations.scss`, `_base.scss` | Font faces, shared keyframes, global element and button styles |
| `resources/scss/_typography.scss` | Text role mixins (Lead, Detail, Fine print) from the [Style Guide](08-style-guide.md) |
| `resources/scss/components/`, `resources/scss/pages/` | One partial per component or page |
| `resources/js/app.js` | JavaScript entry point; initializes each enhancement |
| `resources/js/*.js` | One module per enhancement, attached to markup through `data-*` attributes |
| `resources/fonts/`, `resources/images/` | Fonts and design images, processed by Vite |
| `public/content/` | Photos used on pages, served as-is |
| `public/favicon.png` | Favicon |

## Conventions

- **Components:** Blade anonymous components in `resources/views/components/`. Props are declared with `@props` and defaults; kebab-case attributes map to camelCase props (`:show-donate="false"` sets `$showDonate`). A leading `:` passes a PHP expression instead of a string.
- **Style scoping:** Sass does not scope styles to a component. Each component or page partial nests all of its rules under its root class (for example, `.menu-overlay .links`), so generic class names cannot leak between components.
- **Keyframes are global.** Give each one a distinct name. Shared animations live in `_animations.scss`.
- **JavaScript hooks** use `data-*` attributes (`data-menu-toggle`, `data-modal-open`), not styling classes, so markup and styles can change without breaking scripts. Each module does nothing when its elements are not on the page.
- **Continuous animations pause while off screen.** Anything that animates every frame uses `watchOnScreen()` from `on-screen.js` (an `IntersectionObserver` that reports elements 200px before they scroll into view) to stop while nobody can see it, so it never competes with scrolling. Elements that are not displayed, such as inside a closed modal, count as off screen.
- **Progressive enhancement:** build the no-JavaScript version first, then enhance it.
  - Elements that only make sense with JavaScript start with the `hidden` attribute.
  - If CSS gives such an element a `display` value, restate `&[hidden] { display: none; }`.
  - Links fall back to real pages or anchors (`/menu`, `#high-park`).
- **Images in Sass** use paths relative to the partial (`url('../../images/bg-nature.png')`); Vite rewrites them at build time.

## Design System

Fonts, colors, text roles, layout, buttons and links, motion, and writing are in the [Style Guide](08-style-guide.md). Text sizes and colors come from the roles in `resources/scss/_typography.scss`; base element styles and the shared `.button` (with `.full`, `.dark`, and `.wide` modifiers) are in `_base.scss`.

## Pages

### Home (`/`) — `pages/home.blade.php`

- Illustrated mountain background with a firefly forest transition
- Banner asking visitors to get involved ("Get involved and help us fight back against wildfires"), with a Donate link to `/donate` and a Volunteer link that emails volunteer@wildland.eco, separated by a vertical line, and a rotating rainbow border (`rainbow-border.js`), drawn only while the banner is on screen; without JavaScript the border cycles colors in CSS
- The project modals link to Instagram, LinkedIn, and Facebook
- Three sections: Our Mission, What We Do (programs accordion), Our Projects (project cards that open modals)
- The Collecting Leaves program ends with a plain text link to `/leaves` ("Learn more about leaf collection"), styled as a link rather than a button so it isn't mistaken for the buttons that open sections
- Footer, with a Donate link

### Donate (`/donate`) — `pages/donate.blade.php`

- Built on the Content Page component; a plain HTML form validated on the server (see [Routes & Controllers](03-routes-and-controllers.md#donations))
- Frequency toggle (One-Time / Monthly / Annual) and tier cards: `[5, 20, 50]` one-time, `[20, 50, 100]` monthly, `[100, 1000, 5000]` annual. The amounts are defined in the `$frequencies` array at the top of the view.
- No frequency is chosen when the page loads, and only the frequency toggle shows, so the opening card stays short for visitors who want to read about our work first. Choosing a frequency reveals everything else (`.donate-details`): the tier cards, custom amount, name, email, and button. After a validation error, the chosen frequency stays checked, so the details stay open.
- Frequencies and tiers are visually hidden radio inputs styled through their labels. The frequency radios come first in the form, so a sibling selector (`~`) shows `.donate-details` once any frequency is checked, and the tier set for the checked frequency inside it, without JavaScript. Each frequency's tiers use their own field name (`amount_one_time`, `amount_monthly`, `amount_annual`).
- The custom amount, name, and email fields share one width (`$field-width` in `_donate.scss`).
- Custom amount field. `donate.js` filters input as it is typed (digits, one decimal point, two decimal places, $9,999 maximum), clears selections when the frequency changes, shows the amount on the button ("Donate $20/month"), and disables the button until an amount is chosen. Without JavaScript the button says "Donate", and the server checks the amount.
- Name and email fields, both required. Validation errors appear in a red summary above the form and under each field, and every field keeps its input, including the chosen frequency and suggested amount. The summary ends with the submission's reference, which the visitor can quote if they email us (see [Form Submissions](03-routes-and-controllers.md#form-submissions)).
- Proof-of-work spam protection (`proof-of-work.js`), as on the leaf form. There is no hidden spam-trap field: browsers and password managers fill those in, which blocked real donors. Paying needs JavaScript anyway, for Stripe's own pages, so a `<noscript>` notice gives the email address to give another way.
- While online giving is unavailable (no Stripe key), a notice says so and the button stays disabled (`data-unavailable` on the form).
- Submitting sends the donor to Stripe Checkout. Every other page's footer links here; the donate and thank-you pages hide the footer's Donate link (`:show-donate="false"`) and link back home instead.
- Under the form, a tax note ("tax-deductible to the extent allowed by law", as on the receipt) with links to the privacy and refund policies
- "What You're Supporting" cards, impact stats, "Other Ways to Help", and a footer that links back home.

### Donation Thank-You (`/donate/thank-you/{reference}`) — `pages/donate-thank-you.blade.php`

- Where Stripe returns the donor after paying, in the same card style as the donate page
- Thanks the donor for their donation by amount and frequency ("monthly donation of $20"), never showing their name or email, since the URL is the only thing protecting the page
- Notes that bank payments can take a few business days
- For monthly and annual donations, a "Manage Your Recurring Donation" button links to the Stripe Customer Portal login (`STRIPE_PORTAL_LOGIN_URL`), where the donor signs in with an emailed link
- The donate page's tax note, with links to the privacy and refund policies
- A footer that links back home

### Leaves (`/leaves`) — `pages/leaves.blade.php`

- Built on the Content Page component
- Leads with the program's primary message, "Leave the Leaves": don't rake, and let yards grow back into meadows
- "Donate Your Leaves" for people who rake anyway, with Drop-Off (marked Preferred) and Pickup cards
- Leaf guidelines (paper bags and compostable yard waste only), and Privacy: contact details stay private (never sold, and shared only with the services that run the site), which details about the leaves may be shared for grants, research, and outreach, and a link to the full privacy policy
- "Apply to Donate Leaves" buttons after the "Donate Your Leaves" text (kept out of the opening card, whose message is not to rake) and after the Privacy section
- The opening card's text is Lead text (`.prose.lead`); everything below it is Detail text. Only section labels, headings, and buttons are centered.

### Apply to Donate Leaves (`/leaves/apply`) — `pages/leaves-apply.blade.php`

- Built on the Content Page component; a plain HTML form validated on the server (see [Routes & Controllers](03-routes-and-controllers.md#leaf-applications))
- Drop-off (selected by default, marked Preferred) or pickup, contact details, the address the leaves come from, an estimated bag count, and whether the bags are ready now. Donors never choose a pickup date or time; when the bags aren't ready, they give the soonest date they will be.
- The "when will they be ready" date is hidden with `:has()` while "Yes, they're ready now" is checked. Browsers without `:has()` always show it, and the server ignores it when the bags are ready.
- Validation errors appear in a red summary above the form and under each field, and every field keeps its input. The summary ends with the submission's reference (see [Form Submissions](03-routes-and-controllers.md#form-submissions)).
- Terms with a required "Yes, I agree" checkbox. Applicants confirm they're 18 or older, and the sharing terms link to What We Share in the privacy policy.
- Proof-of-work spam protection (`proof-of-work.js`, attached with `data-proof-of-work`): solving starts when the page loads, and if the form is submitted first, the button shows "Verifying…" until it finishes. The form needs JavaScript to submit: without it, a `<noscript>` notice gives the email address to apply by instead. The browser's `crypto.subtle` is only available over HTTPS and on `localhost`. A page restored from the back/forward cache reloads, since its challenge has already been used. A page open for more than 30 minutes fetches a fresh challenge and CSRF token before submitting, or when the visitor returns to the tab, from the form's `data-form-tokens-url` (see [Stale Forms](03-routes-and-controllers.md#stale-forms)).
- After applying, the page shows a thank-you message for drop-off or pickup instead of the form

### FAQ (`/faq`) — `pages/faq.blade.php`

- Built on the Content Page component
- Each question is a native `<details>` element with an `id` for linking (such as `/faq#get-involved`), so questions open and close without JavaScript and several can be open at once. A plus sign turns into a minus sign when a question is open.
- Answers use `.prose`

### Privacy Policy (`/privacy`) and Refund Policy (`/refunds`) — `pages/privacy.blade.php`, `pages/refunds.blade.php`

- Built on the Content Page component, with the `policy-page` class (`scss/pages/_policy.scss`): a heading card like the FAQ's, then the whole policy in one `.prose` column with an `h2` for each part. Each `h2` has an `id` for linking, such as `/refunds#canceling`, which the privacy policy links to.
- Linked from every page's footer, the donate and thank-you pages' tax notes, the leaf page's Privacy section and the application's terms, donation receipts, and the FAQ ("Can I get a refund?").
- The effective date in the heading card is set by hand whenever a policy changes, never generated from the deploy date.
- The refund policy shows a Manage Your Recurring Donation button, and mentions it in the text, only when `STRIPE_PORTAL_LOGIN_URL` is set.
- Both describe what the site and its services actually do. Change them in the same work as anything they describe: what the forms collect, the services that handle data (Stripe, Vultr, AhaSend, Proton Mail, Cloudflare), how long data and logs are kept (see [Logs](07-deployment.md#logs)), emails sent to donors, and how refunds work. Features such as abandoned checkout reminders, a mailing list, and digital volunteer waivers need the privacy policy updated before they go live.

### Menu (`/menu`) — `pages/menu.blade.php`

A full-page menu for visitors without JavaScript, with the same links and social media icons as the menu overlay.

### Sign In (`/login`) — `pages/login.blade.php`

- Built on the Content Page component, in an opening card like the error pages, with no footer; styles are in `_admin.scss`, shared with the admin pages
- A plain HTML form (email and password), validated on the server (see [Signing In](03-routes-and-controllers.md#signing-in)), with a proof of work solved in the browser like the leaf application form's (`data-proof-of-work`). Without JavaScript, a notice above the fields says "To protect against password guessing, signing in needs JavaScript. Please turn it on, then reload this page." (`.js-notice`)
- Field errors appear under each field; a wrong password, too many attempts, or a proof of work that didn't check out appears in a red box above the fields. The email address is kept after an error, the password never is.
- The email field uses `autocomplete="username"` and the password `current-password`, so password managers fill them in
- "Forgot your password?" points to contact@wildland.eco, since passwords are set with `users:admin`
- Not linked from any menu yet

### Admin Pages (`/admin`) — `pages/admin/`

Built on the Admin Page component, with styles in `_admin.scss`. Every admin page works without JavaScript and on a phone.

- **Contacts** (`/admin/contacts`, `pages/admin/contacts/index.blade.php`):
    - A message such as "Deleted Pat Morgan." at the top, after deleting a contact.
    - A Show menu (Needs follow-up, chosen by default, or All contacts), then a Search box with its Search button beside it, the same height (`.search-box`), which also applies the Show menu, and a hint under it: "A name, phone number, or email." A New Contact button sits on the right of the same row (`.page-actions`, like Abandoned Checkouts on Donations), after a Needs Review button with the count, such as "Needs Review (2)", shown only while any application needs review.
    - A line saying what is listed: for Needs follow-up, such as "8 contacts waiting for a pickup or drop-off, who needs to hear from us first at the top.", "Nobody is waiting for a pickup or drop-off.", or with a search, "Nobody waiting for a pickup or drop-off matches “taylor”. Show all contacts to search everyone."; for All contacts, such as "12 contacts, most recent application first." or "2 contacts matching “rivera”, most recent application first."
    - Needs follow-up: name (the link to the contact) with their group beside it as a label (`.badge`): Waiting on us in red, Ready in yellow, Contacted in green, or Later in gray, main phone number, Follow-up (why, such as "No word from us in 10 days"), bags, when the bags are ready ("Now" or a date such as "Nov 10"), when we last reached them (a date, or "Never"), and when they applied, for their first application in the follow-up order. On a phone, each is one line of name and why, then the label, such as "Sam Lee · No word from us in 10 days (Waiting on us)", which opens to show the rest and a View Contact button.
    - All contacts: name (the link to the contact, with a "Drop-off site" label for our drop-off sites), main phone number, main email, number of applications, and when they last applied. On a phone, each contact is one line of when they last applied, name, and phone number, such as "Nov 3 · Jamie Rivera · (970) 555-0123" (without the date for someone who hasn't applied), then any label, which opens to show the rest and a View Contact button.
- **Contact** (`/admin/contacts/{id}`, `pages/admin/contacts/show.blade.php`): the contact's name as the heading, then label and value lines: phone numbers and emails, one per line with the main one first, marked "(main)" only when there's more than one, with any label such as "(mobile)"; the current address; previous phone numbers, emails, or addresses, when there are any; "Drop-off site: Yes" for one of our drop-off sites; Follow-up, such as "Waiting on us · No word from us in 9 days", when they have a pending application; notes; and when they were added. An Edit Contact button follows the details (`.record-actions`). When other contacts share a phone number or email, **Shares Details With** comes next: each one's name (a link) and the shared values, with a "Merge Into Jamie Rivera" button (`.detail-list`), then a hint saying what merging does (`.detail-hint`). Then **Timeline**, with a Log Activity button under the heading, and the entries newest first, or "Nothing logged yet." (`.timeline`): each with its time, such as "Nov 5, 7:42 PM", what happened in bold, such as "Text from us · Rachel", "Text from Jamie Rivera", "Visit · Rachel", or "Pickup: 28 bags · Rachel" (the admin's name for what we did, never for what came from them), then what was said, keeping its line breaks, and a link to the application it was about. Applications ("Applied for a pickup: 30 bags, ready now", linking to it) and form errors ("Form error: leaf application, rejected by the form…", linking to the submission, then its follow-up) are added automatically. A line runs down the left: green for ours, white for what came from them (`.incoming`), and faint for what was added automatically (`.automatic`); the words say which, since color alone doesn't carry meaning. Then **Applications**, newest first: ID (the link to the application), when applied, type, bags, when they're ready, and status (Pending or Complete), or "No applications yet."; on a phone, one line of date, type, and bags, such as "Oct 12 · Drop-off · 12 bags". A message such as "Added Pat Morgan." or "Logged a text." shows at the top. When they have form submissions that didn't go through, **Form Errors** follows, newest first: reference (the link to the submission), when submitted, form, what happened, and Follow-up with its icon, as on Error Logs; on a phone, one line starting with the icon, then date, form, and what happened. A link at the end goes back to Contacts.
- **Needs Review** (`/admin/contacts/review`, `pages/admin/contacts/review.blade.php`): a line such as "2 applications need review, oldest first." or "Nothing needs review.", then the list: ID (the link to the application), when applied, name, the contact (a link) or "Not linked", and why, such as "Phone number also on another contact". On a phone, each is one line of date, name, and why, which opens to show the rest and a View Application button. A link at the end goes back to Contacts.
- **New Contact** (`/admin/contacts/new`, `pages/admin/contacts/create.blade.php`): Name, Notes, and Drop-off site, then Phone, Email, and Address, all but the name marked "(optional)", with a hint under Notes: "Standing information, such as “prefers texts after 5pm”." Drop-off site is a labeled group with one checkbox, "This is one of our drop-off sites", and the hint "Shown on the map with the applications waiting for a pickup. Its address stays private." (`.record-form`, up to 640px wide; the name, notes, and drop-off site fields are shared with the edit page in `contacts/name-and-notes.blade.php`). Add Contact and a Cancel link sit in a row (`.form-actions`). When the phone number or email is already someone's, a notice names them with a link, and an "Add as a separate contact anyway" checkbox appears above the button.
- **Sign-Ins** (`/admin/sign-ins`, `pages/admin/sign-ins.blade.php`), with Error Logs current in the menu: a line saying what's listed, that passwords are never recorded, and that attempts are kept indefinitely; the same red warning as Error Logs, without its button; a filter form with Show (All attempts or Failed only) and Search, "An email or IP address."; a line such as "6 sign-in attempts, newest first."; then the list: when, email, outcome (a green check or red cross beside its words, such as "Wrong password"), IP address (a link to every attempt from it), and browser, such as "Firefox on Linux". On a phone, each attempt is one line starting with the same icon, then when, email, and outcome, which opens to show the IP address and browser. A link at the end goes back to Error Logs.
- **Log Activity** (`/admin/contacts/{id}/activities/new`, `pages/admin/contacts/log.blade.php`): "Log Activity" as the heading, any message such as "Logged a call.", and the contact's name and main phone number, such as "Jamie Rivera · (970) 555-0123". Then the form (`.record-form.log-form`): What happened (radio buttons for Text, chosen by default, Call, Visit, Note, Pickup, and Drop-off); Who reached out ("We did", chosen by default, or "They did"; `.direction-field`, hidden unless Text or Call is chosen); When (a date and time field, now by default); "What was said, or the note (optional)"; Bags (`.bags-field`, shown only for Pickup and Drop-off); and, when the contact has a pending application, Application (a menu of them, such as "42 · Pickup · applied Nov 4", newest first and chosen by default, then None) with the hint "A pickup or drop-off marks it complete, taking it off the map and the follow-up list.", and "Follow up on (optional)" with the hint "Moves them down the follow-up list until then, such as when they asked us to check back next week." Fields are hidden with `:has()`, so browsers without it show every field. Save, Save and Add Another, and a Cancel link back to the contact sit in a row (`.form-actions`).
- **Edit contact** (`/admin/contacts/{id}/edit`, `pages/admin/contacts/edit.blade.php`): the contact's name as the heading and any message, then the Name, Notes, and Drop-off site fields with Save. Then **Phone Numbers**, **Emails**, and **Address**, each listing its current details one per line (`.detail-list`), marked "· Main" on the main one, with Make Main (on the others) and Remove buttons on the right, then a box with its button beside it, the same height (`.field-with-button`): "Add a phone number", "Add an email", and "New address" with Change Address (or "Add an address" with Add Address), with the hint "The current address is kept as a previous address." Then **Delete Contact** (`#delete`): for a contact with no applications, a Confirm box, "Delete Pat Morgan", with the hint "For contacts added by mistake or as a test. They disappear from Contacts, the map, and matching new applications, along with their timeline.", then a Delete Contact button; for one with applications, only why they can't be deleted, such as "Jamie Rivera has an application, so they can’t be deleted. If this is a duplicate, merge it into the other contact from Shares Details With." (`.detail-hint`). A link at the end goes back to the contact.
- **Dashboard** (`/admin`, `pages/admin/home.blade.php`):
    - Who is signed in, by name and email address.
    - A red warning while people are waiting to hear from us or have bags ready (`.notice.warning`, from `App\Reports\FollowUpNotice`), such as "3 people are waiting to hear from us, and 2 more have bags ready." or "2 people have bags ready and haven’t heard from us yet.", with a Follow Up button on the right that opens Contacts; on a phone the button sits under the words. It's left out when nobody is waiting or ready, and only the dashboard shows it.
    - Under it, while attempts to sign in failed in the last day, another red warning, such as "5 failed sign-in attempts in the last day, 3 from 203.0.113.77.", with a Review Sign-Ins button that opens the failed attempts on Sign-Ins.
    - **Recent Activity**, "The last 90 days.", and four counts side by side: donations received, leaf applications, unresolved errors, and applications to review. Each is an open number in large white type, with its label and a short gradient bar under it (the bar grows on hover), separated from the next by a thin line, with no box around it. Each links to its list for the last 90 days; applications to review link to Needs Review. On a phone they're two by two, smaller, each row starting without a line.
    - **Donation Statistics**: a line saying what is counted, a Download CSV link under it, a notice when Stripe's fee isn't recorded yet for some donations, then a list for each grouping under its own heading (Month over month, By quarter, By year, All time): period, donations, amount, refunded, Stripe fees, and net. On a phone, each total is one line of period, donations, and amount, such as "Jan 2026 · 12 donations · $1,250.00", which opens to show the rest.
- **Donations** (`/admin/donations`, `pages/admin/donations/index.blade.php`):
    - A filter form above the list: a Period menu (Last 30 days, Last 90 days, Last 365 days, All time), One-time, Monthly, and Annual checkboxes (none checked shows every type), an Include group with "Pending and failed payments", a Show button, and a Download CSV link for the same filters. An Abandoned Checkouts button sits on the right of the filters' first row, level with the Period menu (`.page-actions`, with the filters in a `.filter-group` so they wrap beside it); on a phone it spans the screen above the filters. Download CSV links on every admin page are bold, green, and underlined (`.download`), next to the Show button or on their own row.
    - A line saying what is listed, such as "12 donations received in the last 30 days."
    - The list itself (Admin Records): ID (the link to the donation), date, donor, type, amount, and status. On a phone, each payment is one line of date, donor, and amount, such as "Oct 18 · Jamie Rivera · $50.00", which opens to show the rest and a View Donation button.
- **Donation** (`/admin/donations/{id}`, `pages/admin/donations/show.blade.php`): every detail `donations:view` shows, as label and value lines, with a notice when Stripe's fee isn't recorded yet or the donation was deleted, and a link back to Donations.
- **Abandoned Checkouts** (`/admin/donations/abandoned`, `pages/admin/donations/abandoned.blade.php`): a line explaining what an abandoned checkout is, the Period menu with Show and Download CSV, a line such as "3 abandoned checkouts in the last 30 days; 1 donated later.", and the list: when started, donor, type, amount, and whether they donated later. On a phone, each checkout is one line of date, donor, and amount, which opens to show the rest. Checkouts have no page of their own, so the list has no links. Donations stays highlighted in the admin links, and a link at the end goes back to Donations.
- **Map** (`/admin/map`, `pages/admin/map.blade.php`):
    - Filters (`.filters`): a Show menu (Needs scheduling, chosen by default, or Everything), and a Search box with its button and the hint "A name, phone number, or email, to see where they are and their closest drop-off site." On Needs scheduling, a Start a Trailer Load button on the right of the row (`.page-actions`). An opened trailer load has no filters.
    - **Trailer Loads**, on every view but an opened load, before the map: the planned loads soonest first, with date (the link), stops, and bags such as "70 of 80"; on a phone, one line such as "Sun, Nov 8 · 70 of 80 bags". Or "No trailer loads are planned." Under it, done and canceled loads folded under "Past loads (4)" (`.past-loads`), with their status in gray.
    - A line saying what's shown, such as "6 pickups need scheduling, 245 bags in all: 4 on the map, 2 still need a location. 1 more is in a planned load.", "Every pickup is in a planned trailer load.", "Planned · 3 stops", "4 places on the map: 1 pickup waiting, 2 other contacts, and 1 drop-off site.", or "1 contact matching “rivera”."
    - The map (`.map-view`, 560px tall, the page's width): a dot for each point (`.map-dot`, printed in color), red, yellow, or green for pickups by when the bags are ready, blue for other contacts, gray with a dark outline for drop-off sites, and, after a preview, purple for the pasted locations not saved yet (their popup says "Not saved yet" and the address). Pickups in a planned load are ringed in black on Everything, and a load's stops, or the pickups chosen for a new load, are larger, ringed, and numbered (`.marked`). Clicking a dot opens its name, linking to the contact, and a line such as "Ready now · 40 bags" or the address. It fits every dot, zooms in on one, and starts over Fort Collins with none. Scrolling the page doesn't zoom it. Without JavaScript it says the map needs it.
    - A legend under it naming each color with its count (`.map-legend`), such as "Ready now or within a week (2)", since color alone doesn't carry meaning.
    - A new trailer load (`.load-builder`, left out when printing): "New Trailer Load", the hint "Click pickups on the map, or check them in the list, to add them. Click again to take one out.", any error about the stops, then Date, Capacity (bags), and the progress bar in a row (`.load-builder-fields`), then Save Load and a Cancel link. While choosing, clicking a dot adds or removes it instead of opening its popup, and hovering names it. The pickups list gets a box at the start of each row.
    - The progress bar (`.load-bar`): a track that fills with the chosen bags against the capacity, green, then yellow from 90%, then red over the capacity, with words such as "3 stops · 70 of 80 bags · 10 bags of room", "Full", or "15 over", since color alone doesn't carry meaning.
    - A trailer load, to follow on a phone: the heading "Trailer Load: Sun, Nov 8", the progress bar, Edit Load and Mark Done buttons under it for a planned load (`.load-actions`), then **Stops** (`.load-stops`), for a planned load with "In your route’s order. Move a stop up or down to change it.": on wider screens a table (`.stop-table`) of stop, name (a link), bags, address, phone (a link that dials it), notes, and Up and Down buttons (`.stop-moves`, labeled "Move Dana Cruz up" for screen readers; the first stop has no Up, the last no Down); on phones, a card for each stop with everything shown (`.stop-cards`): the stop number in a white circle, the name, and bags on the right, then the address, the phone number to tap, the notes with their line breaks, and Up and Down. A done load adds a Pickup column, and a bold line on each card (`.stop-pickup`): "Picked up 28 bags", "Picked up separately", or "Not picked up". For a planned load, **Cancel This Load** follows: a Confirm box, "Cancel the Sun, Nov 8 load", the hint "Its stops go back to Needs scheduling. The load is kept, marked Canceled.", and Cancel Load. Then "← Back to Map".
    - **Mark Load Done** (`/admin/map/loads/{id}/done`, `pages/admin/loads/done.blade.php`), with Map current in the menu: "Sun, Nov 8 load · 3 stops to log", then When (a date and time field, now by default) and **Pickups**: a table that stays a table on phones (`.done-table`) of a box for each stop (checked to start with), the stop's number and name (the box's label) with its address under it, and a bags field filled with the application's estimate, "estimated 25" under it, and any error under that. A stop not linked to a contact has no box or field, only "Not linked to a contact, so its pickup can’t be logged here. Link it, then log the pickup on the contact’s page. Until then it stays pending." Then the hint "Uncheck a stop that wasn’t picked up; it stays pending, ready for another load. Each checked stop is logged as a pickup on its contact’s timeline, which marks the application complete.", saying too when other stops were already picked up or deleted, and Mark Done with a Cancel link back to the load. A form sent back keeps what was checked and typed.
    - Editing a planned load: the same form as a new one, headed "Edit Load", with the hint "Click pickups on the map, or check them in the list, to add them or take them out. New stops go at the end; move them into place after saving.", the load's date and capacity, Save Changes, and a Cancel link back to the load; the list is "This load’s stops, and the pickups not in another load, soonest first.", with the load's stops checked.
    - Needs scheduling: **Pickups**, "Soonest first, with a running total of bags, for filling the trailer.", listing name (with a label: Ready now or This week in red, In 1 to 2 weeks in yellow, In 2+ weeks in green), when the bags are ready, bags, the running total, address (with "(not on the map yet)" when it has no location), and phone. Each links to the contact, or to the application before it's linked. On a phone, one line of name, when ready, and bags, then the label.
    - Search: for each contact found, their name as a heading, their address and phone number with a View contact link, then "Closest drop-off site" and "Next closest" with each site's distance, such as "North Drop-off Site · 2.0 mi", and "Straight-line distance; driving may be farther."; or "No location yet, so the distances aren’t known. Locate their address below."
    - **Locate Addresses** (left out when printing): how many addresses need a location, or "Every address has a location."; then three steps in one form (`.record-form`): "1. Run these in your local setup", with the commands (`.command`) and the hint "It asks for the addresses. Nothing is saved to a file."; "2. Copy these addresses, paste them into the terminal, then press Enter", a read-only box of the JSON, one address per line, in a monospace font (`.lines-box`), with the hint "Each address with its number, one per line. It starts once everything is pasted, then prints the locations it found." (the commands come first, so copying the addresses is the last thing before pasting them); and "3. Paste what it printed", a box for its JSON, with the hint "Everything from the { to the }. Addresses it found only at the street, or not at all, are listed in the preview to look up by hand. Nothing is saved until you check the preview.", and Preview. With every address located, the whole section is left out, unless a preview or an error is showing (then it says "Every address has a location." and the box is labeled "Paste what addresses:geocode printed"). After a preview, **Preview** lists each entry's number, address, location, and result with a ✓ or ✕ ("Will be saved", "Will replace its location", "No address with this number", "Address changed since it was copied", "Outside Colorado", "Missing its number or location", "Listed again later", then "Only the street was found" and "Not found by the geocoder"), "2 of 9 addresses can be saved. The others are left out.", and a Save 2 Locations button beside Preview.
    - Printing it gives the map and the list in black on white, without the navigation, filters, backgrounds, or Locate Addresses.
- **Leaf Applications** (`/admin/leaves`, `pages/admin/leaves/index.blade.php`):
    - A filter form like Donations': the Period menu, Drop-off and Pickup checkboxes (neither checked shows both), a Show button, and a Download CSV link.
    - A line saying what is listed, such as "3 pickup applications in the last 30 days."
    - The list: ID (the link to the application), when applied, name (linking to the person's contact, once linked), type, bags, when they're ready, and Follow-up: a label, then why, such as Waiting on us (red) "No word from us in 9 days", Ready (yellow), Contacted (green), Later (gray), Complete (gray), or Not linked (yellow) "Link it to a contact". On a phone, each application is one line of date, name, type, and bags, such as "Oct 18 · Jamie Rivera · Drop-off · 12 bags", then the label, which opens to show the ID, when the bags are ready, the follow-up, and a View Application button.
- **Application** (`/admin/leaves/{id}`, `pages/admin/leaves/show.blade.php`): any message, then when it was applied, Follow-up, such as "Waiting on us · No word from us in 9 days", "Complete", or "Not linked · Link it to a contact" (not for a deleted application), and every other detail `leaves:view` shows, keeping the line breaks in the donor's questions, with a notice when the application was deleted. Then **Contact**: "Linked to Jamie Rivera." (a link) or "Not linked to a contact."; when it needs review, a notice such as "Needs review. Phone number also on another contact: Jamie Rivera. Mark it reviewed if nothing needs to change, or move it to the right contact below."; and, once reviewed, who reviewed it and when. Under it, one under another (`.review-actions`): Mark Reviewed, only while it needs review and is linked, and a menu with its button beside it, the same height (`.field-with-button`): "Move this application to" with Move, or "Link this application to" with Link, offering each contact that shares its email or phone number, such as "Jamie Rivera · (970) 555-0123", and "A new contact". A link at the end goes back to Leaf Applications.
- **Error Logs** (`/admin/errors`, `pages/admin/errors/index.blade.php`):
    - A line explaining what the log holds.
    - While attempts to sign in failed in the last day, a red warning such as "5 failed sign-in attempts in the last day, 3 from 203.0.113.77." with a Review Sign-Ins button, as on the dashboard.
    - A filter form: a Sign-Ins button on the right (`.page-actions`, above the filters on a phone), the Period menu, Donation and Leaf application checkboxes (neither checked shows both), an Include group of Submitted later and Resolved by an admin (both checked by default), Spam and tests, and Submissions that succeeded, a Show button, and Download CSV.
    - A line such as "2 submissions that did not succeed in the last 30 days.", after any message from closing errors.
    - The list, with a checkbox on each row: reference (the link to the submission), when submitted, form, what happened, name (or email address without one; linking to the person's contact when one matches), and Follow-up. The follow-up is a green check with "Submitted 4 minutes later" linking to the application or donation made, a green check with "Resolved by (admin)", a red cross with "No record" (including a donor who left the payment page and never paid), or a gray dash with "Marked as a test" or "Marked as spam".
    - On a phone, each submission is one line starting with the same icon, then date, name, and what happened, which opens to show the reference, the form, the follow-up, and a View Submission button. Its checkbox sits beside the line.
    - Under the list, "Mark selected as" with a Spam, Test, or Resolved menu and a Mark button, after any error from it.
- **Submission** (`/admin/errors/{reference}`, `pages/admin/errors/show.blade.php`): every detail `submissions:list` shows for one submission, including the follow-up with its icon and link, a Contact line after the name linking to the person's contact (a dash when none matches), the errors the visitor saw, and their browser. Below them, either the Close this error form (radio buttons for "Resolved: we followed up" (chosen by default), Test, and Spam, an optional note, "Closed errors aren't counted on the dashboard.", and a Close Error button), or how it was closed, such as "Marked as a test by (admin) on (time): “(note)”", with a Reopen button. Then a link back to Error Logs.

## Components

### Layout (`layout`)

Page shell used by every page: `<head>` with meta tags, favicon, and Vite assets; the menu overlay; the top nav; then the page content (`$slot`). The `title` prop sets the page title.

### Top Nav (`top-nav`)

Fixed bar with the text wordmark "Wildland **Ecotech**" and the menu button.

### Menu Button (`menu-button`)

A 44px square link to `/menu` with a three-bar icon drawn as an inline SVG, centered in the button; a text character such as ≡ sits differently in each font. With JavaScript (`menu.js`), it toggles the menu overlay instead and keeps `aria-expanded` in sync.

### Menu Overlay (`menu-overlay`)

Full-screen blurred overlay with links to Home, Leaf Collection (`/leaves`, the page visitors from our flyers are looking for), FAQ (`/faq`), Volunteer (an email to volunteer@wildland.eco until there's a volunteers page), Contact Us, and Donate (`/donate`), the filled main-action button. Below the links, social media icons (`social-links`), left-aligned with the links. It scrolls when it's taller than the window. It repeats the top nav layout with an invisible wordmark, so its close button sits exactly where the menu button is. Escape or following a link closes it.

### Footer (`footer`)

Gradient footer with decorative bubbles, links to Instagram, LinkedIn, and Facebook under "Follow our progress", the organization's EIN, and links to the privacy and refund policies. Props with defaults:

- `color` (default: `'green'`)
- `heading` (default: `'Want to make a difference?'`)
- `title` (default: `'Help us restore wildlands for future generations.'`)
- `buttonText` / `buttonHref` (default: `'Get In Touch'` / `mailto:contact@wildland.eco`)
- `showDonate` (default: `true`)
- `showSocials` (default: `true`)

### Content Page (`content-page`)

Shell for standalone content pages such as `/donate`: the mountain and firefly backgrounds (the fireflies image fades in and out at its edges with a mask, as on the home page, and the backgrounds are cut off at the bottom of the page, so they never extend past the footer of a short page), a centered content column wrapped in Disappearing Content, and the page's footer in a `footer` slot. Pages add their own root class for their styles (`<x-content-page class="donate-page">`). `_content-page.scss` also provides `.section-divider`, the gradient line between sections, and `.prose`, a left-aligned 680px text column under centered headings. Text in `.prose` is Detail text, or Lead text with `.prose.lead` (see [Text Roles](08-style-guide.md#text-roles)).

### Disappearing Content (`disappearing-content`)

Wraps page content so it fades out as it scrolls under the top nav. `disappearing-content.js` recalculates a mask image on scroll and resize. The fade is positioned from the top of the content, so it stays correct when something below changes height (opening a `<details>` element, showing a form field) without being recalculated, and never flickers.

### Social Links (`social-links`) and Social Icon (`social-icon`)

`social-links` is a row of icon links to Instagram, LinkedIn, and Facebook, in the menu overlay and on `/menu`; each link has an `aria-label`, since it has no text. `social-icon` draws one outlined icon in the current text color (`<x-social-icon name="facebook" />`; names: `instagram`, `linkedin`, `facebook`), and is also used in the project modals' buttons.

### Error Page (`error-page`) and Error Pages

Laravel renders `resources/views/errors/<status>.blade.php` for an error, or `4xx` / `5xx` for any status without a page of its own. Laravel ships its own pages for 401, 402, 403, 404, 419, 429, 500, and 503, which it uses before falling back to `4xx` or `5xx`, so each has one of ours: `401`, `402`, and `403` include `4xx`, and `500` includes `5xx` (`ErrorPagesTest` checks that none of Laravel's are left). Each is built on the `error-page` component, a content page with a card holding a label, a heading, what happened and what to do, and buttons (`_error-page.scss`). Every page says what went wrong and what to do next (see "Error messages" in the [Style Guide](08-style-guide.md#writing)):

| Page | Says |
|------|------|
| `404` | The link may be out of date or mistyped; links home and to donate |
| `419` | The page expired, so the form wasn't processed and nothing was charged; reload and submit again. Links back to the form. Rare, since forms refresh their tokens first (see [Stale Forms](03-routes-and-controllers.md#stale-forms)) |
| `429` | Too many requests from the connection; wait a minute |
| `503` | Maintenance mode (`php artisan down`); on servers, Caddy shows its own matching page instead |
| `4xx` | Any other client error, with its status code |
| `5xx` | A server error, which is logged; anyone donating should check for a receipt before trying again. Shown when `APP_DEBUG` is false |

### Admin Page (`admin-page`)

Shell for admin pages (`<x-admin-page title="Donations" current="admin.donations">`): a content page with a wide card, up to 1400px rather than other pages' 960px so lists have room, holding Sign Out in its top right corner (on its own line above the label on a phone), the "— Admin" label, the page's heading, and a row of links to the admin pages with the current one underlined (`aria-current="page"`): Dashboard, Contacts, Map, Leaf Applications, Donations, and Error Logs. The links are listed in the component; add one only once its page exists. The page's content goes in the slot, left-aligned.

### Admin Records (`admin-records`)

A list of records on an admin page, given headers and rows (`<x-admin-records :headers="..." :rows="..." :summary-columns="[1, 2, 4]" :numeric="[0, 4]" link-label="View Donation" />`):

- **Wider screens:** a table whose first column, such as the ID or reference, links to the record, in bold green; `numeric` columns line up on the right. Times are short, such as "Oct 18, 7:30 PM", since every admin time is Fort Collins time (`listTime()`).
- **Phones (under 760px):** one line per record, the row's `summary` words joined by " · ", inside a `<details>`. Opening it shows the other columns (those not in `summary-columns`) as label and value lines, and a button to the record.
- **Records without a page of their own,** such as totals, leave out `url`: the list then has no links or buttons.
- **Status cells:** a cell can be `['text' => ..., 'status' => ..., 'url' => ...]` instead of a string: the words after an icon in a colored circle, linking to `url` when it isn't null. The statuses are `success` (a green check), `failure` (a red cross), and `muted` (a gray dash). An icon is always beside words, since color alone doesn't carry meaning. A row's `status` and `statusLabel` start its phone line with the same icon, with the label read out by screen readers in its place.
- **Labels:** a row's optional `badge`, such as "Drop-off site" or a contact's follow-up group, shows as a small outlined label after the first column's link and at the end of the phone line (`.badge`). Its `badgeTone` sets the color: green without one, `urgent` (red, bold), `soon` (yellow), or `muted` (gray); the words say what it means, since color alone doesn't carry meaning. A cell `['badge' => ..., 'tone' => ..., 'text' => ...]` starts with a label, then its text, such as an application's follow-up.
- **Linked cells:** a cell `['text' => ..., 'url' => ...]` without a `status` is its text alone, linking to `url` when it isn't null, such as the contact an application belongs to. Links in cells, in a record's values, and in summary lines are green and underlined.
- **Choosing records:** with `select-form` and `select-name` (such as `select-form="close-errors" select-name="references[]"`), each row gets a checkbox holding its `value`, in the table and beside its phone line. The checkboxes belong to the form with that ID elsewhere on the page through their `form` attribute, so the list needn't sit inside it. A row's checkbox appears twice, so the form's handler ignores duplicates.

Both are rendered, and CSS shows one, so the list works without JavaScript. Empty values show as a dash.

### Modal (`modal`)

Dialog opened by links to `#id`. Props: `id`, `title`, `closeHref` (where the close link points without JavaScript). Without JavaScript, CSS shows the modal while its id is the URL fragment (`:target`). With JavaScript, `modal.js` opens and closes it with the `is-open` and `is-closed` classes without changing the URL, closes it on Escape or a backdrop click, and moves focus into the modal and back.

### Blob (`blob`) and Blob Shapes (`blob-shapes`)

Image clipped to an organic shape, with two gradient layers rotating behind it. Prop: `type` (shape 1–10); when set, the animation never shrinks below that shape. The background layers' shapes, speeds, and directions are picked randomly on each request.

`blob-shapes` holds the 10 SVG clip-path definitions and is rendered once per page by the first blob (`@once`). Without JavaScript, each blob keeps its static shape. With JavaScript, `blob.js` reads the outlines from those definitions, samples them into radii around the center, and morphs between random shapes. While a blob is off screen or not displayed (such as inside a closed modal), its morphing stops and its spinning background layers pause (`.is-paused`), and the morph resumes where it left off.

### Accordion

The programs accordion on the home page is markup rather than a component: `<details>` elements sharing `name="programs"`, so only one section is open at a time without JavaScript. `accordion.js` keeps the open section from closing when clicked again, hides the "tap to learn more" hint after the first click, scrolls the opened section into view, and closes other sections in browsers without exclusive `<details>` support.

## Known Issues and Notes

- `backdrop-filter: blur()` conflicts with DisappearingContent's mask. The donate section uses `rgba(0, 0, 0, 0.5)` without blur as a workaround.
- `backdrop-filter` makes `position: fixed` children position relative to the element instead of the window. The menu overlay pins its copy of the top nav to `top: 0; left: 0` for this reason.
- The favicon is still the Wetfish fish icon; it needs replacing with a Wildland Ecotech logo.
- Blob morphing uses polar coordinate sampling, which can produce slightly irregular shapes depending on the source paths. This is cosmetic and intentional.
