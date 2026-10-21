# Routes & Controllers

## Current Routes

| Method | URI | Name | Handler | Notes |
|--------|-----|------|---------|-------|
| GET | `/` | `home` | `Route::view` → `pages.home` | Home page |
| GET | `/menu` | `menu` | `Route::view` → `pages.menu` | Full-page menu for visitors without JavaScript |
| GET | `/donate` | `donate` | `Route::view` → `pages.donate` | Donate page; the form does not submit until the Stripe integration (milestone 6), and no page links here until then |
| GET | `/leaves` | `leaves` | `Route::view` → `pages.leaves` | Leaf program: leave the leaves, or donate them by drop-off or pickup |

Pages with no server-side logic use `Route::view` and need no controller. Link to pages by route name (`route('donate')`) rather than hard-coded paths.

Document each route here as it is added, along with the controller responsible and the request flow for anything non-trivial (forms, webhooks, redirects to the payment gateway).
