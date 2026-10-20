# Planned Features

The development plan for the wildland.eco Laravel platform: accepting donations and giving donors a member account. Update this document as milestones complete and decisions change.

## Guiding Decisions

- **Server-rendered Blade, no frontend framework.** Every public page is rendered by Laravel and works without JavaScript; small vanilla JS modules add animations and conveniences. A JavaScript framework would need either a server-side rendering Node process in production or pages rendered only in the browser.
- **No Node.js at runtime.** Node is only used to build CSS and JS assets.
- **Docker in every environment.** Local development, staging, and production run the same `docker-compose.yml`; differences live only in each environment's `.env` files.
- **PostgreSQL 17 + PostGIS.** PostGIS is not needed for donations, but it gives us accurate area and distance calculations, coordinate system conversion for drone and LIDAR data, raster storage, and direct QGIS access for the mapping work planned below.
- **Gateway-neutral payments.** Stripe is used through the Stripe PHP SDK directly (no Laravel Cashier). Stripe calls sit behind our own payment gateway interface, and database columns use `gateway` and `gateway_*_id` instead of Stripe-specific names, so we can move from Stripe-hosted pages to our own UI, or to another processor, without a schema rewrite.
- **Stripe-hosted pages for the MVP.** Stripe Checkout collects payments and the Stripe Customer Portal handles payment methods and cancellation. Both are replaceable later with on-site pages.
- **Every payment is re-importable.** Webhooks and a history importer write through the same idempotent sync code, so any record can be imported again without creating duplicates.
- **Email is always collected; passwords are optional.** Donors give an email address to donate and get an account automatically. Setting a password is offered only after the donation.
- **Transactional email through AhaSend** over SMTP from `no-reply@wildland.eco`. Proton Mail remains the mailbox for human email.
- **Pull-based deploys.** Staging updates automatically when changes are merged into `main`, and production when changes are merged into `release`, matching other Wetfish projects. The server pulls code when GitHub notifies it of a push; no SSH keys or server credentials are stored on GitHub.

## Milestones

| # | Milestone | Status |
|---|-----------|--------|
| 1 | Laravel setup with Wetfish Skeleton | Done |
| 2 | Consolidate documentation and record this plan | Done |
| 3 | Convert the site to Blade, SCSS, and vanilla JS | Done |
| 4 | New production server, webhook deploys, launch at parity | Launched; backups remaining |
| 5 | Donation database schema and models | Next |
| 6 | Stripe Checkout, webhooks, and history import | Planned |
| 7 | Donation receipts and transactional email | Planned |
| 8 | Donor accounts and member dashboard | Planned |
| 9 | Administrator pages and reporting | Planned |
| 10 | End-to-end testing in Stripe test mode, then go live | Planned |

Content pages can be built any time after milestone 4. Stripe test mode is used from milestone 6 onward, not only in milestone 10.

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

- **Backups:** nightly `pg_dump` of the production database, kept off the server.
- **`prod.wildland.eco` redirect:** redirect everything except `/hooks/` to `https://wildland.eco`, so search engines treat it as an alias. It currently serves the site with a `noindex` header.
- **Retire the old static server** once DNS has fully propagated.
- **Future hardening:** deploy only release tags signed with a maintainer's hardware key (YubiKey), verified on the server against an allowed-signers file that never leaves the server. A compromised GitHub account then cannot deploy code to production.

The queue worker and scheduler containers are added in milestone 5, when the queue tables exist; the deploy script then also restarts the queue worker.

### 5. Database Schema

