# Frontend

## Stack

- **Templates:** Blade, rendered on the server. Every public page works without JavaScript.
- **Styling:** SCSS compiled by Vite with Dart Sass (`sass`). No CSS framework.
- **JavaScript:** Small vanilla ES modules that enhance server-rendered markup. No frontend framework.
- **Build:** `npm run build` (or `npm run dev` for hot reloading) in `laravel/`, run on the host. Node is only used to build assets, never at runtime in production.

## Structure

All paths are relative to `laravel/`.

| Path | Purpose |
|------|---------|
| `resources/views/components/layout.blade.php` | Page shell (`<x-layout title="...">`): head, menu overlay, top nav |
| `resources/views/components/*.blade.php` | Shared components, used as `<x-top-nav />`, `<x-footer />`, etc. |
| `resources/views/pages/*.blade.php` | One view per page |
| `resources/scss/app.scss` | Sass entry point; `@use`s every partial |
| `resources/scss/_fonts.scss`, `_animations.scss`, `_base.scss` | Font faces, shared keyframes, global element and button styles |
| `resources/scss/_typography.scss` | Text role mixins (Lead, Detail, Fine print) from the [Style Guide](08-style-guide.md) |
| `resources/scss/components/`, `resources/scss/pages/` | One partial per component or page |
| `resources/js/app.js` | JavaScript entry point; initializes each enhancement |
| `resources/js/*.js` | One module per enhancement, attached to markup through `data-*` attributes |
| `resources/fonts/`, `resources/images/` | Fonts and design images, processed by Vite |
| `public/content/` | Photos used on pages, served as-is |
| `public/favicon.png` | Favicon |

## Conventions

- **Components:** Blade anonymous components in `resources/views/components/`. Props are declared with `@props` and defaults; kebab-case attributes map to camelCase props (`:show-donate="false"` sets `$showDonate`). A leading `:` passes a PHP expression instead of a string.
- **Style scoping:** Sass does not scope styles to a component. Each component or page partial nests all of its rules under its root class (for example, `.menu-overlay .links`), so generic class names cannot leak between components.
- **Keyframes are global.** Give each one a distinct name. Shared animations live in `_animations.scss`.
- **JavaScript hooks** use `data-*` attributes (`data-menu-toggle`, `data-modal-open`), not styling classes, so markup and styles can change without breaking scripts. Each module does nothing when its elements are not on the page.
- **Continuous animations pause while off screen.** Anything that animates every frame uses `watchOnScreen()` from `on-screen.js` (an `IntersectionObserver` that reports elements 200px before they scroll into view) to stop while nobody can see it, so it never competes with scrolling. Elements that are not displayed, such as inside a closed modal, count as off screen.
- **Progressive enhancement:** build the no-JavaScript version first, then enhance it.
  - Elements that only make sense with JavaScript start with the `hidden` attribute.
  - If CSS gives such an element a `display` value, restate `&[hidden] { display: none; }`.
  - Links fall back to real pages or anchors (`/menu`, `#high-park`).
- **Images in Sass** use paths relative to the partial (`url('../../images/bg-nature.png')`); Vite rewrites them at build time.

## Design System

Fonts, colors, text roles, layout, buttons and links, motion, and writing are in the [Style Guide](08-style-guide.md). Text sizes and colors come from the roles in `resources/scss/_typography.scss`; base element styles and the shared `.button` (with `.full`, `.dark`, and `.wide` modifiers) are in `_base.scss`.

## Pages

### Home (`/`) — `pages/home.blade.php`

- Illustrated mountain background with a firefly forest transition
- Banner asking visitors to get involved ("Get involved and help us fight back against wildfires"), with a Donate link to `/donate` and a Volunteer link that emails volunteer@wildland.eco, separated by a vertical line, and a rotating rainbow border (`rainbow-border.js`), drawn only while the banner is on screen; without JavaScript the border cycles colors in CSS
- The project modals link to Instagram, LinkedIn, and Facebook
- Three sections: Our Mission, What We Do (programs accordion), Our Projects (project cards that open modals)
- The Collecting Leaves program ends with a plain text link to `/leaves` ("Learn more about leaf collection"), styled as a link rather than a button so it isn't mistaken for the buttons that open sections
- Footer, with a Donate link

### Donate (`/donate`) — `pages/donate.blade.php`

