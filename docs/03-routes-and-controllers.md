# Routes & Controllers

## Current Routes

| Method | URI | Handler | Notes |
|--------|-----|---------|-------|
| GET | `/` | Closure in `routes/web.php` | Laravel's default welcome page |

The public pages (`/`, `/donate`, `/menu`) are still served by the static SvelteKit build in `ui/`. They move to Laravel routes in [milestone 3](06-planned-features.md#3-convert-the-site-to-blade).

Document each route here as it is added, along with the controller responsible and the request flow for anything non-trivial (forms, webhooks, redirects to the payment gateway).
