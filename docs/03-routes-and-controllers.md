# Routes & Controllers

## Current Routes

| Method | URI | Name | Handler | Notes |
|--------|-----|------|---------|-------|
| GET | `/` | `home` | `Route::view` → `pages.home` | Home page |
| GET | `/menu` | `menu` | `Route::view` → `pages.menu` | Full-page menu for visitors without JavaScript |
| GET | `/donate` | `donate` | `Route::view` → `pages.donate` | Donate page; the form does not submit until the Stripe integration (milestone 6), and no page links here until then |
| GET | `/leaves` | `leaves` | `Route::view` → `pages.leaves` | Leaf program: leave the leaves, or donate them by drop-off or pickup |
| GET | `/leaves/apply` | `leaves.apply` | `LeafApplicationController@create` → `pages.leaves-apply` | Application form, or the thank-you message after applying |
| POST | `/leaves/apply` | `leaves.apply.store` | `LeafApplicationController@store` | Saves an application to `leaf_applications` |

Pages with no server-side logic use `Route::view` and need no controller. Link to pages by route name (`route('donate')`) rather than hard-coded paths.

Document each route here as it is added, along with the controller responsible and the request flow for anything non-trivial (forms, webhooks, redirects to the payment gateway).

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
