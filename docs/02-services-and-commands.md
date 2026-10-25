# Services & Commands

Planned services and commands (payment gateway sync, history import, receipts, reporting) are described in [Planned Features](06-planned-features.md).

## Services

### ProofOfWork (`app/Services/ProofOfWork.php`)

Proof-of-work spam protection for public forms, used by the leaf application form.

- `issue()` returns a challenge, `<random hex>.<issued at>.<prefix>.<signature>`, signed with an HMAC of the application key so the prefix and issue time cannot be changed. Nothing is stored when a challenge is issued.
- In the browser, `resources/js/proof-of-work.js` finds a nonce such that SHA-256 of `<challenge>:<nonce>` starts with the prefix: `CHARACTER` (`d`) repeated `DIFFICULTY` (4) times, `dddd`. That takes about 65,000 hashes on average, roughly a second. The character is an arbitrary choice; every hex digit is equally likely, so any prefix of the same length is equally hard.
- `verify($challenge, $nonce)` checks the signature, that the challenge is less than `LIFETIME` (2 hours) old, and the hash. It then records the challenge in the cache until it expires, so each challenge can be used only once.

Raising `DIFFICULTY` by one makes the work 16 times harder for people as well as bots.

### DonationReceipts (`app/Services/DonationReceipts.php`)

Tax receipts for donations, one per donation and never more.

