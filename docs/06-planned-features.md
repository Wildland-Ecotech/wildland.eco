# Planned Features

The development plan for the wildland.eco Laravel platform: accepting donations, then giving donors a member account. Update this document as milestones complete and decisions change.

## Guiding Decisions

- **Launch donations first, dashboards later.** Donations go live as soon as donors can give, receive receipts, and manage their recurring donations. Donor accounts and administrator pages follow after launch; until then, reports are artisan commands, like `leaves:applications`.
- **Server-rendered Blade, no frontend framework.** Every public page is rendered by Laravel and works without JavaScript; small vanilla JS modules add animations and conveniences. A JavaScript framework would need either a server-side rendering Node process in production or pages rendered only in the browser.
- **No Node.js at runtime.** Node is only used to build CSS and JS assets.
- **Docker in every environment.** Local development, staging, and production run the same `docker-compose.yml`; differences live only in each environment's `.env` files.
- **PostgreSQL 17 + PostGIS.** PostGIS is not needed for donations, but it gives us accurate area and distance calculations, coordinate system conversion for drone and LIDAR data, raster storage, and direct QGIS access for the mapping work planned below.
- **Gateway-neutral payments.** Stripe is used through the Stripe PHP SDK directly (no Laravel Cashier). Stripe calls sit behind our own payment gateway interface, and database columns use `gateway` and `gateway_*_id` instead of Stripe-specific names, so we can move from Stripe-hosted pages to our own UI, or to another processor, without a schema rewrite.
- **Stripe-hosted pages for the MVP.** Stripe Checkout collects payments, and the Stripe Customer Portal handles payment methods and cancellation. Donors reach the portal through its no-code login link: they enter their email address and Stripe emails them a sign-in link, so managing a recurring donation needs no account on our site. Both are a quick MVP for accepting donations sooner: an on-site donation flow in the site's own design replaces them after launch (milestone 11).
- **Every payment is re-importable.** Webhooks and a history importer write through the same idempotent sync code, so any record can be imported again without creating duplicates.
- **Nothing is hard-deleted.** Records holding people and money are soft-deleted, so nothing is lost by accident, and a user with transaction history cannot be deleted at all. Hard deletes happen only when absolutely necessary. See [Deletion](#deletion).
- **Email is always collected; passwords are optional.** Donors give an email address to donate, which creates their user record automatically. Signing in to our site comes with accounts after launch, and setting a password is offered only after a donation.
- **Transactional email through AhaSend** over SMTP from `no-reply@from.wildland.eco`, a subdomain kept for sending, with replies to `contact@wildland.eco`. Proton Mail remains the mailbox for human email.
- **Pull-based deploys.** Staging updates automatically when changes are merged into `main`, and production when changes are merged into `release`, matching other Wetfish projects. The server pulls code when GitHub notifies it of a push; no SSH keys or server credentials are stored on GitHub.

## Milestones

| # | Milestone | Status |
|---|-----------|--------|
| 1 | Laravel setup with Wetfish Skeleton | Done |
| 2 | Consolidate documentation and record this plan | Done |
| 3 | Convert the site to Blade, SCSS, and vanilla JS | Done |
| 4 | New production server, webhook deploys, launch at parity | Launched; backups deferred |
| 5 | Donation database schema and models | Done |
| 6 | Stripe Checkout, webhooks, and history import | Done |
| 7 | Donation receipts and transactional email | Done |
| 8 | Reporting commands, end-to-end testing in Stripe test mode, then go live | Planned |
| 9 | Donor accounts and member dashboard | After launch |
| 10 | Administrator pages and reporting | After launch |
| 11 | On-site donation flow and recurring donation management, replacing the Stripe-hosted pages | After launch |

Milestones 5 through 8 are the minimum for accepting donations. Content pages can be built any time after milestone 4. Stripe test mode is used from milestone 6 onward, not only in milestone 8.

### 3. Convert the Site to Blade

Done. `/`, `/donate`, and `/menu` are Blade pages styled with Sass and enhanced by small vanilla JavaScript modules. The structure and conventions are documented in [Frontend](04-frontend.md).

- Every page works without JavaScript: the programs accordion uses `<details>`, project modals open through anchor links and CSS `:target`, and the donate selector uses radio inputs.
- The donate form did not submit yet, and no page linked to `/donate`; milestone 6 enabled both.
- Feature tests in `tests/Feature/PagesTest.php` cover each page's key content and no-JavaScript fallbacks.

### 4. Production Server and Deploys

Launched: wildland.eco is served by the production server, and both servers deploy automatically. Server setup and operations are documented in [Deployment](07-deployment.md).

**Environments**

| Environment | Server | Domain | Branch | Updates |
|-------------|--------|--------|--------|---------|
| Local development | Developer's machine | `localhost:8080` | any | Manually |
| Staging | Staging VPS | `stage.wildland.eco` | `main` | Automatically when changes are merged into `main` |
| Production | Production VPS | `wildland.eco` (`www.wildland.eco` redirects; `prod.wildland.eco` is the server's own hostname) | `release` | Automatically when changes are merged into `release` |

**Decisions**

- **Caddy** terminates HTTPS on each server with automatically renewed certificates. It was chosen because its configuration is short and readable and, unlike Traefik's usual setup, it does not need access to the Docker socket.
- **Published ports are bound to `127.0.0.1`.** Docker's published ports bypass host firewalls such as ufw, so binding to all interfaces would expose the database to the internet.
- **The webhook listener has no Docker access.** It verifies GitHub's signature and, for pushes to the server's branch, only writes a trigger file; a systemd unit on the host runs the deploy. Compromising the internet-facing listener cannot run arbitrary commands.
- **Containers never run as root.** PHP-FPM started as root hands requests to `www-data` workers, which cannot write to root-owned storage. Deploys and containers run as the clone's owner, and setup gives a root-owned clone to a `deploy` user.
- **No credentials on GitHub.** The repository is public, so servers pull without GitHub credentials, and secrets live only in each server's `.env` files.

**Remaining**

- **Backups (deferred):** the hosting provider's daily server backups cover production for now. A nightly `pg_dump` of the production database, kept off the server, comes later.
- **`prod.wildland.eco` redirect:** redirect everything except `/hooks/` to `https://wildland.eco`, so search engines treat it as an alias. It currently serves the site with a `noindex` header.
- **Retire the old static server** once DNS has fully propagated.
- **Future hardening:** deploy only release tags signed with a maintainer's hardware key (YubiKey), verified on the server against an allowed-signers file that never leaves the server. A compromised GitHub account then cannot deploy code to production.

The queue worker and scheduler containers were added in milestone 5, with the queue tables; the deploy script also restarts the queue worker.

### 5. Database Schema

Done. The tables are documented in [Database Schema](01-database-schema.md), and the queue worker and scheduler in [Deployment](07-deployment.md). See [Planned Schema](#planned-schema) for the original plan. Includes the users table, models, relationships, factories, soft deletes and the rules in [Deletion](#deletion), and creating the General Fund designation in its migration, since deploys run migrations but never seeders. The framework tables for the cache and queue were generated in this milestone, with `queue` and `scheduler` containers running in every environment, and local development switches `CACHE_STORE` and `QUEUE_CONNECTION` to `database`. Servers keep their existing `laravel/.env`, so once the merge has deployed the tables, staging and production switch both settings to `database` by hand (see [Server Settings](07-deployment.md#server-settings)). The session and password reset tables come with accounts (milestone 9).

The test database was set up ahead of this milestone, for the leaf program's application form: the `db-test` service is a separate PostgreSQL + PostGIS database matching production, replacing the in-memory SQLite database that Laravel's default `phpunit.xml` configured. Tests never run against SQLite or the development database (see [Running Tests](07-deployment.md#running-tests)).

### 6. Stripe Integration

Done. Donations work end to end in Stripe's sandbox: the donate page, Stripe Checkout, webhooks, payment sync, and the history importer. Setting up a server for donations is in [Launching Donations](07-deployment.md#launching-donations).

- Done: the Stripe PHP SDK with the API version pinned in `config/services.php`, the test and live mode check (`StripeMode` and `stripe:check`, run by every deploy), and the Stripe CLI for local webhook testing.
- Done: the `PaymentGateway` interface with a Stripe implementation that finds or creates the donor's Stripe customer and starts Checkout Sessions (see [Services & Commands](02-services-and-commands.md#paymentgateway-apppaymentspaymentgatewayphp)).
- Payment methods are not listed in our code: Checkout offers every method enabled in the Stripe dashboard that supports the donation's frequency, such as cards, wallets, and bank accounts. Methods that settle later, such as US bank accounts (ACH), record a `pending` donation until the payment succeeds or fails.
- Every Checkout Session uses one "Donation" product (`STRIPE_DONATION_PRODUCT_ID`, one per mode) with the donor's amount set inline, and a monthly or yearly interval for recurring donations, so any amount works without creating prices in advance.
- Done: donate form → Checkout Session → Stripe-hosted payment page → thank-you page, with name and email required, proof-of-work spam protection, and a per-IP limit on started checkouts (see [Donations](03-routes-and-controllers.md#donations)).
- Checkout reuses a donor's existing Stripe customer, so the Customer Portal shows all of their recurring donations together. When several Stripe customers share an email address, the portal's login link opens the most recently created one with an active subscription.
- Done: webhook endpoint that verifies Stripe's signature, stores the event, and processes it on the queue with retries, completing and expiring checkouts (see [Stripe Webhooks](03-routes-and-controllers.md#stripe-webhooks)).
- Done: payment sync of customers, subscriptions with their history, payments with Stripe's fee, and refunds, restoring a soft-deleted donor whose payment succeeds (see [StripeSync](02-services-and-commands.md#stripesync-apppaymentsstripestripesyncphp)).
- Done: the `stripe:sync` history importer, run nightly for the last three days (see [stripe:sync](02-services-and-commands.md#stripesync-appconsolecommandssyncstripephp)).
- Done: the thank-you page links to the Customer Portal login for managing recurring donations.
- Done: the Donate links in page footers are restored.

### 7. Receipts and Email

Done, except year-end summaries.

- Done: one receipt email per successful donation, one-time or each recurring payment: the organization's legal name, address, and EIN, the amount, date, receipt number (`WET-<tax year>-<sequence>`), and a statement that no goods or services were provided in exchange. Written acknowledgment matters most for single donations of $250 or more, which donors need in order to claim the deduction. See [DonationReceipts](02-services-and-commands.md#donationreceipts-appservicesdonationreceiptsphp).
- Done: receipts for recurring donations link to the Customer Portal login.
- Done: receipts are only sent automatically for donations that succeeded within the last 24 hours, so an import or a bug can never email donors in bulk, and the database refuses a second receipt for a donation. `php artisan receipt:send` sends one donation's receipt by its Stripe payment ID. Stripe's own payment receipts are turned off.
- Done: every environment sends through AhaSend (`send.ahasend.com`, port 587 with STARTTLS required), with the `from.wildland.eco` sending domain verified. Local development uses an AhaSend sandbox credential, which records messages without delivering them, instead of a local mail-catching container, so testing uses the same path as production. `php artisan mail:test` checks a server's settings (see [Email](07-deployment.md#email)).
- Year-end giving summaries are an artisan command, built after launch and run in January.

### 8. Reporting and Go-Live

- Reporting artisan commands, following `leaves:applications`: donations for a date range (with `--csv`), totals by month, quarter, and year, and active recurring donations. The exact commands are decided when they are built.
- End-to-end runs in Stripe test mode covering one-time and recurring donations, failed payments, refunds, cancellation through the Customer Portal, abandoned checkouts, re-imports, and webhook retries.
- Privacy policy (including email collection) and refund policy pages.
- Switch production to live keys, register the live webhook endpoint, and import live account history, including existing Payment Link donations.

### 9. Accounts and Member Dashboard

After launch. See [Accounts](#accounts). The dashboard shows donation history, active recurring donations, receipts, and the designation each donation supported, with a button that opens a Customer Portal session until milestone 11 replaces it. Donors who gave before accounts existed already have a user record and sign in with an email link.

### 10. Administrator Pages

After launch. Pages for users with the `admin` role, which board members are given too: donor search, donation and subscription lists with filters, a donor detail page with full history, CSV export, and the reports below. Refunds are issued from the Stripe dashboard and synced back automatically. Administrators can soft-delete spam accounts that have no transaction history (see [Deletion](#deletion)).

**Reports:**

- Total donations by month, quarter, and year
- Active vs. churned recurring donors
- Monthly recurring revenue (MRR)
- Failed payment rate and recovery
- Average donation size by frequency
- Donor retention
- Year-end receipt generation

### 11. On-Site Donation Flow

After launch. Stripe Checkout and the no-code Customer Portal are a quick MVP so we can start accepting donations; the long-term goal is a donation experience that matches the look and feel of the site from start to finish.

- Payment fields on our own donate page, built with Stripe's embeddable payment components (confirmed against the pinned API version when built), in place of the redirect to Stripe Checkout.
- Donors manage recurring donations from the member dashboard (milestone 9): update their payment method, change the amount, or cancel, replacing the Customer Portal. Until then, donors change an amount by canceling and starting a new donation.
- Stripe's payment fields need JavaScript, which Stripe uses for fraud detection, so paying has no fallback without it; this is an agreed exception to [Progressive Enhancement](05-ai-development-notes.md#progressive-enhancement).
- The `PaymentGateway` interface and gateway-neutral columns keep this a change to the donation pages, not to the schema or the sync code.

## Donation Flow

1. The donor chooses a frequency and amount and enters their name and email address, both required, so every donor has a name to address them by.
2. The server finds or creates a user by email (no password), records a `checkouts` row, creates a Checkout Session with the checkout's reference in its metadata, and redirects to Stripe.
3. Stripe collects payment. Webhooks record the donation, and the subscription for recurring donations.
4. The thank-you page confirms the donation and links to the Customer Portal login for managing recurring donations. After accounts are built, it also offers an optional password (see [Accounts](#accounts)).
5. If the donor leaves without paying, the Checkout Session expires and the checkout is marked `expired`. After launch, the donor may receive one reminder email (see [Abandoned Checkouts](#abandoned-checkouts)).

## Accounts

Built after launch (milestone 9). Until then, donors have a user record but do not sign in to our site, and they manage recurring donations through the Customer Portal login link.

The goal is the least possible friction before a donor has committed, without exposing anyone's giving history to someone who merely typed their email address.

- **Accounts are created automatically** from the email entered on the donate page. No password is required to donate.
- **Optional password after donating.** The thank-you page offers "set a password", tied to the browser session that started the checkout. Password rules are a minimum length only, with no character-class requirements.
- **Unverified accounts see only their own checkout.** Typing an email address proves nothing about owning it, so until the address is verified, the account can only see the donation from that checkout.
- **Verification by email link.** Receipt emails include a signed link that verifies the address and signs the donor in. If a password was set before verification, verifying clears it and the verified owner sets their own.
- **Email login links.** Donors can always sign in with a one-time link sent by email, so a password is never required. The request form always responds the same way, whether or not an account exists, so it cannot be used to find out who has donated.
- **Existing accounts.** If the email already belongs to a verified account, the thank-you page asks the donor to sign in instead of offering a password.
- **Roles:** `donor` and `admin`. Board members are given `admin`; a separate role is added only once board members need different permissions.
- Authentication uses Laravel Fortify, with views written in the site's own design.

## Abandoned Checkouts

- A checkout that expires without payment is marked `expired`. Stripe Checkout Sessions expire after a configurable period, and we receive an expiration webhook. This is recorded from launch.
- Reminder emails come after launch. At most one reminder is sent per checkout, and reminders to the same address are rate-limited, so the donate form cannot be used to flood someone's inbox.
- Reminders include an unsubscribe link, and the privacy policy is updated to disclose them before they are sent.

## Payment Sync and Import

**Idempotency comes from keying records on the gateway's own IDs, not from filtering events.** A donation is identified by the payment it represents, so receiving the same payment through several webhook events, a retried webhook, or a re-run import always updates one row.

- **One sync function per object type** (customer, subscription, invoice, payment, and charge for refunds). Each fetches the current object from Stripe and upserts it by `(gateway, gateway_*_id)`. Webhooks only signal which object changed, so out-of-order events are harmless.
- **Soft-deleted records are matched too.** Sync and import look records up by gateway ID including soft-deleted rows, so a re-import updates them without restoring them and never creates a duplicate.
- **Every webhook is stored** in `webhook_events` (unique per event ID), then processed on the queue. Failures are recorded and can be retried.
- **History importer** (`php artisan stripe:sync`): lists Stripe's subscriptions, invoices, payments, refunds, and checkout sessions, all of them or those created in the last N days, and runs them through the same sync functions. Customers are synced as the objects that use them are. Stripe only keeps its event log for about 30 days, so history is rebuilt from current objects rather than replayed events; older subscriptions get their key milestones (started, canceled) rather than every change.
- **Nightly sync** re-runs the importer for the last three days to catch any missed webhook.
- **Existing Payment Link donations** have no checkout record or metadata from our system. They are imported like any other payment: matched to a user by email (some may not have a Stripe Customer), and assigned to the General Fund.
- **Test and live mode** never mix. Each environment has its own database and one set of Stripe keys: local development and staging use test mode, and production uses live mode only. The mode is enforced, not just configured: only `APP_URL` `https://wildland.eco` may use a live key, and every deploy runs `stripe:check`, which fails on a key for the wrong mode. Records don't store a mode, since every row in a database comes from that environment's mode. Instead, sync and import compare the `livemode` flag on every Stripe object and webhook event with the mode the server is configured for, and reject a mismatch as an error instead of saving it.
- **Webhook events handled:** `checkout.session.completed`, `expired`, `async_payment_succeeded`, and `async_payment_failed`; `invoice.paid` and `invoice.payment_failed`; `payment_intent.succeeded` and `payment_intent.payment_failed`; `charge.refunded`; `customer.subscription.created`, `updated`, and `deleted`; and `customer.updated`. They are listed with their sync functions in `StripeEventProcessor::EVENTS`, confirmed against the pinned API version (`2026-09-30.endive`), where invoices and payments link through Stripe's invoice payments rather than directly.

## Designations

A **designation** is what a donor directed their donation toward. Every donation, subscription, and checkout has a `designation_id`, which defaults to the General Fund, and the designation's slug travels in Stripe metadata.

For the MVP, all donations go to the General Fund and the donate page has no selector. Enabling project-specific giving later means adding designations (for example, High Park Burn Scar or Wetland Restoration) and a selector on the donate page; designated donations are restricted funds, which `is_restricted` records for accounting. Splitting one donation across several designations would add an allocations table without changing the rest of the schema.

"Campaigns" is reserved for a different future concept: time-bound appeals (such as a spring tree-planting drive), used to track what prompted a donation.

## Deletion

- **Soft deletes.** Tables holding people and money have a nullable `deleted_at` column and use Eloquent's soft deletes: `users`, `designations`, `gateway_customers`, `checkouts`, `subscriptions`, `donations`, `donation_receipts`, and `leaf_applications`. Soft-deleted rows stay in the database and are hidden from normal queries.
- **Append-only logs are never deleted.** `subscription_events` and `webhook_events` record what happened and have no `deleted_at`.
- **Foreign keys restrict deletes.** The database refuses to hard-delete a row that another row references, as a safeguard behind the application rules.
- **Users with transaction history cannot be deleted**, soft or hard. Transaction history means a payment we actually received: a one-time or recurring donation that succeeded, including one refunded later. Refunds are kept for accounting, and a refunded payment still counts. Failed payments, unpaid checkouts, and subscriptions that never collected a payment do not count. A user without any, such as a spam account, can be soft-deleted, and their email address stays reserved by the soft-deleted row.
- **Failed payments are recorded**, but they do not protect an account from deletion. Donation forms are a common target of carding attacks, where stolen card numbers are tested with small donations, leaving an account full of failed payments. Soft-deleting such an account soft-deletes its failed donations, checkouts, and subscriptions with it, so they stay out of reports such as the failed payment rate.
- **Hard deletes** happen only when absolutely necessary, as a deliberate one-off action rather than a feature of the site.

## Planned Schema

Money is stored as integer cents with a `currency` column. Status and type fields are strings, per the [schema conventions](05-ai-development-notes.md#schema-conventions). Every table has `created_at` and `updated_at`. Soft deletes and foreign keys follow [Deletion](#deletion).

### users

| Column | Notes |
|--------|-------|
| name | Nullable |
| email | Unique; stored lowercase, so finding a donor by email is case-insensitive |
| email_verified_at | Nullable timestamp |
| password | Nullable; donors can always sign in with an email link |
| role | String, default `donor` (`donor`, `admin`) |
| remember_token | Nullable |
| deleted_at | Nullable; soft deletes |

### designations

| Column | Notes |
|--------|-------|
| name, slug | Slug unique; seeded with General Fund (`general-fund`) |
| description | Nullable |
| is_restricted | Boolean; false for the General Fund |
| is_active, sort_order | Controls the future donate-page selector |
| deleted_at | Nullable; soft deletes |

### gateway_customers

Links a user to one or more customer records at a payment gateway (a donor may have several in Stripe).

| Column | Notes |
|--------|-------|
| user_id | Foreign key |
| gateway, gateway_customer_id | Unique together |
| deleted_at | Nullable; soft deletes |

### checkouts

Every attempt to donate, including ones never paid.

| Column | Notes |
|--------|-------|
| reference | Unique public identifier (UUID), sent to the gateway as metadata |
| user_id, designation_id | Foreign keys |
| amount_cents, currency | |
| frequency | `one_time`, `monthly`, `annual` |
| status | `started`, `completed`, `expired` |
| gateway, gateway_session_id | Session ID unique together with gateway |
| expires_at, completed_at, reminder_sent_at | Nullable timestamps |
| deleted_at | Nullable; soft deletes |

### subscriptions

| Column | Notes |
|--------|-------|
| user_id, designation_id | Foreign keys |
| gateway, gateway_subscription_id | Unique together |
| amount_cents, currency | |
| interval | `month`, `year` |
| status | `incomplete`, `active`, `past_due`, `unpaid`, `paused`, `canceled` |
| started_at, current_period_end, cancel_at, canceled_at | Nullable timestamps |
| gateway_data | JSONB copy of the latest gateway object |
| deleted_at | Nullable; soft deletes |

### donations

One row per payment, one-time or recurring.

| Column | Notes |
|--------|-------|
| user_id, designation_id | Foreign keys |
| subscription_id, checkout_id | Nullable foreign keys |
| gateway, gateway_payment_id | Unique together; the Stripe PaymentIntent |
| gateway_invoice_id | Nullable; set for subscription payments |
| amount_cents, fee_cents, refunded_cents, currency | Fee nullable; refunded defaults to 0 |
| status | `pending`, `succeeded`, `failed`, `refunded`, `partially_refunded` |
| paid_at | Nullable timestamp |
| gateway_data | JSONB copy of the latest gateway object |
| deleted_at | Nullable; soft deletes |

### subscription_events

The history of a recurring donation in our own vocabulary, independent of the gateway. Powers the donor timeline and churn and retention reports. Append-only; never deleted.

| Column | Notes |
|--------|-------|
| subscription_id | Foreign key |
| type | `created`, `renewed`, `payment_failed`, `amount_changed`, `paused`, `resumed`, `canceled`, `reactivated` |
| source | `webhook`, `import`, `admin`, `donor` |
| source_reference | The event, invoice, or action that caused it; unique with subscription and type, so re-imports don't duplicate history |
| occurred_at | Timestamp |
| data | JSONB, nullable |

### webhook_events

Raw log of every incoming webhook, for deduplication, debugging, and replay. Append-only; never deleted.

| Column | Notes |
|--------|-------|
| gateway, event_id | Unique together |
| type | |
| payload | JSONB |
| processed_at, attempts, error | Processing status |

### donation_receipts

| Column | Notes |
|--------|-------|
| user_id | Foreign key |
| donation_id | Nullable; set for receipts for a single donation |
| receipt_number | Unique |
| type | `single`, `annual_summary` |
| tax_year, amount_cents | |
| sent_at | Nullable timestamp |
| deleted_at | Nullable; soft deletes |

## Content Pages

Can start any time after milestone 4:

- Individual pages for each project area (High Park, GE Moreau)
- Individual pages for each program (leaf collection, wetland restoration, wildfire mitigation, permaculture). Leaf collection is built at `/leaves`.
- About page
- FAQ: built at `/faq`
- Blog / news section for posting updates (supplementing Instagram/LinkedIn)

Project area pages can replace the home page project modals' no-JS fallback once they exist.

## Analytics, Mapping, and IoT

Later phases, built on PostGIS:

- User metrics and analytics built into the platform
- Project area boundaries and restoration activity tracking (tree planting, BDAs) with data from drone surveys
- IoT device dashboards (water barrel irrigation system status)
- Real-time monitoring displays for restoration sites

## Deferred

- Designation selector on the donate page
- Signed release tags for production deploys
- Campaign tracking and split allocations
