# Database Schema

PostgreSQL 17 with PostGIS 3.5 (see [AI Development Notes](05-ai-development-notes.md#database-connections) for connection details). The PostGIS extension is available in the Docker image but is not enabled yet; it will be created in a migration when the first spatial table is added.

The planned donation schema is described in [Planned Features](06-planned-features.md#planned-schema). This document lists only the tables that currently exist.

## Current Tables

These are Laravel's default tables, created by the migrations in `laravel/database/migrations/`.

### users

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | Primary key |
| name | string | |
| email | string | Unique |
| email_verified_at | timestamp | Nullable |
| password | string | Hashed |
| remember_token | string | Nullable |
| created_at, updated_at | timestamp | |

### password_reset_tokens

| Column | Type | Notes |
|--------|------|-------|
| email | string | Primary key |
| token | string | Hashed reset token |
| created_at | timestamp | Nullable |

### sessions

Database session storage. Local development uses file sessions (`SESSION_DRIVER=file`), so this table is currently unused.

### cache, cache_locks

Database cache storage (`CACHE_STORE=database`).

### jobs, job_batches, failed_jobs

Database queue storage (`QUEUE_CONNECTION=database`). Webhook processing and outgoing email will run through this queue.
