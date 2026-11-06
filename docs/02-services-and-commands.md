# Services & Commands

Planned services and commands (payment gateway sync, history import, receipts, reporting) are described in [Planned Features](06-planned-features.md).

## Services

### ProofOfWork (`app/Services/ProofOfWork.php`)

Proof-of-work spam protection for public forms, used by the leaf application form.

- `issue()` returns a challenge, `<random hex>.<issued at>.<prefix>.<signature>`, signed with an HMAC of the application key so the prefix and issue time cannot be changed. Nothing is stored when a challenge is issued.
- In the browser, `resources/js/proof-of-work.js` finds a nonce such that SHA-256 of `<challenge>:<nonce>` starts with the prefix: `CHARACTER` (`d`) repeated `DIFFICULTY` (4) times, `dddd`. That takes about 65,000 hashes on average, roughly a second. The character is an arbitrary choice; every hex digit is equally likely, so any prefix of the same length is equally hard.
- `verify($challenge, $nonce)` checks the signature, that the challenge is less than `LIFETIME` (2 hours) old, and the hash. It then records the challenge in the cache until it expires, so each challenge can be used only once.

Raising `DIFFICULTY` by one makes the work 16 times harder for people as well as bots.

A form open for more than 30 minutes gets a fresh challenge from `GET /form-tokens` before it submits, along with a fresh CSRF token (see [Stale Forms](03-routes-and-controllers.md#stale-forms)).

### DonationReceipts (`app/Services/DonationReceipts.php`)

Tax receipts for donations, one per donation and never more.

- **Automatic receipts:** payment sync calls `queueIfDue()` every time it syncs a donation. It queues `SendDonationReceipt` only for a donation that `succeeded` within the last 24 hours (`AUTOMATIC_WINDOW_HOURS`) and hasn't been receipted, so importing past history, or a bug, can never email donors in bulk. A donation refunded before its receipt went out isn't receipted. Anything older needs [receipt:send](#receiptsend-appconsolecommandssendreceiptphp).
- **Receipt numbers:** `issue()` gives a donation its receipt the first time, numbered `WET-<tax year>-<code>` (such as `WET-2026-K7Q2MX`). The code is six random characters (`CODE_ALPHABET`: digits and capital letters without the lookalikes 0, 1, I, L, and O), drawn with `random_int()`, so a number reveals nothing about how many donations we receive and can't be guessed. The tax year is the year the donation was paid in Fort Collins time (`TIMEZONE`), so the evening of December 31st counts toward that year. Receipts are issued under a PostgreSQL advisory lock, and a code already used, even by a deleted receipt, is drawn again, so two receipts can never get the same number, and a receipt's number never changes. "WET" is only used in receipt numbers (see the [style guide](08-style-guide.md#writing)).
- **Sending:** `send($donation, $resend)` issues the receipt if needed and emails it to the donor (`DonationReceiptMail`), then sets `sent_at`. A receipt already sent is sent again only with `$resend`, keeping its number.
- **Never twice:** the database's partial unique index refuses a second receipt for the same donation, as a safeguard behind the code (see [donation_receipts](01-database-schema.md#donation_receipts)).

`SendDonationReceipt` (`app/Jobs/SendDonationReceipt.php`) sends one donation's receipt on the queue, retrying failures after 1 minute, 5 minutes, 30 minutes, and 2 hours. It checks again that the receipt is still due when it runs, so a retry more than 24 hours after the donation sends nothing. It is unique per donation.

### ContactLinker (`app/Services/ContactLinker.php`)

Links a leaf application to the person who made it, so every application from the same person is on one contact. `LeafApplicationController@store` calls `link()` after the application is saved; a failure is reported and leaves the application unlinked, so it never turns an application away.

An application matches a contact, not deleted, by its email or its phone number's 10 digits, current or removed (see [contact_details](01-database-schema.md#contact_details)). `link()` returns what it did:

| Outcome | When | What happens |
|---------|------|--------------|
| `CREATED` | Nobody matches | A new contact with the application's name, and its email, phone number, and address |
| `LINKED` | One contact matches, with the same name (ignoring case and spacing) and no other current address | Linked. The application's email and phone number become the contact's primary ones, since the newest application has the latest details; earlier ones stay. Its address becomes the contact's if they had none |
| `FLAGGED` | One contact matches, with a different name, or a different current address | Linked, with `contact_review` listing `name`, `address`, or both. The contact is left unchanged, since it may be someone else at the same number, or a move |
| `CONFLICT` | The email and phone number match different contacts | Not linked; `contact_review` is `conflict` |
| `SKIPPED` | The application is already linked or flagged | Nothing |

Each link runs in a transaction, so a failure leaves no half-made contact.

### DonationReceiptMail (`app/Mail/DonationReceiptMail.php`)

The receipt email (`resources/views/mail/donation-receipt.blade.php`, a Markdown mail with HTML and plain-text versions). It thanks the donor on behalf of the Wildland Ecotech team and lists what donors need for their taxes: the receipt number, date (Fort Collins time), amount, and whether the donation was one-time, monthly, or annual; the statement that no goods or services were provided in exchange; and the organization's legal name, address, and EIN from `config/organization.php`. Receipts for recurring donations link to the Customer Portal login, and every receipt links to the refund and privacy policies. Replies go to `contact@wildland.eco`. It uses the Wildland Ecotech email theme (see [Email](08-style-guide.md#email) in the style guide).

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
- **Notices.** Every call goes through `call()` (see [CallsStripe](#callsstripe-apppaymentsstripetraitscallsstripephp)).

The Stripe client is resolved from the container when first needed, not when the gateway is created, so the gateway can answer `isAvailable()` on a server without Stripe keys.

`tests/Feature/StripeGatewayTest.php` checks the exact requests it sends, without calling Stripe (see [CallsStripe](#callsstripe-apppaymentsstripetraitscallsstripephp)).

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

Processes a stored Stripe webhook event by calling the `StripeSync` function for the object it is about. `EVENTS` lists every event type Stripe sends us, mapped to its sync function. It must match `STRIPE_EVENTS` in `docker-compose.dev.yml` (a test checks this) and the events the server webhook endpoints subscribe to. Events without a sync function yet, and any other event, are accepted and marked processed without changes.

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

### CallsStripe (`app/Payments/Stripe/Traits/CallsStripe.php`)

Shared by the classes that call Stripe:

- `stripe()` resolves the `StripeClient` when first needed, so a class can be created without a key.
- `call()` wraps every API call. The SDK raises notices Stripe attaches to responses as PHP warnings, which Laravel throws as exceptions, so a notice would fail a call that already succeeded; `call()` logs them as warnings instead (see [Stripe](07-deployment.md#stripe)).
- `assertLivemode($object)` throws unless a Stripe object's `livemode` matches this site's mode, so test and live data never mix. Deleted objects, such as a deleted customer, come back from Stripe as just their ID and `deleted: true`, with no `livemode`, and are accepted.

Tests run these classes through the real SDK without calling Stripe, using `tests/Traits/FakesStripeApi.php`, which replaces the SDK's HTTP client (`Stripe\ApiRequestor::setHttpClient()`) with one that records requests and returns queued responses.

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

### Report Classes (`app/Reports/`)

The queries behind the report commands and admin pages, as plain classes, so the commands and the admin pages list the same records. Each command validates its options, asks its report class for the records, and writes them; the report classes take plain values, such as a number of days, and never read options or requests. Resolve them from the container, as the commands' `handle()` methods do.

| Class | Methods | Used by |
|-------|---------|---------|
| `DonationReport` | `list($days, $types, $all)`: payments by the date they were made, oldest first, from the last `$days` days or ever with `null`, limited to some of `TYPES` (`one-time`, `monthly`, `annual`), received only unless `$all`. `find($id)`: one donation by its ID or Stripe payment ID, including a deleted one | `donations:list`, `donations:view`, `/admin/donations` |
| `DonationTotalsReport` | `totals()`: totals by month, quarter, year, and all time, keyed by `GROUPINGS`, each with its `net` (the amount less refunds and fees). `missingFees($totals)` | `donations:totals`, the dashboard (`/admin`), `/admin/donations/totals/csv` |
| `AbandonedCheckoutReport` | `list($days)`: abandoned checkouts with `donated_later`, from the last `$days` days or ever with `null` | `donations:abandoned`, `/admin/donations/abandoned` |
| `LeafApplicationReport` | `list($days, $types)`: applications from the last `$days` days or ever with `null`, limited to some of `LeafApplication::TYPES`. `find($id)`, including a deleted application | `leaves:applications`, `leaves:view`, `/admin/leaves` |
| `ContactReport` | `list($search)`: contacts, not deleted, with the most recent applicant first and those without an application last, each with its current details, `applications_count`, and `applications_max_created_at`. `$search` matches part of a name or email, ignoring case, or part of a phone number's digits however it was written, including previous emails and phone numbers; LIKE wildcards are taken literally. `find($id)`: one contact, not deleted, with every detail and their applications, newest first | `/admin/contacts` |
| `FormSubmissionReport` | `list($days, $forms, $all, $submittedLater, $resolvedByAdmin, $spamAndTests)`: submissions from the last `$days` days or ever with `null`, limited to some of `FormSubmission::FORMS`, that need a follow-up, or all of them with `$all`. Each comes with `$submission->laterSubmission`, the visitor's later successful submission of the same form, if any (see [Follow-ups](01-database-schema.md#form_submissions)). `followUp($submission)` says where each stands (`FOLLOW_UP_SUBMITTED_LATER`, `FOLLOW_UP_RESOLVED`, `FOLLOW_UP_TEST`, `FOLLOW_UP_SPAM`, or `FOLLOW_UP_UNRESOLVED`); unresolved ones are always listed, and the three flags include the others (the first two by default). `find($reference)`: one submission whatever its outcome, case-insensitively, with its later submission. `needsAttention($submission)` | `submissions:list`, `/admin/errors`, the dashboard's unresolved errors |

What each list includes, such as which payments count as received or when a checkout counts as abandoned, is described with its command below.

### Shared Report Helpers (`app/Reports/Traits/`, `app/Console/Traits/`)

Traits shared by the report commands, so each command has only its own fields. Those in `app/Reports/Traits/` describe and format records for reading anywhere, so the admin pages can show the same values as the commands; those in `app/Console/Traits/` write to the console.

**`app/Reports/Traits/`:**

- **`DescribesDonations`:** how `donations:list`, `donations:view`, and `donations:abandoned` describe a donation, so they show the same donor, type, status, date, and amounts: `donationFields()` (keyed by `DONATION_LABELS`), `donor()` (the name of a donation's or checkout's donor, or their email address without one), `donationType()` and `checkoutType()` (One-time, Monthly, or Annual), `donationStatus()`, and `donationMadeAt()`. Load donations with `donationRelations()`, which includes soft-deleted donors, subscriptions, and designations. `DONATION_TABLE_HEADERS` and `donationTableRow()` are a donation's row in a list, `donationCsvHeader()` and `donationCsvRow()` its row in a CSV, and `donationListName()` names a list, such as "monthly donations received", so `donations:list` and the admin donations page show the same rows and words. Uses `FormatsMoney` and `FormatsTimes`.
- **`DescribesLeafApplications`:** how `leaves:applications`, its CSV, `leaves:view`, and the admin leaf application pages describe a leaf program application: `leafApplicationFields()` (keyed by `LEAF_APPLICATION_LABELS`), `leafApplicationType()` (Pickup or Drop-off), and `bagsReady()` (Now, or the date given). `LEAF_APPLICATION_TABLE_HEADERS` and `leafApplicationTableRow()` are an application's row in a list, `leafApplicationCsvHeader()` and `leafApplicationCsvRow()` its row in a CSV, and `leafApplicationListName()` names a list, such as "pickup applications". Uses `FormatsTimes`.
- **`DescribesDonationTotals`:** how `donations:totals`, its CSV, and the dashboard's Donation Statistics describe totals: `DONATION_TOTALS_HEADERS` and `donationTotalRow()` for a table, and `donationTotalsCsvHeader()` and `donationTotalsCsvRows()` for a CSV with a Grouping column. Uses `FormatsMoney`.
- **`DescribesCheckouts`:** how `donations:abandoned`, its CSV, and the admin abandoned checkouts page describe an abandoned checkout: `CHECKOUT_TABLE_HEADERS` and `checkoutTableRow()` for a list, `checkoutCsvHeader()` and `checkoutCsvRow()` for a CSV with the donor's name and email address, and `donatedLater()` (Yes or No). Uses `DescribesDonations`.
- **`DescribesFormSubmissions`:** how `submissions:list`, its CSV, and the admin Error Logs pages describe a form submission: `formSubmissionFields()` (keyed by `FORM_SUBMISSION_LABELS`), `formSubmissionOutcome()` (what happened, in plain words), `formSubmissionDetails()` (the amount and frequency, or the pickup or drop-off details), and `formSubmissionFollowUp()` with `formSubmissionFollowUpText()`: whether the visitor got through later ("Submitted 4 minutes later", followed by " as PAID44" in the CSV and the command), an admin closed it ("Resolved by Rachel Shelton", "Marked as a test", or "Marked as spam", followed by the admin's note in the CSV and the command), or neither ("No record"); empty for one that went through. `formSubmissionClosedBy()` names the admin who closed it. `FORM_SUBMISSION_TABLE_HEADERS` and `formSubmissionTableRow()` are a submission's row in a list (the email address stands in for a missing name), `formSubmissionCsvHeader()` and `formSubmissionCsvRow()` its row in a CSV, and `formSubmissionListName()` names a list ("submissions that did not succeed"). Get submissions from `FormSubmissionReport`, which loads their checkouts and later submissions. Uses `FormatsTimes`.
- **`FormatsMoney`:** `money($cents, $currency)` formats an amount for reading the way receipts do, such as `$1,250.00` or `-$1.75`, with the currency code instead of a dollar sign for anything but US dollars (`20.00 EUR`). `decimal($cents)` formats it as a plain number for CSV, such as `1250.00`. Laravel's `Number::currency()` isn't used because it needs PHP's `intl` extension, which the Docker image doesn't install.
- **`FormatsTimes`:** `localTime($time, $format)` formats a time in Fort Collins time, by default such as `Wed, Oct 21, 2026 7:30 PM MDT`, `tableTime($time)` without the weekday for commands' table columns, such as `Oct 21, 2026 7:30 PM MDT`, `listTime($time)` for admin pages' lists, without the time zone, such as `Oct 21, 7:30 PM`, and `shortDate($time)` for a short line, such as `Oct 21`. The last two have the year only when it isn't this year (`Oct 21, 2025, 7:30 PM` and `Oct 21, 2025`). Admin list controllers replace the time in a command's table row with `listTime()`.
- **`FormatsCsv`:** `csv($header, $rows)` returns a header row and the rows as CSV text. Values starting with `=`, `+`, `-`, or `@` get a leading apostrophe, which keeps spreadsheets from running them as formulas. Plain negative numbers, such as `-1.75`, are left as they are so spreadsheets can add them up.

**`app/Console/Traits/`:**

- **`ListsRecords`:** `daysOption()` reads `--days`, a whole number of at least 1, or shows an error and returns null so the command can exit with `INVALID`. `writeTable($headers, $rows, $rightAligned)` writes records as a table, one row each, for an overview, with the columns listed in `$rightAligned` (by index) lined up on the right, for amounts; tests pass the same `rightAlignedColumns()` styles to `expectsTable()`. `writeRecord($heading, $fields)` writes one record in full, as a heading followed by one `Label: value` line per field, with a dash for an empty value; the heading may use console formatting tags. Both escape the values, so submitted text cannot add console formatting. Uses `FormatsTimes`.
- **`WritesCsv`:** `writeCsv($header, $rows)` writes `FormatsCsv`'s CSV to the console, raw and in one piece, so values are never read as console formatting tags.

Fort Collins time is `timezone` in `config/organization.php` (`America/Denver`); dates are stored in UTC.

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

### users:admin (`app/Console/Commands/ManageAdmin.php`)

Makes a user an admin and sets their password, or makes an admin a donor again. There is no public registration: admins are only created with this command, since `role` can't be mass assigned (see [users](01-database-schema.md#users)).

- **Account:** the email address is matched case-insensitively. A donor with that address keeps their account, so a board member who has already donated has one record with their whole history. A new address creates an account. A soft-deleted account can't be made an admin.
- **Confirmation:** it shows the email address, the name, and whether the account is new, a donor (and since when), or already an admin, then asks before changing anything, so a mistyped address can't promote the wrong donor.
- **Name:** asked for when the account doesn't have one.
- **Password:** typed hidden, then again to confirm, and hashed by the model. It must be at least 12 characters (`ManageAdmin::MIN_PASSWORD_LENGTH`), with no other rules. Running the command again for an admin sets a new password; there is no other way to reset one yet.

| Option | Effect |
|--------|--------|
| `--remove` | Makes the admin a donor again and removes their password |

```bash
docker compose exec app php artisan users:admin board-member@example.com
docker compose exec app php artisan users:admin board-member@example.com --remove
```

On a server, run it from the clone's directory as the clone's owner (`sudo -u deploy docker compose exec app ...`). The password prompts need a terminal, so don't add `-T`.

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

### donations:list (`app/Console/Commands/ListDonations.php`)

Lists donations one payment at a time, oldest first, as a table for an overview of recent giving until the administrator pages are built (milestone 10). Each payment of a monthly or annual donation is its own row. [donations:view](#donationsview-appconsolecommandsviewdonationphp) shows one in full. Recurring donations themselves, with their status (active, past due, canceled), are left to a future `subscriptions:list`. Soft-deleted donations are not listed.

The table has the key details of each payment:

| Column | Notes |
|--------|-------|
| ID | The donation's ID, for `donations:view` |
| Date | Fort Collins time. A received payment's date is when it was paid (`paid_at`); a pending or failed payment's is when the donor tried to pay, from Stripe's creation time of the payment in `gateway_data`, so payments imported later still fall on the right day. `--days` counts from these dates |
| Donor | The donor's name, or their email address if we don't have a name, such as for some Payment Link donations |
| Type | One-time, Monthly, or Annual, from the subscription the payment belongs to |
| Amount | The amount donated, before Stripe's fee |
| Status | Paid, Pending, Failed, Refunded, or Partially refunded |

| Option | Default | Effect |
|--------|---------|--------|
| `--days=N` | 30 | Payments from the last N days |
| `--one-time` | off | Only one-time donations |
| `--monthly` | off | Only payments of monthly donations |
| `--annual` | off | Only payments of annual donations |
| `--all` | off | Include pending and failed payments; without it, only payments we received are listed, including ones refunded later |
| `--csv` | off | CSV with every detail `donations:view` shows, plus a Currency column, for importing into a spreadsheet. Amounts are plain numbers, such as `1250.00`; a fee not recorded yet leaves the fee and net empty rather than counting as zero |

The type options can be combined, such as `--monthly --annual` for every recurring payment; with none of them, every type is listed.

```bash
docker compose exec app php artisan donations:list
docker compose exec app php artisan donations:list --days=90 --monthly --annual
docker compose exec app php artisan donations:list --one-time --all
docker compose exec -T app php artisan donations:list --days=365 --csv > ~/donations.csv
```

On a server, run it from the clone's directory as the clone's owner (`sudo -u deploy docker compose exec ...`).

The output contains donors' names and email addresses: never paste it into issues or chat, and never save the CSV inside the repository. Donors type their own names, so CSV values starting with `=`, `+`, `-`, or `@` get a leading apostrophe (see [Shared Report Helpers](#shared-report-helpers-appreportstraits-appconsoletraits)).

### donations:view (`app/Console/Commands/ViewDonation.php`)

Shows one donation in full, named by its ID from `donations:list` or its Stripe payment ID (`pi_...`): the payment ID, date in Fort Collins time, type, status, the donor's name and email, the amount, Stripe's fee, the amount refunded, the net amount, and the designation.

- **Net** is the amount less refunds and Stripe's fee. Stripe keeps its fee when a payment is refunded, so a full refund has a small negative net.
- **Fee, refunded, and net** are only shown for payments we received (`Donation::RECEIVED_STATUSES`); pending and failed payments have none.
- **A fee not recorded yet** shows as "Not recorded yet", with a note that running `stripe:sync` again records it once Stripe has it.
- **A deleted donation** is shown too, since it was asked for by ID, with a warning saying when it was deleted.

```bash
docker compose exec app php artisan donations:view 12
docker compose exec app php artisan donations:view pi_3UUJTNIeELjLGgDr0L3xPGHk
```

The output contains the donor's name and email address: never paste it into issues or chat.

### donations:totals (`app/Console/Commands/ShowDonationTotals.php`)

Totals of the donations we received, for keeping tabs on giving and for quarterly board meetings until the administrator pages are built (milestone 10). It shows four tables, oldest period first: month over month, by quarter (calendar quarters, such as `Q1 2026` for January through March), by year, and all time. Periods without donations are left out.

Each row has the number of donations, the amount donated, the amount refunded, Stripe's fees, and the net: the amount less refunds and fees. Amounts line up on the right.

- **What counts:** payments we received (`Donation::RECEIVED_STATUSES`), including ones refunded later, since their refunds and fees are counted too. Pending and failed payments and soft-deleted donations are left out.
- **Periods** are by the date a donation was paid in Fort Collins time, so a donation made on the evening of December 31st counts toward that year, matching the receipt's tax year. The database adds up each month; quarters, years, and all time are added up from the months.
- **Stripe's fees** are the fees Stripe actually charged, recorded from each payment's balance transaction. Stripe keeps its fee when a payment is refunded, so a fully refunded donation still counts its fee. A fee not recorded yet isn't counted, and a warning says how many donations are missing one; running `stripe:sync` again records them once Stripe has them.
- **Currencies** are totaled separately, so a period with donations in two currencies has a row for each.

| Option | Default | Effect |
|--------|---------|--------|
| `--csv` | off | CSV with a Grouping column (Month over month, By quarter, By year, All time) and a Currency column, for importing into a spreadsheet. Amounts are plain numbers, such as `1250.00` |

```bash
docker compose exec app php artisan donations:totals
docker compose exec -T app php artisan donations:totals --csv > ~/donation-totals.csv
```

On a server, run it from the clone's directory as the clone's owner (`sudo -u deploy docker compose exec ...`). The totals contain no personal details.

### donations:abandoned (`app/Console/Commands/ListAbandonedCheckouts.php`)

Lists abandoned checkouts, oldest first: donors who filled in the donate form and went to Stripe's payment page, but left without paying, so the checkout expired (see [Abandoned Checkouts](06-planned-features.md#abandoned-checkouts)). `submissions:list` shows these too, among the form's other problems; this command shows only them, with whether each donor came back.

The table shows when the checkout was started (Fort Collins time), the donor (their name, or their email address without one), the type (One-time, Monthly, or Annual), the amount, and **Donated later**: whether the donor has a donation we received that was paid after the checkout was started, such as from a second try.

- **Abandoned** means `expired`, or still `started` with Stripe's expiry time passed, which happens when the expiration webhook hasn't arrived yet, such as during an outage; the nightly `stripe:sync` marks those expired. Checkouts that never reached Stripe (no session), are still open, were paid, or were soft-deleted are left out.
- `--days` counts from when the checkout was started.

| Option | Default | Effect |
|--------|---------|--------|
| `--days=N` | 30 | Checkouts started in the last N days |
| `--csv` | off | CSV with the checkout reference, the donor's name and email, and a Currency column, for importing into a spreadsheet. Amounts are plain numbers |

```bash
docker compose exec app php artisan donations:abandoned
docker compose exec app php artisan donations:abandoned --days=90
docker compose exec -T app php artisan donations:abandoned --csv > ~/abandoned-checkouts.csv
```

On a server, run it from the clone's directory as the clone's owner (`sudo -u deploy docker compose exec ...`).

The output contains donors' names and email addresses: never paste it into issues or chat, and never save the CSV inside the repository. Automatic reminder emails for abandoned checkouts come after launch (see [Abandoned Checkouts](06-planned-features.md#abandoned-checkouts)).

### submissions:list (`app/Console/Commands/ListFormSubmissions.php`)

Lists recent submissions of the donate and leaf application forms that didn't succeed, oldest first, so we can reach out to the people who made them (see [form_submissions](01-database-schema.md#form_submissions)). It shows submissions the form rejected, from expired pages, rate-limited, or failed with an error; submissions that stopped without an outcome (still `received` after 5 minutes); and donors who went to pay but left the payment page without finishing (their checkout expired). For each: the reference, time in Fort Collins, form, what happened in plain words, the follow-up (whether the visitor got through later, with how long after and that submission's reference, how an admin closed it, with their note, or "No record"), name, email, the main details entered (amount and frequency, or pickup or drop-off and bag count), the errors shown, and the browser.

| Option | Default | Effect |
|--------|---------|--------|
| `--days=N` | 7 | Submissions from the last N days |
| `--form=FORM` | both | Only `donation` or `leaf_application` |
| `--all` | off | Include submissions that succeeded |
| `--unresolved` | off | Only submissions still unresolved: leaves out those the visitor got through with later and those an admin closed as resolved |
| `--spam-and-tests` | off | Include submissions closed as spam or a test on the Error Logs pages |
| `--csv` | off | CSV with a header row, for importing into a spreadsheet |

```bash
docker compose exec app php artisan submissions:list
docker compose exec app php artisan submissions:list --days=30 --form=donation
docker compose exec -T app php artisan submissions:list --csv > ~/form-submissions.csv
```

On a server, run it from the clone's directory as the clone's owner (`sudo -u deploy docker compose exec ...`). To find one submission a visitor quotes by reference, use `--all` with enough `--days`, or look it up in the `form_submissions` table.

The output contains visitors' names, emails, and form details: never paste it into issues or chat, and never save the CSV inside the repository. CSV values starting with `=`, `+`, `-`, or `@` get a leading apostrophe (see [Shared Report Helpers](#shared-report-helpers-appreportstraits-appconsoletraits)).

### leaves:applications (`app/Console/Commands/ListLeafApplications.php`)

Lists leaf program applications from `/leaves/apply`, oldest first, as a table for an overview until the administrator pages are built (milestone 10). [leaves:view](#leavesview-appconsolecommandsviewleafapplicationphp) shows one in full. Soft-deleted applications are not listed.

The table shows each application's ID (for `leaves:view`), when it was made (Fort Collins time), the donor's name, the type (Pickup or Drop-off), the estimated number of bags, and when the bags are ready (Now, or the date the donor gave).

| Option | Default | Effect |
|--------|---------|--------|
| `--days=N` | 30 | Applications from the last N days |
| `--csv` | off | CSV with every detail `leaves:view` shows except when the terms were accepted, for importing into a spreadsheet |

```bash
docker compose exec app php artisan leaves:applications
docker compose exec app php artisan leaves:applications --days=7
docker compose exec -T app php artisan leaves:applications --csv > ~/leaf-applications.csv
```

On a server, run it from the clone's directory as the clone's owner (`sudo -u deploy docker compose exec ...`).

The output contains donors' personal contact details: never paste it into issues or chat, and never save the CSV inside the repository. Anyone can submit the form, so CSV values starting with `=`, `+`, `-`, or `@` get a leading apostrophe, which keeps spreadsheets from running them as formulas (see [Shared Report Helpers](#shared-report-helpers-appreportstraits-appconsoletraits)).

### leaves:view (`app/Console/Commands/ViewLeafApplication.php`)

Shows one leaf program application in full, named by its ID from `leaves:applications`: when it was made, the type, the donor's name, email, phone, and address, the estimated number of bags, when they are ready, how the donor heard about us, their questions, and when they accepted the program's terms. A deleted application is shown too, since it was asked for by ID, with a warning saying when it was deleted.

```bash
docker compose exec app php artisan leaves:view 12
```

The output contains the donor's personal contact details: never paste it into issues or chat.

### contacts:link (`app/Console/Commands/LinkContacts.php`)

Links leaf applications that have no contact yet and aren't waiting for review, the same way new applications are linked (see [ContactLinker](#contactlinker-appservicescontactlinkerphp)). Run it once per environment for the applications made before contacts existed, and any time to pick up an application whose linking failed. It goes oldest first, so each contact ends up with the details from their newest application, and leaves out deleted applications. Safe to run any number of times.

| Option | Default | Effect |
|--------|---------|--------|
| `--dry-run` | off | Links everything inside a transaction that is rolled back, so it shows exactly what would happen without saving anything |

It shows how many applications made new contacts, were linked to an existing contact, were linked and flagged for review, were flagged without linking, or failed, then a table of those flagged (ID, when applied, name, and why). A failure is reported to the log and fails the command, and the rest still run; running it again retries it.

Run a dry run first, then the real thing:

```bash
docker compose exec app php artisan contacts:link --dry-run
docker compose exec app php artisan contacts:link
```

On a server, run them from the clone's directory as its owner, such as `sudo -u deploy docker compose exec app php artisan contacts:link --dry-run`.

The output names applicants: never paste it into issues or chat.
