# Technical Documentation

## Current Architecture

The website is a statically generated site built with SvelteKit and served via nginx inside a Docker container.

### Tech Stack

- **Framework:** SvelteKit 2 with Svelte 5 (runes syntax: `$state`, `$props`, `$effect`)
- **Language:** TypeScript / JavaScript
- **Styling:** SCSS (Sass)
- **Build Output:** Static HTML via `@sveltejs/adapter-static` (outputs to `public_html/`)
- **Server:** nginx 1.25.2 (Alpine)
- **Container:** Docker (multi-stage build: Node 20 for build, nginx for serve)
- **CI/CD:** GitHub Actions auto-builds Docker images on push to `main` or `release`
- **Registry:** GitHub Container Registry (`ghcr.io/wildland-ecotech/wildland.eco`)
- **Auto-Update:** Watchtower polls for new images every 30 seconds

### Fonts

- **Clash Display** (Semibold) — Headings
- **Red Hat Display** (Bold) — Section numbers, amounts
- **Satoshi** (Medium) — Body text, buttons, UI elements

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
- `buttonText` / `buttonHref` (default: `'Get In Touch'` / `mailto:wildland@wetfish.net`)
- `showDonate` (default: `true`)
- `showSocials` (default: `true`)

## Deployment

### Production

The site runs behind a Traefik reverse proxy. The production Docker Compose file uses Watchtower for automatic updates when new images are pushed to GHCR.

**nginx config requirement:** The server must include `try_files $uri $uri.html $uri/ =404;` to serve SvelteKit's generated pages without `.html` extensions.

Example production nginx server block (external to container):

```nginx
server {
    server_name wildland.eco *.wildland.eco;
    root /home/sites/wildland.eco/ui/public_html;

    location ~ /\. {
        deny all;
        return 404;
    }

    location / {
        index index.html;
        try_files $uri $uri.html $uri/ =404;
    }
}
```

HTTPS is managed via Certbot.

### Development

Two options:

1. **Vite dev server:** `cd ui && npm run dev` for hot module replacement
2. **Docker:** `docker compose -f docker-compose.dev.yml up --build` to test the production build locally on port 31337

Note: Docker serves pre-built static files. Source changes require `--build` flag to rebuild the container.

### CI/CD Pipeline

GitHub Actions builds and pushes Docker images on push to `main` (tagged `staging`, `latest`) or `release` (tagged `prod`). Images are pushed to `ghcr.io/wildland-ecotech/wildland.eco`. Watchtower on the production server detects new images and auto-restarts the container.

## Known Issues and Notes

- The Footer component still references `hello@wildland.eco` in the default `buttonHref` prop; the correct email is `wildland@wetfish.net`. Update when convenient.
- `backdrop-filter: blur()` conflicts with DisappearingContent's CSS mask filter. The donate section uses `rgba(0, 0, 0, 0.5)` without blur as a workaround.
- The favicon is still the Wetfish fish icon; needs replacing with a Wildland Ecotech logo.
- The canvas rainbow border on the social CTA requires JS; CSS fallback is a solid color cycling animation.
- Blob morphing uses polar coordinate sampling which can produce slightly irregular shapes depending on the source paths. This is cosmetic and intentional.

## AI Development Guidelines

This project is being built with the assistance of Claude (Anthropic). The following conventions must be maintained for consistent, high-quality output. These notes serve as a reference for both the AI assistant and the developer.

### Always Ask For Context

If you ever need to see how something is implemented, never guess function names or implementation details; always ask the user to upload relevant files first. Once the files are processed make sure they have all of the context necessary to build the feature. Sometimes you might need to look deeper into dependencies to see how the project is laid out before reinventing the wheel.

### Code Block Formatting

Always use an explicit language identifier on every code block (e.g., `bash`, `ini`, `text`, `php`). Include a descriptive title line before each block.

Bare ` ``` ` without a language tag causes consecutive blocks to merge into a single block in the Claude chat renderer. This was identified early and must be avoided throughout all documentation and chat output.

### File Path References

When referencing files for the developer to open, always provide full file paths relative to the repo root using `codium` commands:

```bash
codium README.md
codium docker/php/custom.ini
codium laravel/app/Http/Controllers/ExampleController.php
```

This convention ensures the developer can copy-paste commands directly without needing to figure out where a file lives.

### Artifact Ordering

When providing downloadable file artifacts alongside a list of `codium` commands, the `codium` commands must be listed in the same order as the artifacts appear in the download list. This prevents confusion when the developer is opening files sequentially to paste content into.

For example, if three artifacts are provided in order — Controller, View, Routes — the commands should be:

```bash
codium laravel/app/Http/Controllers/ExampleController.php
codium laravel/resources/views/example/index.blade.php
codium laravel/routes/web.php
```

### Artifact Naming

When providing multiple files with the same filename (e.g., multiple `index.blade.php` files), use descriptive artifact names so they are distinguishable in the download list. For example: "Users index.blade" and "Settings index.blade" rather than two files both named "index.blade".