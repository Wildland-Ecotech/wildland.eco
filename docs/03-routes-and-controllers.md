# Routes & Controllers

## Current Routes

| Method | URI | Name | Handler | Notes |
|--------|-----|------|---------|-------|
| GET | `/` | `home` | `Route::view` → `pages.home` | Home page |
| GET | `/menu` | `menu` | `Route::view` → `pages.menu` | Full-page menu for visitors without JavaScript |
| GET | `/donate` | `donate` | `DonationController@create` → `pages.donate` | Donate page, linked from the footer of every other page |
| POST | `/donate` | `donate.store` | `DonationController@store` | Records a checkout and redirects to the payment gateway's page |
| GET | `/donate/thank-you/{checkout:reference}` | `donate.thank-you` | `DonationController@thankYou` → `pages.donate-thank-you` | Where the gateway returns the donor after paying |
| POST | `/webhooks/stripe` | `webhooks.stripe` | `StripeWebhookController` | Receives Stripe's webhooks; see [Stripe Webhooks](#stripe-webhooks). Skips CSRF protection, sessions, and cookies |
| GET | `/leaves` | `leaves` | `Route::view` → `pages.leaves` | Leaf program: leave the leaves, or donate them by drop-off or pickup |
| GET | `/leaves/apply` | `leaves.apply` | `LeafApplicationController@create` → `pages.leaves-apply` | Application form, or the thank-you message after applying |
| POST | `/leaves/apply` | `leaves.apply.store` | `LeafApplicationController@store` | Saves an application to `leaf_applications` and links it to a contact |
| GET | `/faq` | `faq` | `Route::view` → `pages.faq` | Frequently asked questions |
| GET | `/login` | `login` | `LoginController@create` → `pages.login` | Sign-in form; see [Signing In](#signing-in). Someone already signed in goes to `/admin` |
| POST | `/login` | `login.store` | `LoginController@store` | Signs in with an email address and password |
| POST | `/logout` | `logout` | `LoginController@destroy` | Signs out and returns to `/login` |
| GET | `/admin` | `admin` | `Admin\DashboardController` → `pages.admin.home` | Admin dashboard: who is signed in, counts for the last 90 days, including unresolved errors, and donation totals. Admins only, like every `/admin` route (`auth`, `auth.session`, `AdminOnly`); see [Admin Pages](#admin-pages) |
| GET | `/admin/contacts` | `admin.contacts` | `Admin\ContactController@index` → `pages.admin.contacts.index` | Contacts, the most recent applicant first, with a search in the query string |
| GET | `/admin/contacts/new` | `admin.contacts.create` | `Admin\ContactController@create` → `pages.admin.contacts.create` | The form for adding a contact by hand |
| POST | `/admin/contacts` | `admin.contacts.store` | `Admin\ContactController@store` | Adds a contact, or goes back naming any contact with the same phone number or email |
| GET | `/admin/contacts/review` | `admin.contacts.review` | `Admin\ContactController@review` → `pages.admin.contacts.review` | Applications whose contact needs an admin to look |
| GET | `/admin/contacts/{contact}` | `admin.contacts.show` | `Admin\ContactController@show` → `pages.admin.contacts.show` | One contact with their details and applications |
| GET | `/admin/contacts/{contact}/edit` | `admin.contacts.edit` | `Admin\ContactController@edit` → `pages.admin.contacts.edit` | Editing a contact's name, notes, phone numbers, emails, and address |
| POST | `/admin/contacts/{contact}` | `admin.contacts.update` | `Admin\ContactController@update` | Saves a contact's name and notes |
| POST | `/admin/contacts/{contact}/merge/{other}` | `admin.contacts.merge` | `Admin\ContactController@merge` | Merges the other contact into this one |
| POST | `/admin/contacts/{contact}/details` | `admin.contacts.details.store` | `Admin\ContactDetailController@store` | Adds a phone number or email, or changes the address |
| POST | `/admin/contacts/{contact}/details/{detail}/primary` | `admin.contacts.details.primary` | `Admin\ContactDetailController@primary` | Makes a phone number or email the main one |
| POST | `/admin/contacts/{contact}/details/{detail}/remove` | `admin.contacts.details.remove` | `Admin\ContactDetailController@remove` | Removes a phone number, email, or address, keeping it as a previous one |
| GET | `/admin/donations` | `admin.donations` | `Admin\DonationController@index` → `pages.admin.donations.index` | Donations, one payment at a time, with filters in the query string |
| GET | `/admin/donations/csv` | `admin.donations.csv` | `Admin\DonationController@download` | The same list as a CSV download, with every detail |
| GET | `/admin/donations/totals` | | `Route::redirect` | Redirects to the dashboard, which shows the totals now |
| GET | `/admin/donations/totals/csv` | `admin.donations.totals.csv` | `Admin\DonationTotalsController@download` | The totals as a CSV download |
| GET | `/admin/donations/abandoned` | `admin.donations.abandoned` | `Admin\AbandonedCheckoutController@index` → `pages.admin.donations.abandoned` | Checkouts donors left without paying, with a Period filter |
| GET | `/admin/donations/abandoned/csv` | `admin.donations.abandoned.csv` | `Admin\AbandonedCheckoutController@download` | The same list as a CSV download, with the donors' names and email addresses |
| GET | `/admin/donations/{donation}` | `admin.donations.show` | `Admin\DonationController@show` → `pages.admin.donations.show` | One donation in full, by its ID or Stripe payment ID (`pi_...`), including a deleted one |
| GET | `/admin/leaves` | `admin.leaves` | `Admin\LeafApplicationController@index` → `pages.admin.leaves.index` | Leaf program applications, with filters in the query string |
| GET | `/admin/leaves/csv` | `admin.leaves.csv` | `Admin\LeafApplicationController@download` | The same list as a CSV download, with every detail |
| GET | `/admin/leaves/{application}` | `admin.leaves.show` | `Admin\LeafApplicationController@show` → `pages.admin.leaves.show` | One application in full, including a deleted one, with its contact |
| POST | `/admin/leaves/{application}/review` | `admin.leaves.review` | `Admin\ApplicationContactController@review` | Marks the application's contact reviewed |
| POST | `/admin/leaves/{application}/contact` | `admin.leaves.contact` | `Admin\ApplicationContactController@contact` | Moves or links the application to a contact, or a new one |
| GET | `/admin/errors` | `admin.errors` | `Admin\ErrorLogController@index` → `pages.admin.errors.index` | Error Logs: form submissions that didn't go through, with filters in the query string |
| GET | `/admin/errors/csv` | `admin.errors.csv` | `Admin\ErrorLogController@download` | The same list as a CSV download, with every detail |
| POST | `/admin/errors/close` | `admin.errors.close` | `Admin\ErrorLogController@close` | Closes the submissions in `references[]` as `resolution` (resolved, test, or spam), with an optional `note`, recording which admin did, and goes back |
| POST | `/admin/errors/reopen` | `admin.errors.reopen` | `Admin\ErrorLogController@reopen` | Reopens the submissions in `references[]`, from a submission's page, and goes back |
| GET | `/admin/errors/{reference}` | `admin.errors.show` | `Admin\ErrorLogController@show` → `pages.admin.errors.show` | One submission in full by its six-character reference, whatever its outcome; lowercase is found too |
| GET | `/form-tokens` | `form-tokens` | `FormTokenController` | JSON with a fresh CSRF token (`csrf`) and proof-of-work challenge (`challenge`), for a form that has been open a while; see [Stale Forms](#stale-forms). Not cached; 30 requests per minute per visitor |
| GET | `/dev/receipt-preview/{frequency?}` | | `DonationReceiptPreviewController` | Local development only (`APP_ENV=local`): the receipt email with sample details, for `one-time`, `monthly`, or `annual`. Nothing is saved or sent |

Errors render the branded pages in `resources/views/errors` (see [Error Pages](04-frontend.md#error-page-error-page-and-error-pages)).

Pages with no server-side logic use `Route::view` and need no controller. Link to pages by route name (`route('donate')`) rather than hard-coded paths.

Document each route here as it is added, along with the controller responsible and the request flow for anything non-trivial (forms, webhooks, redirects to the payment gateway).

## Form Submissions

Both forms' POST routes (`POST /donate` and `POST /leaves/apply`) record every submission in `form_submissions` the moment it arrives, with the `RecordFormSubmission` middleware (`app/Http/Middleware/RecordFormSubmission.php`, added as `RecordFormSubmission:donation` and `RecordFormSubmission:leaf_application`). Nothing a visitor typed is lost, even when something fails:

1. **Arrival.** The middleware runs right after the session starts and before the CSRF check (its place is set in `bootstrap/app.php` with `appendToPriorityList()`), so even a submission from a page that had expired is recorded. It saves the reference, name, email, the other fields, and the browser, with the outcome `received`, and keeps the submission on the request for the controller (`FormSubmission::forRequest()`).
2. **Outcome.** Controllers record what happened with `markAs()`: `unavailable`, `rate_limited`, `payment_error`, `sent_to_payment` (with the checkout), or `saved` (with the application). Anything else is worked out from the response when the request ends: a 419 is `expired`, a 5xx is `error`, and errors flashed to the session are `rejected`. The messages the visitor was shown are saved with it.
3. **Failures.** For a failed submission, the middleware writes a warning to the Laravel log with the reference, form, outcome, and the names of the fields that failed, never what was typed, and flashes the reference to the session. Both forms show it under their errors, asking the visitor to include it if they email us.

`php artisan submissions:list` lists the submissions that need a follow-up (see [submissions:list](02-services-and-commands.md#submissionslist-appconsolecommandslistformsubmissionsphp)).

## Stale Forms

A form's CSRF token stops working when its session expires (`SESSION_LIFETIME`, 120 minutes), and its proof-of-work challenge expires after two hours (`ProofOfWork::LIFETIME`). A visitor coming back to a form left open would otherwise get a "page expired" error or a proof-of-work error. Both forms carry `data-form-tokens-url`, and `resources/js/proof-of-work.js` fetches fresh tokens from `GET /form-tokens`:

1. **Before submitting**, if the page was loaded (or last refreshed) more than 30 minutes ago, it replaces the form's `_token` and challenge, solves the new challenge, then submits. A newer page submits right away.
2. **When the visitor comes back** to the tab (it becomes visible or focused) after 30 minutes, it refreshes them ahead of time, so the submit doesn't wait.
3. If the request fails, the form is sent with its current tokens, and the server explains any problem (the branded "page expired" page, or the proof-of-work message).

If the session had expired, fetching the token starts a new one, so the refreshed token matches the cookie the response sets. Only the site's own pages can read the response, so it gives other sites no way to get a token.

## Donations

Payment happens on the payment gateway's hosted page (Stripe Checkout); payments are recorded from the gateway's webhooks, not by these routes. See [Donation Flow](06-planned-features.md#donation-flow).

1. `GET /donate` shows the form with a new proof-of-work challenge. While the gateway is not configured (`PaymentGateway::isAvailable()`, false without `STRIPE_SECRET`), the page says online giving is unavailable and the button is disabled.
2. `POST /donate` is validated by `StartDonationRequest`:
   - The amount is the custom amount when one is entered, otherwise the suggested amount for the chosen frequency, from that frequency's own field (`amount_one_time`, `amount_monthly`, `amount_annual`). The custom amount is cleaned first (spaces, `$`, and commas removed), so `$1,250.50` is accepted.
   - It must be $1 to $9,999 (`MIN_AMOUNT`, `MAX_AMOUNT`) with at most two decimal places, and is converted to cents exactly from the string (`amountCents()`).
   - The frequency must be `one_time`, `monthly`, or `annual`; name and email are required; and the proof of work is checked as on the leaf form.
   - Failures redirect back with errors and the visitor's input. Like every outcome, they are recorded in `form_submissions` (see [Form Submissions](#form-submissions)).
3. The controller refuses if the gateway became unavailable, and allows `DonationController::MAX_PER_HOUR` (10) started checkouts per IP address per hour. Only checkouts that reach the gateway count.
4. In one transaction, it finds the user by email, including a soft-deleted one, or creates them with the name entered. An existing user's name is only filled in when it is empty: typing an email address proves nothing about owning it. A soft-deleted user is not restored here. It then records a `started` checkout for the General Fund.
5. `PaymentGateway::startCheckout()` creates the gateway session, with the thank-you URL as the success URL and `/donate` as the cancel URL. The checkout's `gateway_session_id` and `expires_at` are saved, and the donor is redirected to the gateway. If the gateway call fails, the error is reported, the donor is sent back with a message and their input, and the checkout stays recorded without a session.
6. `GET /donate/thank-you/{reference}` finds the checkout by its UUID reference; anything else is a 404. It shows the donation's amount and frequency, never the donor's name or email, and for recurring donations a link to the Customer Portal login (`STRIPE_PORTAL_LOGIN_URL`).

## Stripe Webhooks

Stripe sends events to `POST /webhooks/stripe`: from each server's registered webhook endpoint, or locally from the Stripe CLI. Responses follow [Webhook Responses](05-ai-development-notes.md#webhook-responses): errors only when something is actually wrong, because Stripe retries failed deliveries for days.

1. **Signing secret.** If `STRIPE_WEBHOOK_SECRET` is not set, the request fails with a 500 and an error is logged.
2. **Signature.** The `Stripe-Signature` header is verified against the raw request body with the Stripe SDK (`Webhook::constructEvent()`), which also rejects signatures more than 5 minutes old, so a captured event can't be replayed. A missing or invalid signature is a 400. The route skips CSRF protection, since Stripe can't send a token, and skips sessions and cookies, since there is no visitor.
3. **Mode.** An event from the other Stripe mode (its `livemode`, see [StripeMode](02-services-and-commands.md#stripemode-apppaymentsstripestripemodephp)) is a 400 and is logged as an error.
4. **API version.** An event whose `api_version` differs from `services.stripe.api_version` is accepted, with a warning in the log (see [Updating the Stripe API version](07-deployment.md#updating-the-stripe-api-version)).
5. **Storage.** The event is stored in `webhook_events` once per event ID; a redelivery finds the stored row. Every event Stripe sends is stored, including types we don't handle.
6. **Queue.** If the event hasn't been processed yet, `ProcessWebhookEvent` is queued and the response is `200 Queued`; if it has, `200 Already processed`. Processing happens on the queue worker (see [ProcessWebhookEvent](02-services-and-commands.md#processwebhookevent-appjobsprocesswebhookeventphp)).

## Leaf Applications

1. `GET /leaves/apply` shows the form. The controller passes `earliestReadyDate` (tomorrow in Fort Collins) for the date field's `min`, and a new proof-of-work challenge (see [ProofOfWork](02-services-and-commands.md#proofofwork-appservicesproofofworkphp)).
2. `POST /leaves/apply` is validated by `StoreLeafApplicationRequest`:
   - The bag count is cleaned first (spaces and commas removed), so `1,200` is accepted.
   - `ready_date` is required only when `ready_now` is `0`, and must be after today in `America/Denver` (`StoreLeafApplicationRequest::TIMEZONE`), not the server's UTC date.
   - `terms` must be accepted.
   - `pow_challenge` and `pow_nonce` must be a solved, unexpired, unused proof of work, checked in the request's `after()` hook. A checked challenge is used up even when other fields fail, and the redisplayed form issues a new one.
   - Failures redirect back with errors and the visitor's input.
3. The controller allows `LeafApplicationController::MAX_PER_HOUR` (5) saved applications per IP address per hour. Only saved applications count, so validation errors never lock anyone out.
4. The application is saved, with `ready_date` cleared when the bags are ready now and `terms_accepted_at` set to the current time, then linked to its contact by [ContactLinker](02-services-and-commands.md#contactlinker-appservicescontactlinkerphp); a failure linking is reported and never shown to the visitor. The visitor is redirected to `GET /leaves/apply` with `applied` (the application type) flashed to the session, which shows the thank-you message.

## Signing In

Admins sign in with their email address and password at `/login`, using Laravel's built-in session authentication (`Auth::attempt()`) rather than an authentication package. Admins are created, and their passwords set, with [users:admin](02-services-and-commands.md#usersadmin-appconsolecommandsmanageadminphp); there is no registration or password reset page yet. The same sign-in page will serve donors once donor accounts are built (see [Accounts](06-planned-features.md#accounts)).

1. `POST /login` is validated by `LoginRequest`: the email address is trimmed and lowercased, since addresses are stored lowercase, and both fields are required.
2. `LoginController` allows `MAX_ATTEMPTS_PER_MINUTE` (5) failed attempts per email address and IP address per minute. After that, even the right password is refused with a message to wait a minute. A successful sign-in clears the count.
3. Only users with the `admin` role can sign in until donor accounts are built, and soft-deleted users never can. A wrong password, an unknown email address, and a donor's account all get the same message, so the form can't be used to find out who has an account.
4. Failures redirect back with the email address only, never the password. The sign-in form isn't recorded in `form_submissions`: that table is for following up with visitors, and the form holds a password.
5. A successful sign-in gets a new session ID, then goes to the admin page the admin first asked for, or `/admin`. There is no "remember me": a session lasts `SESSION_LIFETIME` (120 minutes) after the last request.

Admin pages use three middleware:

- **`auth`** sends guests to `/login`, and back to the page they asked for afterwards.
- **`auth.session`** (Laravel's `AuthenticateSession`) signs out every other session when an admin's password changes, such as after `users:admin` sets a new one.
- **`AdminOnly`** (`app/Http/Middleware/AdminOnly.php`) refuses anyone without the `admin` role with a 403, checked on every request so `users:admin --remove` takes effect right away. Responses are sent with `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`, since admin pages show donors' personal details.

Sessions are stored in the `sessions` table (`SESSION_DRIVER=database`), and servers set `SESSION_SECURE_COOKIE=true` so the session cookie is only sent over HTTPS (see [Server Settings](07-deployment.md#server-settings)).

## Admin Pages

Admin pages show the same records and values as the report commands, from the report classes and describing traits in `app/Reports/` (see [Report Classes](02-services-and-commands.md#report-classes-appreports)), so a page and its command can't disagree. Controllers for admin pages live in `app/Http/Controllers/Admin/`, and every route is under `/admin` behind the middleware in [Signing In](#signing-in).

**Dashboard (`/admin`)** shows who is signed in, then **Recent Activity**: four counts for the last 90 days (`DashboardController::DAYS`), donations received, leaf applications, unresolved errors (leaving out errors the visitor got through later, and those an admin closed), and applications to review (applications from those days whose contact still needs an admin to look). Each comes from the same report class or query as its list, so it matches that list with the Last 90 days period, and links to it; unresolved errors link to Error Logs with `later=0&resolved=0`, and applications to review to Needs Review, which lists every one, however old. Then **Donation Statistics**: what `donations:totals` shows, one list each for month over month, by quarter, by year, and all time, with the same notice when Stripe's fee isn't recorded yet for some donations. `/admin/donations/totals/csv` (`DonationTotalsController`) downloads `donation-totals-<date>.csv` with the same columns as `donations:totals --csv`. The totals had a page of their own, `/admin/donations/totals`, which now redirects to the dashboard.

**Contacts (`/admin/contacts`)** lists contacts from `ContactReport`, the person who applied most recently first and contacts without an application last. `q` searches part of a name or email, or part of a phone number's digits however it was written, including previous ones (up to `ContactController::MAX_SEARCH_LENGTH`, 100 characters). It's a plain `GET` form, so a search is a URL. `/admin/contacts/{contact}` shows one contact's details, the other contacts sharing a phone number or email, their applications, and their form submissions that didn't go through, with how each was followed up (`FormSubmissionReport::forContact()`). A contact merged into another redirects to that one with "That contact was merged into Jamie Rivera."; an unknown contact, or one deleted without merging, is a 404. Contacts have no report command, and no CSV download yet.

**Needs Review (`/admin/contacts/review`)** lists the applications whose contact needs an admin to look (`LeafApplication::needingContactReview()`: flagged and not reviewed yet; see [leaf_applications](01-database-schema.md#leaf_applications)), oldest first, however old: ID (the link to the application), when applied, name, the contact it's linked to (linking to them) or "Not linked", and why. The contact list shows a Needs Review button with the count, only while there are any, and the dashboard counts those from the last 90 days.

**Adding a contact** (`/admin/contacts/new`), such as someone reached by text before they applied, takes a name, and optionally a phone number, email, address, and notes (`SaveContactRequest`). When another contact, not deleted, already has the phone number or email, current or previous, nothing is added: the form comes back with its input and a notice for each, such as "(970) 555-0123 is already Jamie Rivera's phone number." (or "was", for a previous one), linking to that contact, and a box to "Add as a separate contact anyway" (`separate`). A contact added this way gets linked to the applications they send later, like any contact with the same name and phone number or email (see [ContactLinker](02-services-and-commands.md#contactlinker-appservicescontactlinkerphp)). It goes to the new contact's page with "Added Pat Morgan."

**Merging contacts:** a contact's page lists the other contacts, not deleted, that share one of its phone numbers or emails, current or previous (`ContactReport::sharing()`), each with a Merge Into button. It posts to `/admin/contacts/{contact}/merge/{other}`, which merges the other contact into this one ([ContactMerger](02-services-and-commands.md#contactmerger-appservicescontactmergerphp)) and goes back with "Merged Jamie R. into Jamie Rivera, with 1 application." There's no confirmation step, since the merged contact is only soft-deleted. Merging a contact into itself, or with a deleted one, is a 404.

**Editing a contact** (`/admin/contacts/{contact}/edit`): the name and notes are saved together. Each phone number, email, and the address is its own small form (`ContactDetailController`): adding one (`AddContactDetailRequest`, a hidden `type` and a field named after it, so an error shows by the right box; it becomes the main one only when there's none yet, and one already on the contact changes nothing), Make Main, and Remove. Changing the address keeps the previous one, and Remove keeps a detail as a previous one, so applications still match it. Each goes back to the edit page with a message. A detail in the URL must be the contact's own, so another contact's is a 404 (`scopeBindings()`), as is a deleted contact. Edits aren't recorded yet; the record of edits comes with the timeline.

**Donations (`/admin/donations`)** lists payments like `donations:list`. Its filters are a plain `GET` form, so a filtered list is a URL that can be bookmarked or shared with another admin:

| Query | Values | Default | Like |
|-------|--------|---------|------|
| `period` | `30`, `90`, `365`, or `all` (`FiltersByPeriod::PERIODS`, shared by admin lists) | `30` | `--days` |
| `type[]` | `one-time`, `monthly`, `annual`, any combination; none lists every type | none | `--one-time`, `--monthly`, `--annual` |
| `all` | `1` includes pending and failed payments | received only | `--all` |

Anything else, such as a hand-edited URL, falls back to the defaults, since the form can only send these values. `/admin/donations/csv` takes the same query and downloads `donations-<date>.csv` with the same columns as `donations:list --csv`, including the protection against spreadsheet formulas. `/admin/donations/{donation}` shows what `donations:view` shows, with the same notices for a fee not recorded yet and a deleted donation; an unknown ID is a 404.

**Abandoned Checkouts (`/admin/donations/abandoned`)**, reached from the Abandoned Checkouts button on Donations, lists checkouts like `donations:abandoned`, with the same `period` filter as the other lists, and says how many donors donated later. Checkouts have no page of their own, so the list has no links. `/admin/donations/abandoned/csv` takes the same query and downloads `abandoned-checkouts-<date>.csv` with the same columns as `donations:abandoned --csv`, including the donors' names and email addresses.

**Leaf Applications (`/admin/leaves`)** lists applications like `leaves:applications`, with the same `period` filter and a `type[]` filter that the command doesn't have: `drop_off`, `pickup`, or both (none lists both). `/admin/leaves/csv` takes the same query and downloads `leaf-applications-<date>.csv` with the same columns as `leaves:applications --csv`. Each name links to the person's contact, once the application is linked. `/admin/leaves/{application}` shows what `leaves:view` shows, including when the terms were accepted, with a notice for a deleted application; an unknown ID is a 404.

**An application's contact:** its page ends with the contact it's linked to ("Not linked to a contact." otherwise). When it needs review, a notice gives each reason, naming the other contacts it's about with links (`ApplicationReview::candidates()`), and says what to do. Mark Reviewed posts to `/admin/leaves/{application}/review`, which records which admin looked and when, shown afterwards as "Reviewed by Sam on Nov 5, 7:30 PM." "Move this application to" (or "Link this application to", when it isn't linked) posts the chosen `contact` to `/admin/leaves/{application}/contact` (`LinkApplicationContactRequest`): one of the contacts sharing its email or phone number, or `new` for a new contact made from it (`ApplicationReview::move()`). A contact that's deleted, or the one it's already linked to, is refused with an error. Both go back to the application with a message, such as "Moved application 43 to Jamie Rivera." A deleted application shows its contact without the forms, and posting to it is a 404.

**Error Logs (`/admin/errors`)** lists submissions of the donate and leaf application forms that didn't go through, like `submissions:list`, each name linking to the person's contact when one matches (`ContactReport::contactsFor()`), and each with its follow-up: whether the visitor submitted the same form successfully later, linking to the leaf application or donation it made, an admin closed it as resolved, a test, or spam, or neither (unresolved; see [Follow-ups](01-database-schema.md#form_submissions)). Its filters:

| Query | Values | Default | Like |
|-------|--------|---------|------|
| `period` | `30`, `90`, `365`, or `all`, like the other lists | `30` | `--days`, which defaults to 7 |
| `form[]` | `donation`, `leaf_application`, or both; none lists both | none | `--form` |
| `later` | `0` leaves out submissions the visitor got through with later | included | `--unresolved` leaves out both |
| `resolved` | `0` leaves out submissions an admin closed as resolved | included | |
| `spam` | `1` includes submissions closed as spam or a test | left out | `--spam-and-tests` |
| `all` | `1` includes submissions that succeeded | left out | `--all` |

The Submitted later and Resolved by an admin checkboxes are checked unless turned off, so the form sends a hidden `later=0` and `resolved=0` before each checkbox's `1`; when both arrive, the last one wins, as PHP reads query strings. `/admin/errors/csv` takes the same query and downloads `error-logs-<date>.csv` with the same columns as `submissions:list --csv`. `/admin/errors/{reference}` shows one submission in full, such as one a visitor quotes in an email, with a Contact line linking to the person's contact; an unknown reference is a 404.

**Closing errors:** the list's checkboxes with "Mark selected as" Spam, Test, or Resolved, or the Close Error form on a submission's page (which also takes a note of up to 1,000 characters), post the chosen references (`references[]`), `resolution`, and `note` to `/admin/errors/close`. It records which admin closed each one and when, then goes back to the page with a message such as "2 submissions marked as spam." or "1 submission marked as a test." Choosing none goes back with "Select at least one submission to mark."; every error shows at once. Closing one already closed replaces how it was closed. Reopen on a closed submission's page posts to `/admin/errors/reopen`, clearing all four resolution columns. Unknown references are skipped. Admin forms don't record their submissions in `form_submissions`, which is for following up with visitors.

For now the log holds form submissions only; it may grow into general error reporting.

The Period filter and its summary line, such as "No applications yet.", come from `App\Http\Controllers\Admin\Traits\FiltersByPeriod`, so every admin list offers the same periods.
