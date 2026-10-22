# Style Guide

How wildland.eco looks and reads. Follow it for every page so the site stays consistent. Text roles are defined in `laravel/resources/scss/_typography.scss`; base element and button styles are in `laravel/resources/scss/_base.scss`. The page and component structure is documented in [Frontend](04-frontend.md).

## Principles

- **Lead with impact, then give details.** Large white text carries the key message we want people to take away. Smaller muted text gives the details, further information, and subtitles. Each page states its main message in its opening card.
- **Pick a role, not a size.** Pages use the text roles below instead of setting their own font sizes and colors, so the same kind of text looks the same everywhere.
- **Works without JavaScript.** Animations and enhancements are extras on top of server-rendered pages (see [Progressive Enhancement](05-ai-development-notes.md#progressive-enhancement)).

## Colors

| Use | Value |
|-----|-------|
| Page background | `#010902` |
| Green accent: links, card borders, focus | `#2ECF9E` |
| Button fill | `rgba(46, 207, 158, 0.25)` |
| Cards and panels | `rgba(0, 0, 0, 0.3)`, or `rgba(0, 0, 0, 0.5)` for opening cards |
| Lead text | `#fff` |
| Detail text | `rgba(255, 255, 255, 0.75)` |
| Fine print | `rgba(255, 255, 255, 0.6)` |
| Errors | `#FF8A80` |
| Labels and `h2` headings | Gradient `linear-gradient(95.37deg, #7AA8F3 -4%, #88FF82 111%)` |

## Typography

### Fonts

- **Red Hat Display** (Bold, `redhat`): page headings (`h1`), card headings (`h2`), section numbers, and amounts
- **Clash Display** (Semibold, `clash`): section labels (`h3`, uppercase)
- **Satoshi** (Medium, `satoshi`): all other text, including buttons and forms

### Text Roles

Every piece of body text uses one of these roles:

| Role | Use for | Size (phone → desktop) | Color | In Sass |
|------|---------|------------------------|-------|---------|
| **Lead** | The key message: home page sections, each page's opening card | 21px → 24px | White | Default for `p` and `li`; `.prose.lead`; `@include type.lead` |
| **Detail** | Supporting information: cards, guidelines, privacy, FAQ answers, form introductions and terms, subtitles | 18px → 20px, line height 1.6 | White at 75% | `.prose` on content pages; `@include type.detail` |
| **Fine print** | Form hints, footnotes, legal text | 15px | White at 60% | `@include type.fine-print` |

Desktop sizes start at 1024px wide. A partial uses the roles with `@use '../typography' as type;`.

Headings, section labels, buttons, form fields, badges, and stats have their own component styles and are not body text.

### Headings

- **Section label:** an `h3` starting with an em dash, such as "— Leaf Program". Short, in title case; the CSS makes it uppercase.
- **Heading:** an `h1` right after the label, a few words that name the section, such as "Leave the Leaves". 49px, 62px from 1024px wide; pages may set it smaller when it would wrap inside a card (the FAQ heading is 48px).
- **Card heading:** an `h2` inside a card, such as "Drop-Off".

## Layout

- **Content pages** use `<x-content-page>`: the mountain and firefly backgrounds, a 960px content column, and the footer.
- **Opening card:** each content page starts with a dark card (`rgba(0, 0, 0, 0.5)`, 16px corners) holding the section label, the heading, and the page's Lead text.
- **Sections:** the label and heading are centered; text is left-aligned in a 680px column (`.prose`). Separate sections with `.section-divider`.
- **Cards:** supporting content sits in dark cards (`rgba(0, 0, 0, 0.3)`) with a 3px green top border, such as the Drop-Off and Pickup cards.
- **Calls to action** go where the reader is ready for them, and outside sections whose message they contradict. For example, the "Apply to Donate Leaves" button follows "Donate Your Leaves", not the "Leave the Leaves" card.

## Buttons and Links

- **Buttons** are for actions (apply, send, donate) and for opening sections (accordion and FAQ questions). Use `.button.full` for the main action and `.button.dark` for secondary ones.
- **Text links** are for navigation within content: green and underlined, such as "Learn more about leaf collection →". Don't use a button inside a section of buttons that open sections, where it would look like one of them.
- **Menu labels** say what visitors are looking for, such as "Leaf Collection", rather than internal program names.

## Motion

- Content fades out as it scrolls under the top nav (Disappearing Content).
- Anything that animates every frame pauses while it is off screen (`watchOnScreen()` in `on-screen.js`), so it never makes scrolling stutter.
- Every animation has a static or CSS fallback without JavaScript.

## Writing

- **Name:** "Wildland Ecotech". Use the legal name, Wildland Ecology Technologies, only where it matters (once, as in the FAQ's first answer), and never "WET".
- **Plain language:** say what we do and why, not legalese. For example, the Privacy section explains what we share and why instead of listing terms.
- **Familiar section names:** keep names people look for, such as "Privacy", even when the content is written conversationally.
- **Accurate places:** the New York property is next to the GE Moreau Superfund site, not in it.
- **Contact:** there is no contact page; link the email addresses (contact@wildland.eco, volunteer@wildland.eco).
- **People:** don't name team members or publish their bios until they have agreed.
