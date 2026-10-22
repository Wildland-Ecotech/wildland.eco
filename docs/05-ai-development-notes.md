# AI Development Notes

This project is being built with the assistance of Claude (Anthropic). The following conventions must be maintained for consistent, high-quality output. These notes serve as a reference for both the AI assistant and the developer.

## Before Starting Work

Review the relevant documentation files in this `docs/` directory before beginning any development work. At minimum, check:

- `01-database-schema.md` for current table structure
- `02-services-and-commands.md` for existing service classes and artisan commands
- `03-routes-and-controllers.md` to understand the current route/controller layout
- `04-frontend.md` for Blade template structure and UI conventions
- `06-planned-features.md` for the agreed development plan and design decisions
- `07-deployment.md` for local development and server setup
- `08-style-guide.md` for colors, text roles, layout, and writing conventions

This ensures new work builds on existing patterns rather than introducing inconsistencies.

## Never Guess Implementation Details

Never guess function names, file contents, or implementation details. Read the relevant source in the repository before building on it, including packages under `laravel/vendor/` when a dependency's behavior matters. A dependency often already provides what is needed, so check before reinventing it.

This applies to every dependency, not only PHP packages: Debian packages, Docker images, and other tools. Read the source or documentation for the version actually installed (for example, the webhook listener's behavior was checked against the source of the version Debian packages), since behavior changes between versions.

If the information is not in the repository (server configuration, account settings, credentials, or local changes that have not been pushed), ask the developer for it rather than assuming.

## Keep Documentation Updated

When development work introduces new files, database columns, design decisions, or changes to existing patterns, update the relevant documentation files as part of the same session. Documentation should always reflect the current state of the project, not lag behind it.

## Provide Full File Artifacts

When making changes to existing files, always provide the complete updated file rather than piecemeal diffs, snippets, or partial patches. The developer will review and apply the full file directly. This avoids ambiguity about where changes go and reduces the risk of merge errors.

Present work one commit at a time: the `codium` commands and full file artifacts for that commit, QA steps, and a commit message. Wait for the developer to review and ask to continue before presenting the next commit.

When a commit changes files that an earlier, not yet committed commit also changed, its full files already include the earlier changes. Say which commit to apply first.

The Claude interface shows at most 20 artifacts per message, and the rest are silently dropped. Keep each message under 20 files; if a commit needs more, split it into smaller commits that each leave the application working.

## Artifact File Extensions

The Claude interface can only display artifacts with common file extensions. Deliver every other file as a plain text file with a `.txt` extension and a descriptive name, and use the `codium` command to show where the content belongs. This includes:

- Files with no extension: `Dockerfile` (`webhook-Dockerfile.txt`), `production.Caddyfile` (`production-Caddyfile.txt`)
- Dotfiles and examples: `.env`, `laravel/.env.example` (`laravel-env-example.txt`), `.gitignore`
- Configuration with uncommon extensions, such as systemd `.path` and `.service` units

When unsure whether an extension displays, use `.txt`.

## File Path References

When referencing files for the developer to open, always provide full file paths relative to the repo root using `codium` commands:

```bash
codium README.md
codium docker/php/custom.ini
codium laravel/app/Http/Controllers/ExampleController.php
```

This convention ensures the developer can copy-paste commands directly without needing to figure out where a file lives.

## Artifact Ordering

When providing downloadable file artifacts alongside a list of `codium` commands, the `codium` commands must be listed in the same order as the artifacts appear in the download list. This prevents confusion when the developer is opening files sequentially to paste content into.

For example, if three artifacts are provided in order — Controller, View, Routes — the commands should be:

```bash
codium laravel/app/Http/Controllers/ExampleController.php
codium laravel/resources/views/example/index.blade.php
codium laravel/routes/web.php
```

## Artifact Naming

When providing multiple files with the same filename (e.g., multiple `index.blade.php` files), use descriptive artifact names so they are distinguishable in the download list. For example: "Users index.blade" and "Settings index.blade" rather than two files both named "index.blade".

## Code Block Formatting

Always use an explicit language identifier on every code block (e.g., `bash`, `ini`, `text`, `php`). Include a descriptive title line before each block.

Bare ` ``` ` without a language tag causes consecutive blocks to merge into a single block in the Claude chat renderer. This was identified early and must be avoided throughout all documentation and chat output.

## Commit Messages

Draft a short commit message to explain what we've built. Don't explain intermediary steps, don't explain our iterations and internal dev process, only explain the final state we agreed upon. We want to produce something people will actually read, not gloss over a wall of text.

- **Simple changes get a single line**, 80 characters or less, with no bullets.
- **Bullets only when a larger commit or PR warrants them:** a subject line plus a few short bullet lines, 256 characters at most in total.

The same applies to PR descriptions.

## Commit Scope

Split larger features into separate, logically isolated commits (for example: database and back-end changes, refactoring or helper changes, UI work, documentation) so each one can be reviewed on its own. Avoid touching too many files in a single commit, but don't split so finely that the commit count itself becomes hard to follow. Each commit should leave the application in a working state.

## Testing and Verification

Do not run long-running, resource-heavy commands inside the AI sandbox. The sandbox is resource constrained and these commands are slow, which gets in the way of iterating quickly. This includes:

- The test suite
- Installing or updating dependencies: `composer install`, `composer update`, `npm install`, `npm ci`
- Creating or installing Laravel projects and packages
- Building Docker images or starting containers
- Building frontend assets

If one of these seems necessary, provide the command for the developer to run instead. Quick checks are fine and expected:

- Run a syntax check on every changed file: `php -l` for PHP, `bash -n` or `sh -n` for shell scripts, and parse JSON and YAML files.
- Check that links between documentation files resolve.

Then provide the changed files along with QA testing steps: the test commands to run and any manual checks to perform. The developer runs them locally on a machine with more resources and reports back any failures.

Tests run against PostgreSQL in Docker, never SQLite. A separate test database, identical to production, keeps tests on the same code paths as production so they cannot pass on behavior that would break there. It runs as the `db-test` service (see [Running Tests](07-deployment.md#running-tests)). Feature tests that render pages call `$this->withoutVite()` so they do not depend on built assets.

Example QA commands handed to the developer:

```bash
docker compose --profile testing up -d db-test
docker compose exec app php artisan test --filter=DonationSyncTest
docker compose exec app php artisan test
```

## Deployment Changes

When a commit changes how the servers run, its QA steps say what happens when it is deployed and what, if anything, the developer must do on the servers. See [Deployment](07-deployment.md).

- **Applied by the next deploy:** application code, Docker images and their build files (`docker compose build` rebuilds changed images and `up` recreates only the changed containers), `docker-compose.yml`, and Caddyfiles (reloaded on every deploy).
- **Need a manual step:** systemd units are copied onto the host, so re-run `sudo deploy/setup.sh`; changes to a server's `.env` files need a manual deploy (`sudo systemctl start wildland-deploy`).
- **Configuration a container reads only at startup** is copied into its image rather than mounted, so changing it rebuilds the image and recreates the container.
- **Changes to the deploy process itself** are first deployed by the old version. Describe how that first deploy behaves.

Server rules that must hold:

- Containers never run as root. Deploys and containers run as the clone's owner.
- Published ports are bound to `127.0.0.1`. Docker's published ports bypass host firewalls such as ufw.
- Comments in `.env` files go on their own lines, never after a value, because `deploy/deploy.sh` reads values literally.
- No credentials or SSH keys are stored on GitHub; servers pull from the public repository.
- Git commands on a server run as the clone's owner (`sudo -u deploy git status`).

## Webhook Responses

Webhook endpoints, both the deploy webhook and the payment gateway webhooks planned in milestone 6, return an error only when something is actually wrong, such as a missing or invalid signature or a failure while processing. Expected outcomes succeed with a 2xx response and a short message: a push to a branch this server does not deploy, an event type we do not handle, or an event already processed. An error then always means a real problem, and senders that retry failed deliveries (Stripe retries for days and can disable an endpoint) are not sent into retry loops.

## Artisan Commands

Run artisan commands through Docker Compose in every environment. The `app` container's working directory is already set to the Laravel project root:

```bash
docker compose exec app php artisan make:migration create_example_table
docker compose exec app php artisan migrate
```

On servers, deploys already run migrations and cache configuration. For a manual command, run it from the clone's directory as the clone's owner (`deploy` when setup created that user):

```bash
sudo -u deploy docker compose exec app php artisan about
```

## Migration Generation

Always use Laravel's Artisan command to generate migration files. Never create migration files manually with dummy or sequential timestamps. The generated timestamp ensures correct ordering across environments and avoids conflicts when multiple sessions produce migrations on the same day.

When generating multiple migrations in sequence, add `sleep 1` between commands to ensure unique timestamps. The database requires referenced tables to exist before foreign keys point to them, and alphabetical ordering of same-timestamp migrations can cause failures:

```bash
docker compose exec app php artisan make:migration create_first_table
sleep 1
docker compose exec app php artisan make:migration create_second_table
```

## Progressive Enhancement

Every public page must render and work without JavaScript. Pages are rendered on the server with Blade, and JavaScript only enhances them (animations, live labels, overlays). Forms are real HTML forms that submit to the server and are validated there. Features that cannot work without JavaScript, such as the canvas border animation, need a CSS fallback.

The one agreed exception is proof-of-work spam protection on public forms, starting with the leaf application form: submitting requires JavaScript to solve the challenge. Such pages still render without JavaScript and show a `<noscript>` notice with another way to reach us (see [Frontend](04-frontend.md#apply-to-donate-leaves-leavesapply--pagesleaves-applybladephp)).

Do not add a runtime Node.js process (for example, server-side rendering) to production. Node is only used to build assets.

## Follow Instructions Literally

Do exactly what was asked for visible content: what to show, hide, link, or word a certain way. When an instruction could be read more than one way, ask before building rather than picking an interpretation.

## Page References

Do not add navigation links, buttons, or URLs pointing to pages that have not been built yet. Build the pages first, then add the references. This avoids broken links and confusion during development.

## Input Cleaning

All numeric form inputs should be cleaned server-side to strip commas, currency symbols, and whitespace before validation. Users should be able to type `$50,000.00` or `50,000` without errors.

## Validation Errors

All forms must display validation errors visibly to the user (typically in red text above or within the form) and preserve `old()` input values so users don't have to retype after a failure.

## Schema Conventions

All status and type fields use string columns instead of database enum types (MySQL `ENUM` columns or PostgreSQL enum types). Enums are difficult to modify in production migrations and cause issues with schema diffing tools. Expected values are documented in the schema docs and enforced in application logic.

Money is stored as integer cents (`amount_cents`) with a separate `currency` column, never as floats or decimals.

## Database Connections

This project uses PostgreSQL 17 + PostGIS 3.5.

When connecting to the PostgreSQL container from the host machine (e.g., DBeaver, pg_dump, or psql), use host `127.0.0.1` and the host port from the root `.env`. Docker exposes the database over TCP only.

From within the Docker network (e.g., in `laravel/.env`), use the Docker Compose service name `db` as the host and port `5432`.

To open a psql shell inside the container:

```bash
docker compose exec db psql -U wildland-eco -d wildland-eco
```

## PostgreSQL Conventions

Text comparison is case-sensitive in PostgreSQL. Use `ILIKE` (or `whereLike(..., caseSensitive: false)`) for case-insensitive searches rather than assuming MySQL's default collation behavior.

Create any required extensions (e.g., `postgis`, `pg_trgm`, `fuzzystrmatch`) in a migration with `DB::statement('CREATE EXTENSION IF NOT EXISTS ...')`, even if the Docker image already provides them, so that production databases end up with the same extensions. Creating an extension usually requires a superuser. The Docker database user is a superuser; on production servers, an administrator may need to create extensions once before migrations run.

## Privacy

Avoid committing real personal data, financial institution names, or other sensitive information to the repository. Use generic placeholders where needed.
