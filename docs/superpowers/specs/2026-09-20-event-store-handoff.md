# MiConvener Marketplace — Architecture & Strategy Handoff

**Updated 2026-10-01.** Codifies the agreed architectural and business evolution from the original 2026-09-20 brainstorming notes.

---

## 1. Executive Summary & Strategic Rationale

The **MiConvener Marketplace** (`miconvener.com/marketplace`) unifies event spaces, equipment rentals, and event services into an **operational SaaS + Marketplace flywheel**.

### Key Strategic Pivots
1. **Tenant-Native Merchants**: Instead of maintaining a detached landlord-only shop system (`tenant_id = null`), every vendor or venue on the Marketplace is an active **MiConvener Tenant** (`Shop` belongs to `Tenant`). Vendors ARE MiConvener accounts.
2. **Venues as the Operational Hero**: Physical venues are high-GMV anchors. They require dedicated capacity tracking across seating styles (theater, banquet, cocktail), geo-proximity search ("near [location]" via Haversine), and transparent **Included vs. Excluded Amenities** checklists.
3. **Approval-First Bookings**: Venue bookings require availability and pricing confirmation before payment unlocks.
4. **Dual Payment Channels**: Instant online checkout via Paystack (split payment with automatic platform commission) OR Direct Bank Transfer (Pro-Forma PDF invoice with receipt upload and manual verification).
5. **Operational Collaboration Loop**: Confirmed venue bookings directly integrate with event execution:
   - Tasks assigned to the venue flow through the default **8 Pillars** (`Logistics → Planning`, Pillar 6: `Venue & Logistics`).
   - Private, real-time **Venue Coordination Chat** embedded in `Venue & seating` and the venue's multi-event Messages Inbox.
6. **Multi-Event Venue Operations Hub**: Venue hosts (e.g. Labadi Beach Hotel) manage all bookings, tasks, and communications across dozens of organizer tenants from a single consolidated dashboard.
7. **Viral Growth Loop**: Walk-in and offline clients receive a lightweight Booker Portal (`/bookings/{uuid}`) with an automated 1-click path to launch an event workspace on MiConvener.

---

## 2. Marketplace Taxonomy & Hierarchy

```
                           M I C O N V E N E R   M A R K E T P L A C E
                                             │
    ┌────────────────────────┬───────────────┴───────────────┬────────────────────────┐
    ▼                        ▼                               ▼                        ▼
1. Venues & Spaces       2. Equipment & Rentals          3. Event Services        4. Print & Merch
• Auditoriums            • Audio / Visual (PA, screens)  • Catering & Food        • Badges & Lanyards
• Banquet Halls          • Canopies & Tents              • Photography / Video    • Banners & Signage
• Outdoor Gardens        • Furniture (Chairs, Tables)    • Live Streaming         • Swag & Conference Bags
• Boardrooms             • Generators & Backup Power     • Ushers & Security      • T-Shirts & Gifts
```

### The Merchant Brand Storefront (`/marketplace/{merchant-slug}`)
Premier merchants (like Labadi Beach Hotel) showcase their physical spaces alongside in-house catering, AV packages, and equipment upgrades in a single branded hub.

---

## 3. Financial Invariants & Money Flow

Adheres to all project finance rules:
- **Money is integer minor units (pesewas)**: `rental_price_pesewas`, `security_deposit_pesewas`, `platform_commission_pesewas`.
- **Platform Commission**: Default 10% on marketplace bookings (or category-specific/vendor deal).
- **Paystack Split Payments**: For online checkout, funds split directly to the venue's `TenantPayoutAccount` (`recipient_code`), while the platform retains its commission at source.
- **Direct Bank Transfer**: Pro-Forma PDF invoice includes the venue's corporate bank details and booking reference; platform commission is debited/tracked on the tenant ledger upon payment confirmation.

---

## 4. Staged 3-Build Roadmap

- **Build 1: Venue Supply, Catalog & Marketplace Discovery**
  - Tenant-native `shops` model.
  - `store_listings` schema with venue-specific capacities, geo-coordinates, and `price_visibility` (`public` vs `on_request`).
  - Transparent **Included vs. Excluded Amenities & Facilities Grid**.
  - Merchant Brand Storefronts (`/marketplace/{merchant-slug}`).
  - Public Marketplace Discovery & Geo-Search (`miconvener.com/marketplace` via Haversine distance).
  - Superadmin "Blue Tick" Verification Queue.
- **Build 2: Bookings, Invoicing & Multi-Event Collaboration**
  - Approval-first booking request state machine.
  - Multi-channel payments: Paystack Split + Direct Bank Transfer with Pro-Forma PDF invoices.
  - 8 Pillars task integration (Pillar 6: `Venue & Logistics`).
  - Private real-time Venue Coordination Chat (Laravel Reverb).
  - Multi-Event Venue Operations Hub (Master Calendar, multi-event Messages Inbox, aggregated tasks).
  - Booker Portal (`/bookings/{uuid}`) and 1-click "Launch Event on MiConvener" handoff.
- **Build 3: Dual Reputation, Boosts & General Suppliers**
  - Reciprocal star ratings (Host rates Venue; Venue rates Host).
  - Composite search ranking algorithm.
  - Paid search boosts and featured placements.
  - Equipment rentals, services, and merchandise catalog expansion.
