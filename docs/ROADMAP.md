# Roadmap

## Website Platform

The current static SvelteKit site is a temporary foundation. The plan is to migrate to a full Laravel platform using the [Wetfish Skeleton](https://github.com/wetfish/skeleton/) as a starting point.

### Phase 1 — Laravel Foundation

- Set up Laravel using the wetfish skeleton (targeting latest Laravel version)
- Convert existing Svelte templates into Blade views
- Deploy and verify feature parity with current static site
- PHP 8.5-FPM compatible

### Phase 2 — Content Pages

- Individual pages for each project area (High Park, GE Moreau)
- Individual pages for each program (leaf collection, wetland restoration, wildfire mitigation, permaculture)
- About page
- Blog / news section for posting updates (supplementing Instagram/LinkedIn)

### Phase 3 — Donation Platform

Stripe integration using the PHP SDK directly (no Laravel Cashier; the team has experience with custom Stripe integration from Andon Alert).

**Database schema:**

- **users** — Donors and admins, with `stripe_customer_id`, roles (donor, admin, board_member)
- **donations** — Every payment (one-time or subscription renewal), with `stripe_payment_intent_id`, amount in cents, status tracking (succeeded, pending, failed, refunded)
- **subscriptions** — Recurring giving relationships, with `stripe_subscription_id`, plan type (monthly/annual), status (active, past_due, canceled, paused, unpaid), period tracking
- **subscription_events** — Audit trail for lifecycle changes (created, renewed, payment_failed, canceled, reactivated)
- **donation_receipts** — Tax receipt records with receipt numbers and tax year

**Stripe webhook events to handle:**

- `payment_intent.succeeded` — Create donation record
- `invoice.payment_failed` — Mark subscription past_due, trigger notification
- `customer.subscription.updated` — Sync status
- `customer.subscription.deleted` — Mark canceled

**Features:**

- One-time donations with custom amounts ($1-$9999)
- Recurring monthly and annual donations
- Automated tax-deductible donation receipts
- Donor dashboard for managing subscriptions
- Anonymous one-time donations (no account required)
- Account required for recurring donations

**Reporting:**

- Total donations by month/quarter/year
- Active vs churned subscribers
- Monthly recurring revenue (MRR)
- Failed payment rate and recovery
- Average donation size by type
- Donor retention analysis
- Year-end tax receipt generation

### Phase 4 — Analytics and IoT

- User metrics and analytics built into the platform
- Project area tracking with data from drone surveys
- IoT device dashboards (water barrel irrigation system status)
- Real-time monitoring displays for restoration sites

## Ecotech Vision

### Drone Surveys

Regular aerial surveys for year-over-year change detection across restoration areas. Data feeds into grant applications and public reporting on the website. The first survey of Brown Gulch watershed has been completed, with restoration now expanding into newly surveyed areas.

### 3D Terrain Modeling and Watershed Analysis

- Use publicly available LIDAR data to create 3D terrain models of project areas
- Load models into a 3D rendering engine (Unity or Unreal)
- Simulate water flow based on topography and geology
- Calculate total watershed volume to quantify water storage impact of restoration work

### Photogrammetry for Streamflow Measurement

- Phone-based photogrammetry to 3D-scan individual Beaver Dam Analogs and their ponds
- Generate depth maps to measure pond volume
- Estimate streamflow by measuring multiple ponds along a watershed
- Addresses a gap in current hydrology research: few good automated tools exist for measuring streamflow; most methods require tedious manual sampling across multiple locations

### IoT Solar-Powered Irrigation System

A mesh network of solar-powered water barrels for irrigating raised beds in the restoration area.

**Design:**

- Each barrel has 1-2 solar panels, a pump, and IoT sensors
- Sensors monitor water level, battery charge, solar input, and pump status
- Barrels communicate as a mesh network, automatically balancing water distribution
- Fill storage tanks at the top of the hill; the network distributes water downhill automatically
- Barrels sourced from local manufacturers (nutritional supplement company donating food-safe barrels)

**Long-term vision:**

- Real-time dashboard on the Wildland Ecotech website showing system status
- Open-source hardware and software designs

### Remote Monitoring (New York Property)

- Solar-powered live streaming cameras and microphones
- Automatic species identification via audio/visual AI
- Solar-powered boat for aquatic ecosystem surveys
- Bioremediation studies: water, soil, and plant tissue sampling

## Organizational Growth

### Volunteer Program

- Whole Foods volunteer partnership (up to 20 paid employees per store per event)
- Expanding partnerships with new landowners in the High Park burn scar
- Drone survey data enabling targeted expansion into new restoration areas

### Grant Strategy

Priority order for applications:

1. **CWCB Watershed Restoration** — Rolling applications, open now
2. **COSWAP** — Applications expected September 2026
3. **CSFS FRWRM** — Applications expected fall 2026
4. **RESTORE Colorado** — Applications expected fall 2026
5. **USDA CWDG** — Federal program, higher complexity, pursue after first successful state grant

### Education Expansion

- Scale beaver dam workshop kits for classrooms statewide
- Partner with educational organizations for field trips (high school and college students doing actual BDA construction)
- Develop curriculum materials around watershed restoration and water conservation

### Community Partnerships

- Food Not Bombs Fort Collins — Ongoing food production and weekly meal service
- CSU STEM 4 Kids — Hands-on workshops
- Whole Foods Market — Volunteers, in-kind donations, and future grant programs
- Local manufacturers — Barrel donations for IoT irrigation system
- Private landowners — Expanding restoration access across the Front Range

## Infrastructure

### Domain and Email

- **Domain:** wildland.eco (activated through .eco registrar)
- **Current email:** wildland@wetfish.net (temporary, using Wetfish infrastructure)
- **Future:** Set up email on wildland.eco domain

### Repository

- **GitHub organization:** [wildland-ecotech](https://github.com/wildland-ecotech)
- **Website repo:** [wildland.eco](https://github.com/wildland-ecotech/wildland.eco)
- **Future repos:** Laravel platform, IoT firmware, photogrammetry tools, 3D modeling pipeline