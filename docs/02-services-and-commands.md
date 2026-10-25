# Services & Commands

Planned services and commands (payment gateway sync, history import, receipts, reporting) are described in [Planned Features](06-planned-features.md).

## Services

### ProofOfWork (`app/Services/ProofOfWork.php`)

Proof-of-work spam protection for public forms, used by the leaf application form.

- `issue()` returns a challenge, `<random hex>.<issued at>.<prefix>.<signature>`, signed with an HMAC of the application key so the prefix and issue time cannot be changed. Nothing is stored when a challenge is issued.
- In the browser, `resources/js/proof-of-work.js` finds a nonce such that SHA-256 of `<challenge>:<nonce>` starts with the prefix: `CHARACTER` (`d`) repeated `DIFFICULTY` (4) times, `dddd`. That takes about 65,000 hashes on average, roughly a second. The character is an arbitrary choice; every hex digit is equally likely, so any prefix of the same length is equally hard.
- `verify($challenge, $nonce)` checks the signature, that the challenge is less than `LIFETIME` (2 hours) old, and the hash. It then records the challenge in the cache until it expires, so each challenge can be used only once.

Raising `DIFFICULTY` by one makes the work 16 times harder for people as well as bots.

### StripeMode (`app/Payments/Stripe/StripeMode.php`)

Keeps Stripe's test and live modes apart. Only the production site may use live mode.

- `expected()` returns `live` when the host in `APP_URL` is `wildland.eco` (`LIVE_HOST`), and `test` for every other host, including staging and local development. The mode comes from `APP_URL` rather than its own setting, since staging deliberately runs with `APP_ENV=production`.
- `ofKey($key)` reads a key's mode from its prefix: `sk_live_` or `rk_live_` is live, and `sk_test_` or `rk_test_` is test.
- `assertValid()` throws a `RuntimeException` unless `STRIPE_SECRET` is set and its mode matches `expected()`. The message never includes the key.
- `expectsLivemode()` is for sync code, which rejects any Stripe object or event whose `livemode` flag doesn't match.

The `Stripe\StripeClient` singleton (bound in `AppServiceProvider`) calls `assertValid()` when it is first resolved and uses the API version pinned in `config/services.php`. The check runs then, not at boot, so a wrong key can never stop artisan commands, including the ones a deploy runs to fix it.

Document each service class and artisan command here as it is added: its purpose, inputs, side effects, and how to run it.

## Scheduled Tasks

Tasks are scheduled in `routes/console.php` and run by the `scheduler` container (see [Deployment](07-deployment.md)). None are scheduled yet; the nightly payment sync comes with the Stripe integration (milestone 6).

## Artisan Commands

### stripe:check (`app/Console/Commands/CheckStripeConfiguration.php`)

Checks that the Stripe settings match the mode this site must use, without calling Stripe. Deploys run it after caching the configuration, so a wrong key fails the deploy instead of taking payments in the wrong mode.

- `STRIPE_SECRET` empty: passes with a warning that payments are disabled, so servers deploy normally before Stripe is set up.
- `STRIPE_SECRET` set: fails if its mode doesn't match (see [StripeMode](#stripemode-apppaymentsstripestripemodephp)), or if `STRIPE_WEBHOOK_SECRET` (`whsec_`), `STRIPE_DONATION_PRODUCT_ID`, or `STRIPE_PORTAL_LOGIN_URL` is missing.

```bash
docker compose exec app php artisan stripe:check
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
