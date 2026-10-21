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
- **Progressive enhancement:** build the no-JavaScript version first, then enhance it.
  - Elements that only make sense with JavaScript start with the `hidden` attribute.
  - If CSS gives such an element a `display` value, restate `&[hidden] { display: none; }`.
  - Links fall back to real pages or anchors (`/menu`, `#high-park`).
- **Images in Sass** use paths relative to the partial (`url('../../images/bg-nature.png')`); Vite rewrites them at build time.

## Design System

### Fonts

- **Clash Display** (Semibold) — Headings (`clash`)
- **Red Hat Display** (Bold) — Section numbers, amounts (`redhat`)
- **Satoshi** (Medium) — Body text, buttons, UI elements (`satoshi`)

### Color Scheme

- **Page background:** `#010902`
- **Green accent:** `#2ECF9E`
- **Button fill:** `rgba(46, 207, 158, 0.25)`
- **Dark transparent:** `rgba(0, 0, 0, 0.3)` to `rgba(0, 0, 0, 0.5)`
- **Text primary:** `#fff`
- **Text secondary:** `rgba(255, 255, 255, 0.6)` to `rgba(255, 255, 255, 0.8)`

### Buttons

`.button` is the shared button style in `_base.scss`, with modifiers: `.full` (filled green), `.dark` (dark transparent), and `.wide` (90% width, used in the accordion and project cards).

## Pages

### Home (`/`) — `pages/home.blade.php`

- Illustrated mountain background with a firefly forest transition
- Social media banner with a rotating rainbow border (`rainbow-border.js`); without JavaScript the border cycles colors in CSS
- Three sections: Our Mission, What We Do (programs accordion), Our Projects (project cards that open modals)
- Footer, with its Donate link hidden until donations are enabled (milestone 6)

### Donate (`/donate`) — `pages/donate.blade.php`

- Built on the Content Page component
- Frequency toggle (One-Time / Monthly / Annual) and tier cards: `[5, 20, 50]` one-time, `[20, 50, 100]` monthly, `[100, 1000, 5000]` annual. The amounts are defined in the `$frequencies` array at the top of the view.
- Frequencies and tiers are visually hidden radio inputs styled through their labels. The frequency radios come first in the form, so a sibling selector (`~`) shows the tier set for the checked frequency without JavaScript. Each frequency's tiers use their own field name (`amount_one_time`, `amount_monthly`, `amount_annual`).
- Custom amount field. `donate.js` filters input as it is typed (digits, one decimal point, two decimal places, $9,999 maximum), clears selections when the frequency changes, and shows the amount on the button ("Donate $20/month").
- The donate button is disabled until Stripe is integrated (milestone 6), and no page links to `/donate` until then.
- "What You're Supporting" cards, impact stats, "Other Ways to Help", and a footer that links back home.

### Leaves (`/leaves`) — `pages/leaves.blade.php`

- Built on the Content Page component
- Leads with the program's primary message, "Leave the Leaves": don't rake, and let yards grow back into meadows
- "Donate Your Leaves" for people who rake anyway, with Drop-Off (marked Preferred) and Pickup cards
- Leaf guidelines (paper bags and compostable yard waste only), and Privacy: contact details stay private, and which details about the leaves may be shared for grants and outreach

### Menu (`/menu`) — `pages/menu.blade.php`

A full-page menu for visitors without JavaScript, with the same links as the menu overlay.

## Components

### Layout (`layout`)

Page shell used by every page: `<head>` with meta tags, favicon, and Vite assets; the menu overlay; the top nav; then the page content (`$slot`). The `title` prop sets the page title.

### Top Nav (`top-nav`)

Fixed bar with the text wordmark "Wildland **Ecotech**" and the menu button.

### Menu Button (`menu-button`)

A link to `/menu`. With JavaScript (`menu.js`), it toggles the menu overlay instead and keeps `aria-expanded` in sync.

### Menu Overlay (`menu-overlay`)

Full-screen blurred overlay with links to Home, Our Programs (`/#programs`), Our Projects (`/#projects`), and Contact Us. It repeats the top nav layout with an invisible wordmark, so its close button sits exactly where the menu button is. Escape or following a link closes it.

### Footer (`footer`)

Gradient footer with decorative bubbles. Props with defaults:

- `color` (default: `'green'`)
- `heading` (default: `'Want to make a difference?'`)
- `title` (default: `'Help us restore wildlands for future generations.'`)
- `buttonText` / `buttonHref` (default: `'Get In Touch'` / `mailto:contact@wildland.eco`)
- `showDonate` (default: `true`)
- `showSocials` (default: `true`)

### Content Page (`content-page`)

Shell for standalone content pages such as `/donate`: the mountain and firefly backgrounds (the fireflies image fades in and out at its edges with a mask, as on the home page), a centered content column wrapped in Disappearing Content, and the page's footer in a `footer` slot. Pages add their own root class for their styles (`<x-content-page class="donate-page">`). `_content-page.scss` also provides `.section-divider`, the gradient line between sections.

### Disappearing Content (`disappearing-content`)

Wraps page content so it fades out as it scrolls under the top nav. `disappearing-content.js` recalculates a mask image on scroll and resize.

### Modal (`modal`)

Dialog opened by links to `#id`. Props: `id`, `title`, `closeHref` (where the close link points without JavaScript). Without JavaScript, CSS shows the modal while its id is the URL fragment (`:target`). With JavaScript, `modal.js` opens and closes it with the `is-open` and `is-closed` classes without changing the URL, closes it on Escape or a backdrop click, and moves focus into the modal and back.

### Blob (`blob`) and Blob Shapes (`blob-shapes`)

Image clipped to an organic shape, with two gradient layers rotating behind it. Prop: `type` (shape 1–10); when set, the animation never shrinks below that shape. The background layers' shapes, speeds, and directions are picked randomly on each request.

`blob-shapes` holds the 10 SVG clip-path definitions and is rendered once per page by the first blob (`@once`). Without JavaScript, each blob keeps its static shape. With JavaScript, `blob.js` reads the outlines from those definitions, samples them into radii around the center, and morphs between random shapes; blobs that are not displayed, such as inside closed modals, are skipped.

### Accordion

The programs accordion on the home page is markup rather than a component: `<details>` elements sharing `name="programs"`, so only one section is open at a time without JavaScript. `accordion.js` keeps the open section from closing when clicked again, hides the "tap to learn more" hint after the first click, scrolls the opened section into view, and closes other sections in browsers without exclusive `<details>` support.

## Known Issues and Notes

- `backdrop-filter: blur()` conflicts with DisappearingContent's mask. The donate section uses `rgba(0, 0, 0, 0.5)` without blur as a workaround.
- `backdrop-filter` makes `position: fixed` children position relative to the element instead of the window. The menu overlay pins its copy of the top nav to `top: 0; left: 0` for this reason.
- The favicon is still the Wetfish fish icon; it needs replacing with a Wildland Ecotech logo.
- Blob morphing uses polar coordinate sampling, which can produce slightly irregular shapes depending on the source paths. This is cosmetic and intentional.