- **Automatic receipts:** payment sync calls `queueIfDue()` every time it syncs a donation. It queues `SendDonationReceipt` only for a donation that `succeeded` within the last 24 hours (`AUTOMATIC_WINDOW_HOURS`) and hasn't been receipted, so importing past history, or a bug, can never email donors in bulk. A donation refunded before its receipt went out isn't receipted. Anything older needs [receipt:send](#receiptsend-appconsolecommandssendreceiptphp).
- **Receipt numbers:** `issue()` gives a donation its receipt the first time, numbered `WET-<tax year>-<sequence>` (such as `WET-2026-00001`), with the sequence restarting each tax year. The tax year is the year the donation was paid in Fort Collins time (`TIMEZONE`), so the evening of December 31st counts toward that year. Numbers are issued under a PostgreSQL advisory lock, so two receipts can never get the same number, and a receipt's number never changes. "WET" is only used in receipt numbers (see the [style guide](08-style-guide.md#writing)).
- **Sending:** `send($donation, $resend)` issues the receipt if needed and emails it to the donor (`DonationReceiptMail`), then sets `sent_at`. A receipt already sent is sent again only with `$resend`, keeping its number.
- **Never twice:** the database's partial unique index refuses a second receipt for the same donation, as a safeguard behind the code (see [donation_receipts](01-database-schema.md#donation_receipts)).

`SendDonationReceipt` (`app/Jobs/SendDonationReceipt.php`) sends one donation's receipt on the queue, retrying failures after 1 minute, 5 minutes, 30 minutes, and 2 hours. It checks again that the receipt is still due when it runs, so a retry more than 24 hours after the donation sends nothing. It is unique per donation.

### DonationReceiptMail (`app/Mail/DonationReceiptMail.php`)

The receipt email (`resources/views/mail/donation-receipt.blade.php`, a Markdown mail with HTML and plain-text versions). It thanks the donor on behalf of the Wildland Ecotech team and lists what donors need for their taxes: the receipt number, date (Fort Collins time), amount, and whether the donation was one-time, monthly, or annual; the statement that no goods or services were provided in exchange; and the organization's legal name, address, and EIN from `config/organization.php`. Receipts for recurring donations link to the Customer Portal login. Replies go to `contact@wildland.eco`. It uses the Wildland Ecotech email theme (see [Email](08-style-guide.md#email) in the style guide).

Stripe's own payment receipts are turned off in the Stripe dashboard, so donors get one receipt (see [Launching Donations](07-deployment.md#launching-donations)).

Locally, `GET /dev/receipt-preview/{one-time|monthly|annual}` shows the email in the browser with sample details, since AhaSend's sandbox records emails without delivering them. The route only exists when `APP_ENV=local`.

### PaymentGateway (`app/Payments/PaymentGateway.php`)

Our interface to the payment processor, so the rest of the application never depends on Stripe directly. Resolve it from the container (`app(PaymentGateway::class)` or a type-hinted parameter); `AppServiceProvider` binds it to `StripeGateway`. The interface grows as each part of the donation flow is built.

- `name()` returns the value stored in `gateway` columns: `stripe`.
- `isAvailable()` says whether payments can be taken. For Stripe, that is whether `STRIPE_SECRET` is set; the donate page offers giving only while it is true.
- `startCheckout($checkout, $successUrl, $cancelUrl)` starts a hosted payment page for a `Checkout` and returns a `HostedCheckout` (`app/Payments/HostedCheckout.php`): the session `id` for `checkouts.gateway_session_id`, the `url` to send the donor to, and `expiresAt`.

### StripeGateway (`app/Payments/Stripe/StripeGateway.php`)

The Stripe implementation of `PaymentGateway`.

- **Customers.** The checkout's user becomes a Stripe customer, so the Customer Portal shows all of a donor's recurring donations together. It uses the user's most recently recorded `gateway_customers` row; otherwise the most recent Stripe customer with their email address, such as a donor who gave through a Payment Link; otherwise a new customer with their name and email. Stripe's email filter is case-sensitive, so a customer created elsewhere with capital letters in their address is not found, and a new one is created. Found customers are recorded, restoring a soft-deleted record rather than duplicating it.
- **Checkout Sessions.** One-time donations use `payment` mode with a "Donate" button; monthly and annual donations use `subscription` mode with a `month` or `year` interval. Every session uses the one Donation product (`STRIPE_DONATION_PRODUCT_ID`) with the amount set inline (`price_data`), so any amount works without creating prices. No payment methods are listed, so Checkout offers every method enabled in the Stripe dashboard that supports the donation's frequency.
- **Metadata.** The checkout's `reference` and designation slug are set as the session's `metadata` and `client_reference_id`, and copied to the PaymentIntent or subscription, which outlive the session, so sync and import can match payments to checkouts and designations.
- **Retries.** Sessions are created with an idempotency key of the checkout reference and customer, so a retried request returns the same session. If a recorded customer was deleted in Stripe, its record is soft-deleted and the session is created again with a current customer.
- **Notices.** Every call goes through `call()` (see [CallsStripe](#callsstripe-apppaymentsstripeconcernscallsstripephp)).

The Stripe client is resolved from the container when first needed, not when the gateway is created, so the gateway can answer `isAvailable()` on a server without Stripe keys.

`tests/Feature/StripeGatewayTest.php` checks the exact requests it sends, without calling Stripe (see [CallsStripe](#callsstripe-apppaymentsstripeconcernscallsstripephp)).

### StripeSync (`app/Payments/Stripe/StripeSync.php`)

Brings our records up to date with Stripe, with one function per object type. Each fetches the object's current state from Stripe and updates our record by its Stripe ID, including soft-deleted records, so receiving the same change twice, out of order, or from a re-import never creates duplicates, restores a deleted record, or moves a record backwards. Webhooks only say which object changed (see [StripeEventProcessor](#stripeeventprocessor-apppaymentsstripestripeeventprocessorphp)); the history importer runs objects through the same functions. Every function takes a `$source` (`webhook` or `import`), recorded on the subscription history it adds. Objects from the other Stripe mode are refused.

Objects are read as arrays (`toArray()` and Laravel's `data_get()`), because reading a field a Stripe object doesn't have logs an error.

- `checkoutSession($sessionId)`: sets the checkout's status from the session's: `open` → `started`, `complete` → `completed`, `expired` → `expired`. `completed_at` is set the first time a checkout is seen completed and kept after that. The checkout is found by session ID, or else by the `checkout_reference` in the session's metadata (in case saving the session ID failed). Sessions that aren't ours, such as a Payment Link's, are ignored. A bank payment that settles later still completes its checkout right away; whether the payment succeeds is recorded on the donation.

- `customer($customerId)`: links a Stripe customer to the user with its email address, creating the user if needed, and records the `gateway_customers` row. A user's name is only filled in when they don't have one, never replaced, since typing an email address on a payment page proves nothing about owning it. A customer without an email address, or deleted in Stripe before we recorded it, has no user.
- `subscription($subscriptionId)`: records the recurring donation: its donor (from the customer), designation (the `designation` slug in its metadata, else the General Fund), amount (price × quantity), interval, status, and dates. Stripe's `incomplete_expired` is recorded as `canceled`, and `trialing` as `active`. It adds to the subscription's history: `created`, `amount_changed` (with the old and new amounts), `paused`, `resumed`, and `canceled`. Each entry has a source reference that makes syncing the same state again add nothing.
- `invoice($invoiceId)`: for a subscription's invoice, syncs the subscription and each payment of the invoice, found through Stripe's invoice payments, then adds `renewed` to the history for a paid renewal (`billing_reason` `subscription_cycle`), or `payment_failed` for each failed attempt on an open invoice. The first invoice's payment (`subscription_create`) belongs to the checkout in the subscription's metadata. Invoices that aren't for a subscription are ignored.
- `paymentIntent($paymentIntentId)`: records the payment as a donation, one row per PaymentIntent:
  - **Status:** `succeeded` (or `refunded` / `partially_refunded` from the charge's refunded amount), `pending` while it settles (`processing`, such as a bank payment), or `failed` when an attempt failed (`last_payment_error`). A payment that was never attempted, such as a checkout left before paying, isn't recorded.
  - **Amounts:** the amount, Stripe's fee from the charge's balance transaction, and the refunded amount. `paid_at` is when the charge was made.
  - **What it belongs to:** a subscription payment is found through its invoice (Stripe's invoice payments, since payments no longer link to invoices directly) and takes the subscription's donor and designation. Other payments belong to the checkout in their metadata, or, without one, such as Payment Link donations, go to the General Fund.
  - **Donor:** the subscription's or checkout's user, else the Stripe customer's, else the user for the charge's billing email, created if needed. A payment with none of these is logged and not recorded.
  - A soft-deleted donor is restored when a payment from them is received, so someone mistaken for spam comes back with their donation.
- `charge($chargeId)`: syncs the payment a charge belongs to, such as after a refund.

### StripeEventProcessor (`app/Payments/Stripe/StripeEventProcessor.php`)

Processes a stored Stripe webhook event by calling the `StripeSync` function for the object it is about. `EVENTS` lists every event type Stripe sends us, mapped to its sync function. It must match `STRIPE_EVENTS` in `docker-compose.yml` (a test checks this) and the events the server webhook endpoints subscribe to. Events without a sync function yet, and any other event, are accepted and marked processed without changes.

### ProcessWebhookEvent (`app/Jobs/ProcessWebhookEvent.php`)

The queued job that processes a stored webhook event after the webhook has been answered.

- Each attempt increments the event's `attempts`. A failure stores its message in `error` and is retried after 1 minute, 5 minutes, 30 minutes, 2 hours, and 12 hours, 6 tries over about a day in all; success sets `processed_at` and clears `error`.
- It is unique per event (`ShouldBeUnique`, using a cache lock), so a redelivered event can't be processed alongside its own retry. An event already processed is skipped.
- After its last try, the job stays in `failed_jobs`; retry it with `php artisan queue:retry` (see [Operating a Server](07-deployment.md#operating-a-server)). The nightly sync also catches up on whatever it missed.

### StripeImporter (`app/Payments/Stripe/StripeImporter.php`)

Imports Stripe history for `stripe:sync` by listing objects and running each through `StripeSync` with the `import` source:

1. **Subscriptions**, always all of them (`status: all`), since a long-running one can change at any time; first, so their payments find them without asking Stripe again.
2. **Invoices**, with their payments, renewals, and failed payments.
3. **Payments** (PaymentIntents): one-time donations from our checkouts and from Payment Links. Payments of invoices are synced again, harmlessly.
4. **Refunds**, so a recent refund of an older payment is caught.
5. **Checkout sessions**, completing or expiring checkouts.

`import($createdSince, $each)` limits everything but subscriptions to objects created since a time, or imports everything when it is null. Lists are paged through automatically. Each object is handed to `$each` with a closure that syncs it, one at a time, so the caller can show progress and handle failures, and the import continues with the next object. The closure returns the synced record, or `null` when there is nothing to record, such as a Payment Link's checkout session or a payment that was never attempted. Stripe keeps its event log for only about 30 days, so history is rebuilt from objects as they are now: a subscription imported long after it was canceled gets its start and cancellation, but not every change between.

### CallsStripe (`app/Payments/Stripe/Concerns/CallsStripe.php`)

Shared by the classes that call Stripe:

- `stripe()` resolves the `StripeClient` when first needed, so a class can be created without a key.
- `call()` wraps every API call. The SDK raises notices Stripe attaches to responses as PHP warnings, which Laravel throws as exceptions, so a notice would fail a call that already succeeded; `call()` logs them as warnings instead (see [Stripe](07-deployment.md#stripe)).
- `assertLivemode($object)` throws unless a Stripe object's `livemode` matches this site's mode, so test and live data never mix. Deleted objects, such as a deleted customer, come back from Stripe as just their ID and `deleted: true`, with no `livemode`, and are accepted.

Tests run these classes through the real SDK without calling Stripe, using `tests/Concerns/FakesStripeApi.php`, which replaces the SDK's HTTP client (`Stripe\ApiRequestor::setHttpClient()`) with one that records requests and returns queued responses.

### StripeMode (`app/Payments/Stripe/StripeMode.php`)

Keeps Stripe's test and live modes apart. Only the production site may use live mode.

- `expected()` returns `live` when the host in `APP_URL` is `wildland.eco` (`LIVE_HOST`), and `test` for every other host, including staging and local development. The mode comes from `APP_URL` rather than its own setting, since staging deliberately runs with `APP_ENV=production`.
- `ofKey($key)` reads a key's mode from its prefix: `sk_live_` or `rk_live_` is live, and `sk_test_` or `rk_test_` is test.
- `assertValid()` throws a `RuntimeException` unless `STRIPE_SECRET` is set and its mode matches `expected()`. The message never includes the key.
- `expectsLivemode()` is for sync code, which rejects any Stripe object or event whose `livemode` flag doesn't match.

The `Stripe\StripeClient` singleton (bound in `AppServiceProvider`) calls `assertValid()` when it is first resolved and uses the API version pinned in `config/services.php`. The check runs then, not at boot, so a wrong key can never stop artisan commands, including the ones a deploy runs to fix it.

Document each service class and artisan command here as it is added: its purpose, inputs, side effects, and how to run it.

## Scheduled Tasks

Tasks are scheduled in `routes/console.php` and run by the `scheduler` container (see [Deployment](07-deployment.md)). Times are in Fort Collins time.

| Task | When | Purpose |
|------|------|---------|
| `stripe:sync --days=3` | Daily at 3:30 AM | Catches anything a Stripe webhook missed. Skipped while Stripe isn't configured, and never overlaps a sync still running. Three days covers a weekend of webhook failures; a longer outage needs a manual `stripe:sync --days=N`. Each run's output is appended to `storage/logs/stripe-sync.log` |

## Artisan Commands

### stripe:check (`app/Console/Commands/CheckStripeConfiguration.php`)

Checks that the Stripe settings match the mode this site must use, without calling Stripe. Deploys run it after caching the configuration, so a wrong key fails the deploy instead of taking payments in the wrong mode.

- `STRIPE_SECRET` empty: passes with a warning that payments are disabled, so servers deploy normally before Stripe is set up.
- `STRIPE_SECRET` set: fails if its mode doesn't match (see [StripeMode](#stripemode-apppaymentsstripestripemodephp)), or if `STRIPE_WEBHOOK_SECRET` (`whsec_`), `STRIPE_DONATION_PRODUCT_ID`, or `STRIPE_PORTAL_LOGIN_URL` is missing.

```bash
docker compose exec app php artisan stripe:check
```


### mail:test (`app/Console/Commands/SendTestEmail.php`)

Sends a short test email (`App\Mail\TestEmail`) to an address, right away rather than through the queue, to check a server's mail settings before donors depend on them. It shows the mailer, SMTP server, from address, and reply-to address it used, and the error if sending fails. With an AhaSend sandbox credential, the message is recorded in the AhaSend dashboard but not delivered; with `MAIL_MAILER=log`, it is written to the log. See [Email](07-deployment.md#email).

```bash
docker compose exec app php artisan mail:test you@example.com
```

### receipt:send (`app/Console/Commands/SendReceipt.php`)

Sends the receipt for one donation, named by its Stripe payment ID (`pi_...`), such as a donation that succeeded more than 24 hours before it was synced and so wasn't receipted automatically. It takes exactly one payment ID and nothing else, so it can never email more than one donor. Only `succeeded` donations are receipted.

It shows the payment, amount, date, and the address the receipt will go to, and asks for confirmation before sending. A receipt already sent is refused unless `--resend` is given, which sends the same receipt, with the same number, again.

| Option | Effect |
|--------|--------|
| `--resend` | Send a receipt that was already sent again |
| `--force` | Send without asking for confirmation |

```bash
docker compose exec app php artisan receipt:send pi_3UUJTNIeELjLGgDr0L3xPGHk
```

On a server, run it from the clone's directory as the clone's owner (`sudo -u deploy docker compose exec app ...`).

### stripe:sync (`app/Console/Commands/SyncStripe.php`)

Imports donations, recurring donations, refunds, and checkouts from Stripe through the same sync functions as webhooks (see [StripeImporter](#stripeimporter-apppaymentsstripestripeimporterphp)). Safe to run any number of times: every record is keyed on its Stripe ID.

| Option | Default | Effect |
|--------|---------|--------|
| `--days=N` | everything | Only objects created in the last N days; subscriptions are always imported in full |

Run it in full once per environment after Stripe is configured, to import past history, including donations made through Payment Links before the donate page. The scheduler runs it nightly for the last three days.

Output is shown as the sync runs: a line saying whether it's a live or test mode sync and what it covers, then one line per object (such as `Payment pi_3U…`), written before the object syncs and finished with its time and `DONE`, `SKIPPED` (nothing to record), or `FAIL`. If a sync is slow or stuck, the last line shows which object it's on. A summary table follows, with how many objects of each kind were synced, skipped, and failed, then each failure's error. A failed object is also reported to the log, the rest still sync, and the command exits with an error; running it again retries. Without `STRIPE_SECRET`, it does nothing.

```bash
docker compose exec app php artisan stripe:sync
docker compose exec app php artisan stripe:sync --days=7
```

### leaves:applications (`app/Console/Commands/ListLeafApplications.php`)

Lists leaf program applications from `/leaves/apply`, oldest first, for reviewing them until the administrator pages are built (milestone 10). Times are shown in Fort Collins time. Soft-deleted applications are not listed.

| Option | Default | Effect |
|--------|---------|--------|
| `--days=N` | 30 | Applications from the last N days |
| `--csv` | off | CSV with a header row, for importing into a spreadsheet |

```bash
docker compose exec app php artisan leaves:applications
docker compose exec app php artisan leaves:applications --days=7
docker compose exec -T app php artisan leaves:applications --csv > ~/leaf-applications.csv
```

On a server, run it from the clone's directory as the clone's owner (`sudo -u deploy docker compose exec ...`).

The output contains donors' personal contact details: never paste it into issues or chat, and never save the CSV inside the repository. Anyone can submit the form, so CSV values starting with `=`, `+`, `-`, or `@` get a leading apostrophe, which keeps spreadsheets from running them as formulas.
