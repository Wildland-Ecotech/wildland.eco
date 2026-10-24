# Database Schema

PostgreSQL 17 with PostGIS 3.5 (see [AI Development Notes](05-ai-development-notes.md#database-connections) for connection details). The PostGIS extension is available in the Docker image but is not enabled yet; it will be created in a migration when the first spatial table is added.

The planned donation schema is described in [Planned Features](06-planned-features.md#planned-schema). This document lists only the tables that currently exist.

## Current Tables

Laravel's default migrations were removed so that every migration in this project is generated with `php artisan make:migration` and carries the timestamp of when it was created (see [Migration Generation](05-ai-development-notes.md#migration-generation)). The framework tables for the cache and queues are added with the donation schema (milestone 5); the session and password reset tables come with accounts (milestone 9).

### users

Donors and administrators. Model: `App\Models\User`. Donors are created automatically from the email address entered on the donate page, with no password. The table holds donors' personal contact details; never copy its rows into the repository, issues, or chat.

| Column | Notes |
|--------|-------|
| name | Nullable |
| email | Unique, including among soft-deleted users, so a deleted account keeps its address reserved. Stored lowercase by the model, so finding a user by email is case-insensitive |
| email_verified_at | Nullable timestamp |
| password | Nullable; hashed by the model. Donors can always sign in with an email link once accounts are built |
| role | String, default `donor`: `donor` or `admin` (`User::ROLES`). Board members are given `admin` until they need different permissions. Not mass assignable, so form input can never change it |
| remember_token | Nullable |
| created_at, updated_at | |
| deleted_at | Nullable; soft deletes (see [Deletion](06-planned-features.md#deletion)) |

### leaf_applications

Requests to donate leaves to the leaf program, by drop-off or pickup. Model: `App\Models\LeafApplication`. The table holds donors' personal contact details, which are never shared; never copy its rows into the repository, issues, or chat.

| Column | Notes |
|--------|-------|
| type | String: `drop_off` or `pickup` (`LeafApplication::TYPES`) |
| name | |
| email | Stored lowercase by the model, so finding a donor's applications by email is case-insensitive |
| phone | |
| address | Up to 500 characters; where the leaves are coming from |
| estimated_bag_count | Integer; the donor's estimate, since many don't know the exact count in advance. Large counts may need a special pickup trip, while small ones are batched with other pickups |
| ready_now | Boolean; whether the bags are ready now |
| ready_date | Nullable date, set when the bags are not ready yet: when they will be, the soonest they can be picked up or dropped off. Donors never choose a pickup date or time; we schedule pickups around routes and volunteers |
| referral_source | Nullable text; how the donor heard about us |
| questions | Nullable text |
| terms_accepted_at | Timestamp; when the donor agreed to the program's terms |
| created_at, updated_at | |
| deleted_at | Nullable; soft deletes. Deleted applications are kept but hidden, including from `leaves:applications` (see [Deletion](06-planned-features.md#deletion)) |

## Framework Drivers

Until the framework tables exist, local development uses drivers that do not need the database:

| Setting | Value | Switches to `database` when |
|---------|-------|-----------------------------|
| `SESSION_DRIVER` | `file` | Accounts are built (milestone 9) |
| `CACHE_STORE` | `file` | The cache table is added (milestone 5) |
| `QUEUE_CONNECTION` | `sync` | The queue tables are added (milestone 5) |
