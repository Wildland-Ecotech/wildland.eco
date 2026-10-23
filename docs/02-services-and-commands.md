# Services & Commands

Planned services and commands (payment gateway sync, history import, receipts, reporting) are described in [Planned Features](06-planned-features.md).

## Services

### ProofOfWork (`app/Services/ProofOfWork.php`)

Proof-of-work spam protection for public forms, used by the leaf application form.

- `issue()` returns a challenge, `<random hex>.<issued at>.<prefix>.<signature>`, signed with an HMAC of the application key so the prefix and issue time cannot be changed. Nothing is stored when a challenge is issued.
- In the browser, `resources/js/proof-of-work.js` finds a nonce such that SHA-256 of `<challenge>:<nonce>` starts with the prefix: `CHARACTER` (`d`) repeated `DIFFICULTY` (4) times, `dddd`. That takes about 65,000 hashes on average, roughly a second. The character is an arbitrary choice; every hex digit is equally likely, so any prefix of the same length is equally hard.
- `verify($challenge, $nonce)` checks the signature, that the challenge is less than `LIFETIME` (2 hours) old, and the hash. It then records the challenge in the cache until it expires, so each challenge can be used only once.

Raising `DIFFICULTY` by one makes the work 16 times harder for people as well as bots.

Document each service class and artisan command here as it is added: its purpose, inputs, side effects, and how to run it.

## Artisan Commands

### leaves:applications (`app/Console/Commands/ListLeafApplications.php`)

Lists leaf program applications from `/leaves/apply`, oldest first, for reviewing them until the administrator pages are built (milestone 10). Times are shown in Fort Collins time.

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
