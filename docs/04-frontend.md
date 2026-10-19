# Frontend

## Current State

The public site is still the static SvelteKit build in `ui/`. It is being converted to server-rendered Blade templates in `laravel/` (see [milestone 3](06-planned-features.md#3-convert-the-site-to-blade)). Until that conversion is complete, this document describes the SvelteKit implementation. The design system, pages, and component behavior below are what the Blade version must reproduce.

`laravel/` still contains Laravel's default welcome page and Tailwind setup. Tailwind will be removed during the conversion.

### SvelteKit Stack (`ui/`)

- **Framework:** SvelteKit 2 with Svelte 5 (runes syntax: `$state`, `$props`, `$effect`)
- **Language:** TypeScript / JavaScript
- **Styling:** SCSS (Sass)
- **Build Output:** Static HTML via `@sveltejs/adapter-static` (outputs to `ui/public_html/`)

## Design System

### Fonts

- **Clash Display** (Semibold) — Headings
- **Red Hat Display** (Bold) — Section numbers, amounts
- **Satoshi** (Medium) — Body text, buttons, UI elements

Font files live in `ui/src/lib/fonts/`.

### Color Scheme

- **Page background:** `#010902`
- **Green accent:** `#2ECF9E`
- **Button fill:** `rgba(46, 207, 158, 0.25)`
- **Dark transparent:** `rgba(0, 0, 0, 0.3)` to `rgba(0, 0, 0, 0.5)`
- **Text primary:** `#fff`
- **Text secondary:** `rgba(255, 255, 255, 0.6)` to `rgba(255, 255, 255, 0.8)`

## Pages

### Home (`/`)

The main landing page adapted from the Wetfish Nature page. Features:

- Illustrated mountain landscape background with firefly forest transition
- Social media CTA with animated canvas rainbow gradient border (CSS fallback for no-JS)
- Three content sections: Our Mission, What We Do (accordion), Our Projects (clickable modals)
- Morphing blob clip-paths on project images with rotating gradient background layers
- "Tap to learn more" hint on accordion (disappears after first interaction)
- DisappearingContent scroll mask effect

### Donate (`/donate`)

Donation page with tier selection. Features:

- Compressed mountain header with gradient fade to fireflies
- Frequency toggle: One-Time / Monthly / Annual
- Tier cards: `[5, 20, 50]` for one-time, `[20, 50, 100]` for monthly, `[100, 1000, 5000]` for annual
- Custom amount input with validation ($1 min, $9999 max, text input with decimal filtering, no browser spinners)
- Greyed-out donate button until valid amount selected
- "What You're Supporting" narrative cards: Wildfire Protection, Water Conservation, Community Action, Education
- Impact stats: 1,000+ Acres, 70+ BDAs, 500+ Trees, 80+ Volunteers
- "Other Ways to Help" with social links and volunteer CTA
- Customized footer (no donate button, "Back to Home" CTA)

The donation selector currently runs entirely in client-side Svelte state, and the donate button does not submit anywhere yet.

### Menu (`/menu`)

A full-page menu for users without JavaScript. The MenuButton component uses progressive enhancement: `<a href="/menu">` that JS intercepts to open the overlay instead.

## Components

### TopNav

Text wordmark "Wildland **Ecotech**" with dark transparent background, green accent on "Ecotech".

### MenuButton

Progressive enhancement: anchor link to `/menu` (no-JS), JS intercepts click to toggle overlay. Escape key closes overlay.

### Menu

Full-screen overlay with navigation links. Anchors to page sections (`#mission`, `#programs`, `#projects`), contact email, and donate link.

### Accordion

Expandable content sections used for programs on the home page. Tracks `hasInteracted` state and shows a bouncing "tap to learn more" hint below the last button until first interaction. Uses `getBoundingClientRect()` for scroll positioning (compatible with `position: relative` ancestors).

### Blob

Morphing image clip-path component with rotating gradient background layers. Features:

- **CSS fallback:** Static `clip-path: url(#blob-N)` for no-JS users
- **JS enhancement:** Samples 10 SVG blob paths into polar coordinates (64 angles), morphs between random shapes continuously using requestAnimationFrame
- **Optional `type` prop:** When set, the specified shape acts as a floor (shape can grow but never shrink below the base type)
- **Background layers:** Two gradient-filled divs clipped to random blob shapes, rotating slowly in opposite directions via CSS animation (no JS needed)

### Blobs

SVG clip-path definitions (10 blob shapes) used by Blob component. Rendered in the layout, provides global `<clipPath>` elements.

### DisappearingContent

Scroll-based mask effect that fades content as it approaches the top nav. Uses `mask-image` / `-webkit-mask-image` CSS properties calculated from scroll position. Runs on mount, scroll, and resize.

### Modal

Generic modal component. Props: `open`, `title`, `onclose`. Features: blurred backdrop, close button (above modal on desktop, at border on mobile), escape key and click-outside-to-close. Content comes from parent via slot. No opinions about layout inside.

### Footer

Customizable footer with green gradient background and decorative SVG bubbles. Props with defaults:

- `color` (default: `'green'`)
- `heading` (default: `'Want to make a difference?'`)
- `title` (default: `'Help us restore wildlands for future generations.'`)
- `buttonText` / `buttonHref` (default: `'Get In Touch'` / `mailto:contact@wildland.eco`)
- `showDonate` (default: `true`)
- `showSocials` (default: `true`)

## Known Issues and Notes

- `backdrop-filter: blur()` conflicts with DisappearingContent's CSS mask filter. The donate section uses `rgba(0, 0, 0, 0.5)` without blur as a workaround.
- The favicon is still the Wetfish fish icon; needs replacing with a Wildland Ecotech logo.
- The canvas rainbow border on the social CTA requires JS; CSS fallback is a solid color cycling animation.
- Blob morphing uses polar coordinate sampling which can produce slightly irregular shapes depending on the source paths. This is cosmetic and intentional.
- The project modals on the home page only open with JavaScript, and only the first accordion section is reachable without it. The Blade version needs no-JS fallbacks for both (see [milestone 3](06-planned-features.md#3-convert-the-site-to-blade)).
