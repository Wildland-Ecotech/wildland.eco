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
| POST | `/leaves/apply` | `leaves.apply.store` | `LeafApplicationController@store` | Saves an application to `leaf_applications` |
| GET | `/faq` | `faq` | `Route::view` → `pages.faq` | Frequently asked questions |
| GET | `/login` | `login` | `LoginController@create` → `pages.login` | Sign-in form; see [Signing In](#signing-in). Someone already signed in goes to `/admin` |
| POST | `/login` | `login.store` | `LoginController@store` | Signs in with an email address and password |
| POST | `/logout` | `logout` | `LoginController@destroy` | Signs out and returns to `/login` |
| GET | `/admin` | `admin` | `Route::view` → `pages.admin.home` | Admin dashboard: who is signed in. Admins only, like every `/admin` route (`auth`, `auth.session`, `AdminOnly`); see [Admin Pages](#admin-pages) |
| GET | `/admin/donations` | `admin.donations` | `Admin\DonationController@index` → `pages.admin.donations.index` | Donations, one payment at a time, with filters in the query string |
| GET | `/admin/donations/csv` | `admin.donations.csv` | `Admin\DonationController@download` | The same list as a CSV download, with every detail |
| GET | `/admin/donations/{donation}` | `admin.donations.show` | `Admin\DonationController@show` → `pages.admin.donations.show` | One donation in full, by its ID or Stripe payment ID (`pi_...`), including a deleted one |
| GET | `/admin/leaves` | `admin.leaves` | `Admin\LeafApplicationController@index` → `pages.admin.leaves.index` | Leaf program applications, with filters in the query string |
| GET | `/admin/leaves/csv` | `admin.leaves.csv` | `Admin\LeafApplicationController@download` | The same list as a CSV download, with every detail |
| GET | `/admin/leaves/{application}` | `admin.leaves.show` | `Admin\LeafApplicationController@show` → `pages.admin.leaves.show` | One application in full, including a deleted one |
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
4. The application is saved, with `ready_date` cleared when the bags are ready now and `terms_accepted_at` set to the current time, and the visitor is redirected to `GET /leaves/apply` with `applied` (the application type) flashed to the session, which shows the thank-you message.

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

**Donations (`/admin/donations`)** lists payments like `donations:list`. Its filters are a plain `GET` form, so a filtered list is a URL that can be bookmarked or shared with another admin:

| Query | Values | Default | Like |
|-------|--------|---------|------|
| `period` | `30`, `90`, `365`, or `all` (`FiltersByPeriod::PERIODS`, shared by admin lists) | `30` | `--days` |
| `type[]` | `one-time`, `monthly`, `annual`, any combination; none lists every type | none | `--one-time`, `--monthly`, `--annual` |
| `all` | `1` includes pending and failed payments | received only | `--all` |

Anything else, such as a hand-edited URL, falls back to the defaults, since the form can only send these values. `/admin/donations/csv` takes the same query and downloads `donations-<date>.csv` with the same columns as `donations:list --csv`, including the protection against spreadsheet formulas. `/admin/donations/{donation}` shows what `donations:view` shows, with the same notices for a fee not recorded yet and a deleted donation; an unknown ID is a 404.

**Leaf Applications (`/admin/leaves`)** lists applications like `leaves:applications`, with the same `period` filter and a `type[]` filter that the command doesn't have: `drop_off`, `pickup`, or both (none lists both). `/admin/leaves/csv` takes the same query and downloads `leaf-applications-<date>.csv` with the same columns as `leaves:applications --csv`. `/admin/leaves/{application}` shows what `leaves:view` shows, including when the terms were accepted, with a notice for a deleted application; an unknown ID is a 404.

The Period filter and its summary line, such as "No applications yet.", come from `App\Http\Controllers\Admin\Traits\FiltersByPeriod`, so every admin list offers the same periods.