See [Planned Schema](#planned-schema). Includes models, relationships, factories, and seeding the General Fund designation. The framework tables for the cache, queue, and password resets are generated in this milestone, and local development switches `CACHE_STORE` and `QUEUE_CONNECTION` to `database`.

This milestone also sets up the test database: a separate PostgreSQL + PostGIS database in the Docker Compose setup, matching production, replacing the in-memory SQLite database that Laravel's default `phpunit.xml` configures. Tests must never run against SQLite or the development database.

### 6. Stripe Integration

- `PaymentGateway` interface with a Stripe implementation; the Stripe API version is pinned in configuration.
- Donate form → Checkout Session → Stripe-hosted payment page → thank-you page.
- Webhook endpoint that verifies Stripe's signature, stores the event, and processes it on the queue.
- History importer command and a scheduled nightly sync. See [Payment Sync and Import](#payment-sync-and-import).
- Customer Portal session for managing recurring gifts.
- Enable the donate button and restore the Donate links in the home page footer, replacing the test that checks no page links to `/donate`.

### 7. Receipts and Email

- One receipt email per successful gift: organization name, EIN, amount, date, receipt number, and a statement that no goods or services were provided in exchange. Written acknowledgment matters most for single gifts of $250 or more, which donors need in order to claim the deduction.
- Year-end giving summaries per donor.
- Receipt emails include the account setup link (see [Accounts](#accounts)).
- Mailpit (a local mail-catching container) is added to Docker for development. Staging and production send through AhaSend (`send.ahasend.com`, port 587 with STARTTLS) with an SMTP credential created in the AhaSend dashboard, after verifying the wildland.eco sending domain with its SPF, DKIM, and DMARC records.

### 8. Accounts and Member Dashboard

See [Accounts](#accounts). The dashboard shows donation history, active recurring gifts, receipts, and the designation each gift supported, with a button that opens the Stripe Customer Portal.

### 9. Administrator Pages

Role-gated pages for admins and board members: donor search, donation and subscription lists with filters, a donor detail page with full history, CSV export, and the reports below. Refunds are issued from the Stripe dashboard and synced back automatically.

**Reports:**

- Total donations by month, quarter, and year
- Active vs. churned recurring donors
- Monthly recurring revenue (MRR)
- Failed payment rate and recovery
- Average donation size by frequency
- Donor retention
- Year-end receipt generation

### 10. Testing and Go-Live

- End-to-end runs in Stripe test mode covering one-time and recurring gifts, failed payments, refunds, cancellation, abandoned checkouts, re-imports, and webhook retries.
- Privacy policy (including email collection and checkout reminders) and refund policy pages.
- Switch production to live keys, register the live webhook endpoint, and import live account history.

## Donation Flow

1. The donor chooses a frequency and amount and enters their email address (name optional).
2. The server finds or creates a user by email (no password), records a `checkouts` row, creates a Checkout Session with the checkout's reference in its metadata, and redirects to Stripe.
3. Stripe collects payment. Webhooks record the donation, and the subscription for recurring gifts.
4. The thank-you page confirms the gift and offers an optional password (see [Accounts](#accounts)).
5. If the donor leaves without paying, the Checkout Session expires and the donor may receive one reminder email (see [Abandoned Checkouts](#abandoned-checkouts)).

## Accounts

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

- A checkout that expires without payment is marked `expired`. Stripe Checkout Sessions expire after a configurable period, and we receive an expiration webhook.
- At most one reminder email is sent per checkout, and reminders to the same address are rate-limited, so the donate form cannot be used to flood someone's inbox.
- Reminders include an unsubscribe link, and the privacy policy discloses them.

## Payment Sync and Import

**Idempotency comes from keying records on the gateway's own IDs, not from filtering events.** A donation is identified by the payment it represents, so receiving the same payment through several webhook events, a retried webhook, or a re-run import always updates one row.

- **One sync function per object type** (customer, subscription, payment, refund). Each fetches the current object from Stripe and upserts it by `(gateway, gateway_*_id)`. Webhooks only signal which object changed, so out-of-order events are harmless.
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

## Planned Schema

Money is stored as integer cents with a `currency` column. Status and type fields are strings, per the [schema conventions](05-ai-development-notes.md#schema-conventions).

### users

| Column | Notes |
|--------|-------|
| name | Nullable |
| email | Unique; stored lowercase, so finding a donor by email is case-insensitive |
| email_verified_at | Nullable timestamp |
| password | Nullable; donors can always sign in with an email link |
| role | String, default `donor` (`donor`, `admin`, `board_member`) |
| remember_token | Nullable |

### designations

| Column | Notes |
|--------|-------|
| name, slug | Slug unique; seeded with General Fund (`general-fund`) |
| description | Nullable |
| is_restricted | Boolean; false for the General Fund |
| is_active, sort_order | Controls the future donate-page selector |

### gateway_customers

Links a user to one or more customer records at a payment gateway (a donor may have several in Stripe, and separate test and live records).

| Column | Notes |
|--------|-------|
| user_id | Foreign key |
| gateway, gateway_customer_id | Unique together |
| livemode | Boolean |

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

### subscription_events

The history of a recurring gift in our own vocabulary, independent of the gateway. Powers the donor timeline and churn and retention reports.

| Column | Notes |
|--------|-------|
| subscription_id | Foreign key |
| type | `created`, `renewed`, `payment_failed`, `amount_changed`, `paused`, `resumed`, `canceled`, `reactivated` |
| source | `webhook`, `import`, `admin`, `donor` |
| source_reference | The event, invoice, or action that caused it; unique with subscription and type, so re-imports don't duplicate history |
| occurred_at | Timestamp |
| data | JSONB, nullable |

### webhook_events

Raw log of every incoming webhook, for deduplication, debugging, and replay.

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

## Content Pages

Can start any time after milestone 4:

- Individual pages for each project area (High Park, GE Moreau)
- Individual pages for each program (leaf collection, wetland restoration, wildfire mitigation, permaculture)
- About page
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
- On-site payment form and subscription management, replacing Stripe Checkout and the Customer Portal
- Changing the amount of an existing recurring gift (for the MVP, donors cancel and start a new gift)
- Signed release tags for production deploys
- Campaign tracking and split allocations