- Built on the Content Page component; a plain HTML form validated on the server (see [Routes & Controllers](03-routes-and-controllers.md#donations))
- Frequency toggle (One-Time / Monthly / Annual) and tier cards: `[5, 20, 50]` one-time, `[20, 50, 100]` monthly, `[100, 1000, 5000]` annual. The amounts are defined in the `$frequencies` array at the top of the view.
- No frequency is chosen when the page loads, and only the frequency toggle shows, so the opening card stays short for visitors who want to read about our work first. Choosing a frequency reveals everything else (`.donate-details`): the tier cards, custom amount, name, email, and button. After a validation error, the chosen frequency stays checked, so the details stay open.
- Frequencies and tiers are visually hidden radio inputs styled through their labels. The frequency radios come first in the form, so a sibling selector (`~`) shows `.donate-details` once any frequency is checked, and the tier set for the checked frequency inside it, without JavaScript. Each frequency's tiers use their own field name (`amount_one_time`, `amount_monthly`, `amount_annual`).
- The custom amount, name, and email fields share one width (`$field-width` in `_donate.scss`).
- Custom amount field. `donate.js` filters input as it is typed (digits, one decimal point, two decimal places, $9,999 maximum), clears selections when the frequency changes, shows the amount on the button ("Donate $20/month"), and disables the button until an amount is chosen. Without JavaScript the button says "Donate", and the server checks the amount.
- Name and email fields, both required. Validation errors appear in a red summary above the form and under each field, and every field keeps its input, including the chosen frequency and suggested amount.
- An off-screen `website` field as a spam trap, and proof-of-work spam protection (`proof-of-work.js`), as on the leaf form. Paying needs JavaScript anyway, for Stripe's own pages, so a `<noscript>` notice gives the email address to give another way.
- While online giving is unavailable (no Stripe key), a notice says so and the button stays disabled (`data-unavailable` on the form).
- Submitting sends the donor to Stripe Checkout. Every other page's footer links here; the donate and thank-you pages hide the footer's Donate link (`:show-donate="false"`) and link back home instead.
- "What You're Supporting" cards, impact stats, "Other Ways to Help", and a footer that links back home.

### Donation Thank-You (`/donate/thank-you/{reference}`) — `pages/donate-thank-you.blade.php`

- Where Stripe returns the donor after paying, in the same card style as the donate page
- Thanks the donor for their donation by amount and frequency ("monthly donation of $20"), never showing their name or email, since the URL is the only thing protecting the page
- Notes that bank payments can take a few business days
- For monthly and annual donations, a "Manage Your Recurring Donation" button links to the Stripe Customer Portal login (`STRIPE_PORTAL_LOGIN_URL`), where the donor signs in with an emailed link
- A footer that links back home

### Leaves (`/leaves`) — `pages/leaves.blade.php`

- Built on the Content Page component
- Leads with the program's primary message, "Leave the Leaves": don't rake, and let yards grow back into meadows
- "Donate Your Leaves" for people who rake anyway, with Drop-Off (marked Preferred) and Pickup cards
- Leaf guidelines (paper bags and compostable yard waste only), and Privacy: contact details stay private, and which details about the leaves may be shared for grants and outreach
- "Apply to Donate Leaves" buttons after the "Donate Your Leaves" text (kept out of the opening card, whose message is not to rake) and after the Privacy section
- The opening card's text is Lead text (`.prose.lead`); everything below it is Detail text. Only section labels, headings, and buttons are centered.

### Apply to Donate Leaves (`/leaves/apply`) — `pages/leaves-apply.blade.php`

- Built on the Content Page component; a plain HTML form validated on the server (see [Routes & Controllers](03-routes-and-controllers.md#leaf-applications))
- Drop-off (selected by default, marked Preferred) or pickup, contact details, the address the leaves come from, an estimated bag count, and whether the bags are ready now. Donors never choose a pickup date or time; when the bags aren't ready, they give the soonest date they will be.
- The "when will they be ready" date is hidden with `:has()` while "Yes, they're ready now" is checked. Browsers without `:has()` always show it, and the server ignores it when the bags are ready.
- Validation errors appear in a red summary above the form and under each field, and every field keeps its input.
- Terms with a required "Yes, I agree" checkbox, and an off-screen `website` field as a spam trap
- Proof-of-work spam protection (`proof-of-work.js`, attached with `data-proof-of-work`): solving starts when the page loads, and if the form is submitted first, the button shows "Verifying…" until it finishes. The form needs JavaScript to submit: without it, a `<noscript>` notice gives the email address to apply by instead. The browser's `crypto.subtle` is only available over HTTPS and on `localhost`. A page restored from the back/forward cache reloads, since its challenge has already been used.
- After applying, the page shows a thank-you message for drop-off or pickup instead of the form

### FAQ (`/faq`) — `pages/faq.blade.php`

- Built on the Content Page component
- Each question is a native `<details>` element with an `id` for linking (such as `/faq#get-involved`), so questions open and close without JavaScript and several can be open at once. A plus sign turns into a minus sign when a question is open.
- Answers use `.prose`

### Menu (`/menu`) — `pages/menu.blade.php`

A full-page menu for visitors without JavaScript, with the same links and social media icons as the menu overlay.

## Components

### Layout (`layout`)

Page shell used by every page: `<head>` with meta tags, favicon, and Vite assets; the menu overlay; the top nav; then the page content (`$slot`). The `title` prop sets the page title.

### Top Nav (`top-nav`)

Fixed bar with the text wordmark "Wildland **Ecotech**" and the menu button.

### Menu Button (`menu-button`)

A 44px square link to `/menu` with a three-bar icon drawn as an inline SVG, centered in the button; a text character such as ≡ sits differently in each font. With JavaScript (`menu.js`), it toggles the menu overlay instead and keeps `aria-expanded` in sync.

### Menu Overlay (`menu-overlay`)

Full-screen blurred overlay with links to Home, Leaf Collection (`/leaves`, the page visitors from our flyers are looking for), FAQ (`/faq`), Volunteer (an email to volunteer@wildland.eco until there's a volunteers page), Contact Us, and Donate (`/donate`), the filled main-action button. Below the links, social media icons (`social-links`), left-aligned with the links. It scrolls when it's taller than the window. It repeats the top nav layout with an invisible wordmark, so its close button sits exactly where the menu button is. Escape or following a link closes it.

### Footer (`footer`)

Gradient footer with decorative bubbles, links to Instagram, LinkedIn, and Facebook under "Follow our progress", and the organization's EIN. Props with defaults:

- `color` (default: `'green'`)
- `heading` (default: `'Want to make a difference?'`)
- `title` (default: `'Help us restore wildlands for future generations.'`)
- `buttonText` / `buttonHref` (default: `'Get In Touch'` / `mailto:contact@wildland.eco`)
- `showDonate` (default: `true`)
- `showSocials` (default: `true`)

### Content Page (`content-page`)

Shell for standalone content pages such as `/donate`: the mountain and firefly backgrounds (the fireflies image fades in and out at its edges with a mask, as on the home page), a centered content column wrapped in Disappearing Content, and the page's footer in a `footer` slot. Pages add their own root class for their styles (`<x-content-page class="donate-page">`). `_content-page.scss` also provides `.section-divider`, the gradient line between sections, and `.prose`, a left-aligned 680px text column under centered headings. Text in `.prose` is Detail text, or Lead text with `.prose.lead` (see [Text Roles](08-style-guide.md#text-roles)).

### Disappearing Content (`disappearing-content`)

Wraps page content so it fades out as it scrolls under the top nav. `disappearing-content.js` recalculates a mask image on scroll and resize. The fade is positioned from the top of the content, so it stays correct when something below changes height (opening a `<details>` element, showing a form field) without being recalculated, and never flickers.

### Social Links (`social-links`) and Social Icon (`social-icon`)

`social-links` is a row of icon links to Instagram, LinkedIn, and Facebook, in the menu overlay and on `/menu`; each link has an `aria-label`, since it has no text. `social-icon` draws one outlined icon in the current text color (`<x-social-icon name="facebook" />`; names: `instagram`, `linkedin`, `facebook`), and is also used in the project modals' buttons.

### Modal (`modal`)

Dialog opened by links to `#id`. Props: `id`, `title`, `closeHref` (where the close link points without JavaScript). Without JavaScript, CSS shows the modal while its id is the URL fragment (`:target`). With JavaScript, `modal.js` opens and closes it with the `is-open` and `is-closed` classes without changing the URL, closes it on Escape or a backdrop click, and moves focus into the modal and back.

### Blob (`blob`) and Blob Shapes (`blob-shapes`)

Image clipped to an organic shape, with two gradient layers rotating behind it. Prop: `type` (shape 1–10); when set, the animation never shrinks below that shape. The background layers' shapes, speeds, and directions are picked randomly on each request.

`blob-shapes` holds the 10 SVG clip-path definitions and is rendered once per page by the first blob (`@once`). Without JavaScript, each blob keeps its static shape. With JavaScript, `blob.js` reads the outlines from those definitions, samples them into radii around the center, and morphs between random shapes. While a blob is off screen or not displayed (such as inside a closed modal), its morphing stops and its spinning background layers pause (`.is-paused`), and the morph resumes where it left off.

### Accordion

The programs accordion on the home page is markup rather than a component: `<details>` elements sharing `name="programs"`, so only one section is open at a time without JavaScript. `accordion.js` keeps the open section from closing when clicked again, hides the "tap to learn more" hint after the first click, scrolls the opened section into view, and closes other sections in browsers without exclusive `<details>` support.

## Known Issues and Notes

- `backdrop-filter: blur()` conflicts with DisappearingContent's mask. The donate section uses `rgba(0, 0, 0, 0.5)` without blur as a workaround.
- `backdrop-filter` makes `position: fixed` children position relative to the element instead of the window. The menu overlay pins its copy of the top nav to `top: 0; left: 0` for this reason.
- The favicon is still the Wetfish fish icon; it needs replacing with a Wildland Ecotech logo.
- Blob morphing uses polar coordinate sampling, which can produce slightly irregular shapes depending on the source paths. This is cosmetic and intentional.
