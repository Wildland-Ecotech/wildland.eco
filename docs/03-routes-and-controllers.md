# Routes & Controllers

## Current Routes

| Method | URI | Name | Handler | Notes |
|--------|-----|------|---------|-------|
| GET | `/` | `home` | `Route::view` → `pages.home` | Home page |
| GET | `/menu` | `menu` | `Route::view` → `pages.menu` | Full-page menu for visitors without JavaScript |
| GET | `/donate` | `donate` | `DonationController@create` → `pages.donate` | Donate page. No page links here until donations launch (milestone 6) |
| POST | `/donate` | `donate.store` | `DonationController@store` | Records a checkout and redirects to the payment gateway's page |
| GET | `/donate/thank-you/{checkout:reference}` | `donate.thank-you` | `DonationController@thankYou` → `pages.donate-thank-you` | Where the gateway returns the donor after paying |
| GET | `/leaves` | `leaves` | `Route::view` → `pages.leaves` | Leaf program: leave the leaves, or donate them by drop-off or pickup |
| GET | `/leaves/apply` | `leaves.apply` | `LeafApplicationController@create` → `pages.leaves-apply` | Application form, or the thank-you message after applying |
| POST | `/leaves/apply` | `leaves.apply.store` | `LeafApplicationController@store` | Saves an application to `leaf_applications` |
| GET | `/faq` | `faq` | `Route::view` → `pages.faq` | Frequently asked questions |

Pages with no server-side logic use `Route::view` and need no controller. Link to pages by route name (`route('donate')`) rather than hard-coded paths.

Document each route here as it is added, along with the controller responsible and the request flow for anything non-trivial (forms, webhooks, redirects to the payment gateway).

## Donations

Payment happens on the payment gateway's hosted page (Stripe Checkout); payments are recorded from the gateway's webhooks, not by these routes. See [Donation Flow](06-planned-features.md#donation-flow).

1. `GET /donate` shows the form with a new proof-of-work challenge. While the gateway is not configured (`PaymentGateway::isAvailable()`, false without `STRIPE_SECRET`), the page says online giving is unavailable and the button is disabled.
2. `POST /donate` is validated by `StartDonationRequest`:
   - The amount is the custom amount when one is entered, otherwise the suggested amount for the chosen frequency, from that frequency's own field (`amount_one_time`, `amount_monthly`, `amount_annual`). The custom amount is cleaned first (spaces, `$`, and commas removed), so `$1,250.50` is accepted.
   - It must be $1 to $9,999 (`MIN_AMOUNT`, `MAX_AMOUNT`) with at most two decimal places, and is converted to cents exactly from the string (`amountCents()`).
   - The frequency must be `one_time`, `monthly`, or `annual`; name and email are required; and the `website` spam trap and proof of work are checked as on the leaf form.
   - Failures redirect back with errors and the visitor's input.
3. The controller refuses if the gateway became unavailable, and allows `DonationController::MAX_PER_HOUR` (10) started checkouts per IP address per hour. Only checkouts that reach the gateway count.
4. In one transaction, it finds the user by email, including a soft-deleted one, or creates them with the name entered. An existing user's name is only filled in when it is empty: typing an email address proves nothing about owning it. A soft-deleted user is not restored here. It then records a `started` checkout for the General Fund.
5. `PaymentGateway::startCheckout()` creates the gateway session, with the thank-you URL as the success URL and `/donate` as the cancel URL. The checkout's `gateway_session_id` and `expires_at` are saved, and the donor is redirected to the gateway. If the gateway call fails, the error is reported, the donor is sent back with a message and their input, and the checkout stays recorded without a session.
6. `GET /donate/thank-you/{reference}` finds the checkout by its UUID reference; anything else is a 404. It shows the gift's amount and frequency, never the donor's name or email, and for recurring gifts a link to the Customer Portal login (`STRIPE_PORTAL_LOGIN_URL`).

## Leaf Applications

1. `GET /leaves/apply` shows the form. The controller passes `earliestReadyDate` (tomorrow in Fort Collins) for the date field's `min`, and a new proof-of-work challenge (see [ProofOfWork](02-services-and-commands.md#proofofwork-appservicesproofofworkphp)).
2. `POST /leaves/apply` is validated by `StoreLeafApplicationRequest`:
   - The bag count is cleaned first (spaces and commas removed), so `1,200` is accepted.
   - `ready_date` is required only when `ready_now` is `0`, and must be after today in `America/Denver` (`StoreLeafApplicationRequest::TIMEZONE`), not the server's UTC date.
   - `terms` must be accepted, and `website`, a hidden spam trap, must be empty.
   - `pow_challenge` and `pow_nonce` must be a solved, unexpired, unused proof of work, checked in the request's `after()` hook. A checked challenge is used up even when other fields fail, and the redisplayed form issues a new one.
   - Failures redirect back with errors and the visitor's input.
3. The controller allows `LeafApplicationController::MAX_PER_HOUR` (5) saved applications per IP address per hour. Only saved applications count, so validation errors never lock anyone out.
4. The application is saved, with `ready_date` cleared when the bags are ready now and `terms_accepted_at` set to the current time, and the visitor is redirected to `GET /leaves/apply` with `applied` (the application type) flashed to the session, which shows the thank-you message.
