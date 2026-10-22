# Services & Commands

Planned services (payment gateway sync, history import, receipts) are described in [Planned Features](06-planned-features.md). No custom artisan commands exist yet.

## Services

### ProofOfWork (`app/Services/ProofOfWork.php`)

Proof-of-work spam protection for public forms, used by the leaf application form.

- `issue()` returns a challenge, `<random hex>.<issued at>.<prefix>.<signature>`, signed with an HMAC of the application key so the prefix and issue time cannot be changed. Nothing is stored when a challenge is issued.
- In the browser, `resources/js/proof-of-work.js` finds a nonce such that SHA-256 of `<challenge>:<nonce>` starts with the prefix: `CHARACTER` (`d`) repeated `DIFFICULTY` (4) times, `dddd`. That takes about 65,000 hashes on average, roughly a second. The character is an arbitrary choice; every hex digit is equally likely, so any prefix of the same length is equally hard.
- `verify($challenge, $nonce)` checks the signature, that the challenge is less than `LIFETIME` (2 hours) old, and the hash. It then records the challenge in the cache until it expires, so each challenge can be used only once.

Raising `DIFFICULTY` by one makes the work 16 times harder for people as well as bots.

Document each service class and artisan command here as it is added: its purpose, inputs, side effects, and how to run it.
