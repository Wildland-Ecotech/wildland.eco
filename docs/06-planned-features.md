# Planned Features

The development plan for the wildland.eco Laravel platform: accepting donations, then giving donors a member account. Update this document as milestones complete and decisions change.

## Guiding Decisions

- **Launch donations first, dashboards later.** Donations go live as soon as donors can give, receive receipts, and manage their recurring gifts. Donor accounts and administrator pages follow after launch; until then, reports are artisan commands, like `leaves:applications`.
- **Server-rendered Blade, no frontend framework.** Every public page is rendered by Laravel and works without JavaScript; small vanilla JS modules add animations and conveniences. A JavaScript framework would need either a server-side rendering Node process in production or pages rendered only in the browser.
- **No Node.js at runtime.** Node is only used to build CSS and JS assets.
- **Docker in every environment.** Local development, staging, and production run the same `docker-compose.yml`; differences live only in each environment's `.env` files.
- **PostgreSQL 17 + PostGIS.** PostGIS is not needed for donations, but it gives us accurate area and distance calculations, coordinate system conversion for drone and LIDAR data, raster storage, and direct QGIS access for the mapping work planned below.
- **Gateway-neutral payments.** Stripe is used through the Stripe PHP SDK directly (no Laravel Cashier). Stripe calls sit behind our own payment gateway interface, and database columns use `gateway` and `gateway_*_id` instead of Stripe-specific names, so we can move from Stripe-hosted pages to our own UI, or to another processor, without a schema rewrite.
- **Stripe-hosted pages for the MVP.** Stripe Checkout collects payments, and the Stripe Customer Portal handles payment methods and cancellation. Donors reach the portal through its no-code login link: they enter their email address and Stripe emails them a sign-in link, so managing a recurring gift needs no account on our site. Both are a quick MVP for accepting donations sooner: an on-site donation flow in the site's own design replaces them after launch (milestone 11).
- **Every payment is re-importable.** Webhooks and a history importer write through the same idempotent sync code, so any record can be imported again without creating duplicates.
- **Nothing is hard-deleted.** Records holding people and money are soft-deleted, so nothing is lost by accident, and a user with transaction history cannot be deleted at all. Hard deletes happen only when absolutely necessary. See [Deletion](#deletion).
- **Email is always collected; passwords are optional.** Donors give an email address to donate, which creates their user record automatically. Signing in to our site comes with accounts after launch, and setting a password is offered only after a donation.
- **Transactional email through AhaSend** over SMTP from `no-reply@wildland.eco`. Proton Mail remains the mailbox for human email.
- **Pull-based deploys.** Staging updates automatically when changes are merged into `main`, and production when changes are merged into `release`, matching other Wetfish projects. The server pulls code when GitHub notifies it of a push; no SSH keys or server credentials are stored on GitHub.

## Milestones

| # | Milestone | Status |
|---|-----------|--------|
| 1 | Laravel setup with Wetfish Skeleton | Done |
| 2 | Consolidate documentation and record this plan | Done |
| 3 | Convert the site to Blade, SCSS, and vanilla JS | Done |
| 4 | New production server, webhook deploys, launch at parity | Launched; backups deferred |
| 5 | Donation database schema and models | Next |
| 6 | Stripe Checkout, webhooks, and history import | Planned |
| 7 | Donation receipts and transactional email | Planned |
| 8 | Reporting commands, end-to-end testing in Stripe test mode, then go live | Planned |
| 9 | Donor accounts and member dashboard | After launch |
| 10 | Administrator pages and reporting | After launch |
| 11 | On-site donation flow and recurring gift management, replacing the Stripe-hosted pages | After launch |

Milestones 5 through 8 are the minimum for accepting donations. Content pages can be built any time after milestone 4. Stripe test mode is used from milestone 6 onward, not only in milestone 8.

### 3. Convert the Site to Blade

Done. `/`, `/donate`, and `/menu` are Blade pages styled with Sass and enhanced by small vanilla JavaScript modules. The structure and conventions are documented in [Frontend](04-frontend.md).

- Every page works without JavaScript: the programs accordion uses `<details>`, project modals open through anchor links and CSS `:target`, and the donate selector uses radio inputs.
- The donate form does not submit yet: its button stays disabled, and no page links to `/donate` until milestone 6.
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

The queue worker and scheduler containers are added in milestone 5, when the queue tables exist; the deploy script then also restarts the queue worker.

### 5. Database Schema

See [Planned Schema](#planned-schema). Includes the users table, models, relationships, factories, soft deletes and the rules in [Deletion](#deletion), and seeding the General Fund designation. The framework tables for the cache and queue are generated in this milestone, and local development switches `CACHE_STORE` and `QUEUE_CONNECTION` to `database`. The session and password reset tables come with accounts (milestone 9).

The test database was set up ahead of this milestone, for the leaf program's application form: the `db-test` service is a separate PostgreSQL + PostGIS database matching production, replacing the in-memory SQLite database that Laravel's default `phpunit.xml` configured. Tests never run against SQLite or the development database (see [Running Tests](07-deployment.md#running-tests)).

### 6. Stripe Integration

- `PaymentGateway` interface with a Stripe implementation; the Stripe API version is pinned in configuration.
- Donate form → Checkout Session → Stripe-hosted payment page → thank-you page.
- Checkout reuses a donor's existing Stripe customer, so the Customer Portal shows all of their recurring gifts together. When several Stripe customers share an email address, the portal's login link opens the most recently created one with an active subscription.
- Webhook endpoint that verifies Stripe's signature, stores the event, and processes it on the queue.
- History importer command and a scheduled nightly sync. See [Payment Sync and Import](#payment-sync-and-import).
- The thank-you page links to the Customer Portal login for managing recurring gifts.
- Enable the donate button and restore the Donate links in the home page footer, replacing the test that checks no page links to `/donate`.

### 7. Receipts and Email

- One receipt email per successful gift: organization name, EIN, amount, date, receipt number, and a statement that no goods or services were provided in exchange. Written acknowledgment matters most for single gifts of $250 or more, which donors need in order to claim the deduction.
- Receipts for recurring gifts link to the Customer Portal login.
- Mailpit (a local mail-catching container) is added to Docker for development. Staging and production send through AhaSend (`send.ahasend.com`, port 587 with STARTTLS) with an SMTP credential created in the AhaSend dashboard, after verifying the wildland.eco sending domain with its SPF, DKIM, and DMARC records.
- Year-end giving summaries are an artisan command, built after launch and run in January.

### 8. Reporting and Go-Live

- Reporting artisan commands, following `leaves:applications`: donations for a date range (with `--csv`), totals by month, quarter, and year, and active recurring gifts. The exact commands are decided when they are built.
- End-to-end runs in Stripe test mode covering one-time and recurring gifts, failed payments, refunds, cancellation through the Customer Portal, abandoned checkouts, re-imports, and webhook retries.
- Privacy policy (including email collection) and refund policy pages.
- Switch production to live keys, register the live webhook endpoint, and import live account history, including existing Payment Link donations.

### 9. Accounts and Member Dashboard

After launch. See [Accounts](#accounts). The dashboard shows donation history, active recurring gifts, receipts, and the designation each gift supported, with a button that opens a Customer Portal session until milestone 11 replaces it. Donors who gave before accounts existed already have a user record and sign in with an email link.

### 10. Administrator Pages

After launch. Role-gated pages for admins and board members: donor search, donation and subscription lists with filters, a donor detail page with full history, CSV export, and the reports below. Refunds are issued from the Stripe dashboard and synced back automatically. Administrators can soft-delete spam accounts that have no transaction history (see [Deletion](#deletion)).

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
- Donors manage recurring gifts from the member dashboard (milestone 9): update their payment method, change the amount, or cancel, replacing the Customer Portal. Until then, donors change an amount by canceling and starting a new gift.
- Stripe's payment fields need JavaScript. Following [Progressive Enhancement](05-ai-development-notes.md#progressive-enhancement), visitors without JavaScript still give through Stripe Checkout, which remains the fallback.
- The `PaymentGateway` interface and gateway-neutral columns keep this a change to the donation pages, not to the schema or the sync code.

## Donation Flow

1. The donor chooses a frequency and amount and enters their email address (name optional).
2. The server finds or creates a user by email (no password), records a `checkouts` row, creates a Checkout Session with the checkout's reference in its metadata, and redirects to Stripe.
3. Stripe collects payment. Webhooks record the donation, and the subscription for recurring gifts.
4. The thank-you page confirms the gift and links to the Customer Portal login for managing recurring gifts. After accounts are built, it also offers an optional password (see [Accounts](#accounts)).
5. If the donor leaves without paying, the Checkout Session expires and the checkout is marked `expired`. After launch, the donor may receive one reminder email (see [Abandoned Checkouts](#abandoned-checkouts)).

## Accounts

Built after launch (milestone 9). Until then, donors have a user record but do not sign in to our site, and they manage recurring gifts through the Customer Portal login link.

The goal is the least possible friction before a donor has committed, without exposing anyone's giving history to someone who merely typed their email address.

- **Accounts are created automatically** from the email entered on the donate page. No password is required to donate.
- **Optional password after donating.** The thank-you page offers "set a password", tied to the browser session that started the checkout. Password rules are a minimum length only, with no character-class requirements.
- **Unverified accounts see only their own checkout.** Typing an email address proves nothing about owning it, so until the address is verified, the account can only see the donation from that checkout.
- **Verification by email link.** Receipt emails include a signed link that verifies the address and signs the donor in. If a password was set before verification, verifying clears it and the verified owner sets their own.
- **Email login links.** Donors can always sign in with a one-time link sent by email, so a password is never required. The request form always responds the same way, whether or not an account exists, so it cannot be used to find out who has donated.
- **Existing accounts.** If the email already belongs to a verified account, the thank-you page asks the donor to sign in instead of offering a password.
- **Roles:** `donor`, `admin`, `board_member`.
- Authentication uses Laravel Fortify, with views written in the site's own design.

## Abandoned Checkouts

- A checkout that expires without payment is marked `expired`. Stripe Checkout Sessions expire after a configurable period, and we receive an expiration webhook. This is recorded from launch.
- Reminder emails come after launch. At most one reminder is sent per checkout, and reminders to the same address are rate-limited, so the donate form cannot be used to flood someone's inbox.
- Reminders include an unsubscribe link, and the privacy policy is updated to disclose them before they are sent.

## Payment Sync and Import

**Idempotency comes from keying records on the gateway's own IDs, not from filtering events.** A donation is identified by the payment it represents, so receiving the same payment through several webhook events, a retried webhook, or a re-run import always updates one row.

- **One sync function per object type** (customer, subscription, payment, refund). Each fetches the current object from Stripe and upserts it by `(gateway, gateway_*_id)`. Webhooks only signal which object changed, so out-of-order events are harmless.
- **Soft-deleted records are matched too.** Sync and import look records up by gateway ID including soft-deleted rows, so a re-import updates them without restoring them and never creates a duplicate.
- **Every webhook is stored** in `webhook_events` (unique per event ID), then processed on the queue. Failures are recorded and can be retried.
- **History importer** (`artisan` command): walks Stripe's customers, subscriptions, invoices, and payments for a date range and runs them through the same sync functions. Stripe only keeps its event log for about 30 days, so history is rebuilt from current objects rather than replayed events; older subscriptions get their key milestones (started, canceled) rather than every change.
- **Nightly sync** re-runs the importer for recent days to catch any missed webhook.
- **Existing Payment Link donations** have no checkout record or metadata from our system. They are imported like any other payment: matched to a user by email (some may not have a Stripe Customer), and assigned to the General Fund.
- **Test and live mode** never mix. Each environment uses one set of Stripe keys and imports only that mode's data; every synced record stores `livemode`, and records from the wrong mode are rejected. Production holds live data only.
- **Webhook events handled:** Checkout Session completed and expired; invoice paid and payment failed; payment succeeded and failed; charge refunded; subscription created, updated, and deleted; customer updated. Exact event names and payload fields are confirmed against the pinned Stripe API version during implementation.

## Designations

A **designation** is what a donor directed their gift toward. Every donation, subscription, and checkout has a `designation_id`, which defaults to the General Fund, and the designation's slug travels in Stripe metadata.

For the MVP, all gifts go to the General Fund and the donate page has no selector. Enabling project-specific giving later means adding designations (for example, High Park Burn Scar or Wetland Restoration) and a selector on the donate page; designated gifts are restricted funds, which `is_restricted` records for accounting. Splitting one gift across several designations would add an allocations table without changing the rest of the schema.

"Campaigns" is reserved for a different future concept: time-bound appeals (such as a spring tree-planting drive), used to track what prompted a gift.

## Deletion

- **Soft deletes.** Tables holding people and money have a nullable `deleted_at` column and use Eloquent's soft deletes: `users`, `designations`, `gateway_customers`, `checkouts`, `subscriptions`, `donations`, `donation_receipts`, and `leaf_applications`. Soft-deleted rows stay in the database and are hidden from normal queries.
- **Append-only logs are never deleted.** `subscription_events` and `webhook_events` record what happened and have no `deleted_at`.
- **Foreign keys restrict deletes.** The database refuses to hard-delete a row that another row references, as a safeguard behind the application rules.
- **Users with transaction history cannot be deleted**, soft or hard. Transaction history means a payment we actually received: a one-time or recurring donation that succeeded, including one refunded later. Failed payments, unpaid checkouts, and subscriptions that never collected a payment do not count. A user without any, such as a spam account, can be soft-deleted, and their email address stays reserved by the soft-deleted row.
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
| role | String, default `donor` (`donor`, `admin`, `board_member`) |
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

Links a user to one or more customer records at a payment gateway (a donor may have several in Stripe, and separate test and live records).

| Column | Notes |
|--------|-------|
| user_id | Foreign key |
| gateway, gateway_customer_id | Unique together |
| livemode | Boolean |
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
| livemode | Boolean |
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
| livemode | Boolean |
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
| livemode | Boolean |
| gateway_data | JSONB copy of the latest gateway object |
| deleted_at | Nullable; soft deletes |

### subscription_events

The history of a recurring gift in our own vocabulary, independent of the gateway. Powers the donor timeline and churn and retention reports. Append-only; never deleted.

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
| type, livemode | |
| payload | JSONB |
| processed_at, attempts, error | Processing status |

### donation_receipts

| Column | Notes |
|--------|-------|
| user_id | Foreign key |
| donation_id | Nullable; set for single-gift receipts |
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
