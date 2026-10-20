# Database Schema

PostgreSQL 17 with PostGIS 3.5 (see [AI Development Notes](05-ai-development-notes.md#database-connections) for connection details). The PostGIS extension is available in the Docker image but is not enabled yet; it will be created in a migration when the first spatial table is added.

The planned donation schema is described in [Planned Features](06-planned-features.md#planned-schema). This document lists only the tables that currently exist.

## Current Tables

None. Laravel's default migrations were removed so that every migration in this project is generated with `php artisan make:migration` and carries the timestamp of when it was created (see [Migration Generation](05-ai-development-notes.md#migration-generation)). The users table and the framework tables for sessions, cache, queues, and password resets will be generated when the features that need them are built.

Until those tables exist, local development uses drivers that do not need the database:

| Setting | Value | Switches to `database` when |
|---------|-------|-----------------------------|
| `SESSION_DRIVER` | `file` | Accounts are built (milestone 8) |
| `CACHE_STORE` | `file` | The cache table is added (milestone 5) |
| `QUEUE_CONNECTION` | `sync` | The queue tables are added (milestone 5) |
