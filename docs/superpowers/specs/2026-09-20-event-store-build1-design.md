# MiConvener Marketplace — Build 1: Venue Supply, Catalog & Discovery (Design)

**Updated 2026-10-01.** Codifies the technical design for Build 1 of the MiConvener Marketplace.
Business strategy lives in `docs/superpowers/specs/2026-09-20-event-store-handoff.md`.

Build 1 implements tenant-native shops, venue spaces, the **Included vs. Excluded Amenities Grid**, public discovery search (with Haversine geo-distance), and the superadmin verification queue. **No money moves in Build 1**; bookings, invoicing, and cross-party collaboration follow in Build 2.

---

## 1. Goal and Non-Goals

### Goal
A MiConvener tenant can activate their Merchant Storefront (`Shop`), list physical venue spaces with detailed capacities, transparently specify **Included vs. Excluded Amenities**, and submit their shop for **Blue Tick verification**. Buyers can discover and filter venues on `miconvener.com/marketplace` by location proximity ("near [location]"), capacity (banquet, theater, cocktail), price visibility (`public` vs `on_request`), and amenities.

### In Scope (Build 1)
- Tenant-native `Shop` model (`1:1` with `Tenant`).
- Standardized Amenities catalog (`store_amenities`) and the **Included vs Excluded Grid** (`store_listing_amenities`).
- Venue & Spaces schema (`store_listings`) with minor integer pricing (pesewas), `price_visibility` toggle, and multi-style seating capacities.
- Listing media (`store_listing_media`) for photos and PDF floor plan layouts with upload limits and admin moderation.
- Tenant Console: Spaces & Listings manager under `Venue Operations → Spaces`.
- Public Marketplace Discovery (`/marketplace`, `/marketplace/venues`, `/marketplace/{merchant-slug}`) with Haversine distance search.
- Superadmin Verification Queue (`/admin/marketplace/verifications`).

### Not in Scope (Build 2+)
- Booking requests, availability calendars, pro-forma invoicing, Paystack split payments, 8 Pillars task synchronization, and private venue coordination chat.

### Success Criteria (Verifiable by Pest Tests)
1. A Tenant can activate a `Shop` record with contact info, GPS coordinates, and brand assets.
2. A shop can publish venue listings specifying seating capacities (theater, banquet, cocktail) and rental price in integer pesewas.
3. Every space listing enforces the **Included vs Excluded Amenities Grid** with boolean `is_included`.
4. Searching venues *"near Airport Residential, Accra"* returns active listings ranked by distance using the Haversine formula without external database extensions.
5. If `price_visibility == 'on_request'`, search results render *"Price on request"* instead of raw figures.
6. A Superadmin can review business registration documents and grant the **Blue Tick Verified Badge**.

---

## 2. Naming and Placement Conventions

- **`vendor` is never used as a PHP class or table name** (conflicts with Composer's `vendor/` directory). In code, sellers are **`Shop`** models belonging to a `Tenant`.
- **Money is integer minor units (pesewas)**: `rental_price_pesewas`, `security_deposit_pesewas`.

| Concern | Namespace / Filepath |
| :--- | :--- |
| **Models** | `app/Models/Shop.php`, `app/Models/StoreListing.php`, `app/Models/StoreAmenity.php`, `app/Models/StoreListingAmenity.php`, `app/Models/StoreListingMedia.php` |
| **Public Marketplace Controllers** | `app/Http/Controllers/Marketplace/MarketplaceController.php`, `MarketplaceListingController.php`, `MarketplaceStorefrontController.php` |
| **Tenant Venue Operations Controllers** | `app/Http/Controllers/Tenant/VenueListingController.php`, `VenueProfileController.php` |
| **Superadmin Moderation** | `app/Http/Controllers/Admin/MarketplaceVerificationController.php` |
| **Services** | `app/Services/Marketplace/MarketplaceSearchService.php`, `ShopVerificationService.php` |
| **Form Requests** | `app/Http/Requests/Marketplace/`, `app/Http/Requests/Tenant/Venue/` |
| **Public UI (Inertia + React)** | `resources/js/Pages/Public/Marketplace/` |
| **Tenant Console UI** | `resources/js/Pages/Tenant/Venue/` |
| **Superadmin UI (Blade + Metronic)** | `resources/views/admin/marketplace/` |
| **Migrations** | `database/migrations/landlord/` |
| **Tests** | `tests/Feature/Marketplace/` |

---

## 3. Database Schema

### 3.1 `shops`
Linked 1:1 with `tenants`:
```sql
CREATE TABLE shops (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL UNIQUE REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NULL,
    logo_path VARCHAR(255) NULL,
    cover_image_path VARCHAR(255) NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    region VARCHAR(100) NOT NULL,
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    verification_status VARCHAR(50) NOT NULL DEFAULT 'unverified', -- unverified, pending, verified, rejected
    verified_at TIMESTAMP NULL,
    verified_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    rejection_reason TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
CREATE INDEX shops_city_region_idx ON shops(city, region);
CREATE INDEX shops_geo_idx ON shops(latitude, longitude);
```

### 3.2 `store_amenities` (Standard Catalog)
Standard amenities with grouping categories:
```sql
CREATE TABLE store_amenities (
    id UUID PRIMARY KEY,
    category VARCHAR(50) NOT NULL, -- power_climate, furniture, av_tech, facilities, catering
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    icon VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
```

### 3.3 `store_listings` (Halls & Spaces)
```sql
CREATE TABLE store_listings (
    id UUID PRIMARY KEY,
    shop_id UUID NOT NULL REFERENCES shops(id) ON DELETE CASCADE,
    listing_kind VARCHAR(50) NOT NULL DEFAULT 'venue', -- venue, rental, service, merchandise
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    rental_price_pesewas BIGINT NOT NULL DEFAULT 0,
    pricing_model VARCHAR(50) NOT NULL DEFAULT 'per_day', -- per_day, per_half_day, per_hour, flat_rate
    price_visibility VARCHAR(50) NOT NULL DEFAULT 'public', -- public, on_request
    security_deposit_pesewas BIGINT NULL,
    capacity_breakdown JSONB NOT NULL DEFAULT '{}', -- {"theater": 600, "banquet": 350, "cocktail": 800, "classroom": 250}
    floor_area_sqm DECIMAL(8, 2) NULL,
    ceiling_height_meters DECIMAL(5, 2) NULL,
    rules_and_policies JSONB NOT NULL DEFAULT '{}', -- {"outside_catering": true, "curfew": "23:00"}
    status VARCHAR(50) NOT NULL DEFAULT 'draft', -- draft, published, suspended
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE(shop_id, slug)
);
CREATE INDEX store_listings_status_idx ON store_listings(status);
```

### 3.4 `store_listing_amenities` (The Included vs. Excluded Grid)
```sql
CREATE TABLE store_listing_amenities (
    id UUID PRIMARY KEY,
    listing_id UUID NOT NULL REFERENCES store_listings(id) ON DELETE CASCADE,
    amenity_id UUID NOT NULL REFERENCES store_amenities(id) ON DELETE CASCADE,
    is_included BOOLEAN NOT NULL DEFAULT true, -- true = included in base price, false = excluded / optional add-on
    notes VARCHAR(255) NULL, -- e.g. "300 chairs included, additional charged per unit"
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE(listing_id, amenity_id)
);
CREATE INDEX store_listing_amenities_inc_idx ON store_listing_amenities(listing_id, is_included);
```

### 3.5 `store_listing_media`
```sql
CREATE TABLE store_listing_media (
    id UUID PRIMARY KEY,
    listing_id UUID NOT NULL REFERENCES store_listings(id) ON DELETE CASCADE,
    media_type VARCHAR(50) NOT NULL, -- photo, floor_plan_pdf
    file_path VARCHAR(255) NOT NULL,
    title VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_primary BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
```

---

## 4. Geo-Search & Haversine Distance

Distance queries compute the spherical distance between the search coordinate $(\phi_1, \lambda_1)$ and venue coordinate $(\phi_2, \lambda_2)$:

$$\text{distance\_km} = 6371 \times 2 \times \arcsin\left(\sqrt{\sin^2\left(\frac{\Delta \phi}{2}\right) + \cos(\phi_1)\cos(\phi_2)\sin^2\left(\frac{\Delta \lambda}{2}\right)}\right)$$

Executed natively in PostgreSQL query builder without requiring PostGIS extensions. Bounding box filters (`latitude BETWEEN min_lat AND max_lat`) prune distant records before computing trigonometric functions.

---

## 5. Public Marketplace Routes (`routes/marketplace.php`)

Registered on the main platform domain:
- `GET /marketplace`: Marketplace landing hub (featured venues, category pillars).
- `GET /marketplace/venues`: Venue search & discovery index with filters:
  - `near`: string or `lat,lng`
  - `radius`: km (default 25)
  - `min_capacity`: integer
  - `seating_style`: `banquet` | `theater` | `cocktail`
  - `price_mode`: `all` | `public_only`
  - `amenities[]`: array of amenity slugs
- `GET /marketplace/venues/{slug}`: Detailed venue listing page (capacities, Included vs Excluded grid, photos, host cross-sell).
- `GET /marketplace/{merchant_slug}`: Merchant Brand Storefront profile.

---

## 6. Superadmin Verification & Trust Review

Admin routes at `/admin/marketplace/verifications`:
- View submitted business registration documents and Ghana Card.
- Inspect host physical address and past event credentials.
- Action: **Approve (Grants Blue Tick)** $\rightarrow$ stamps `verified_at = now()`.
- Action: **Reject** $\rightarrow$ records `rejection_reason` and emails tenant administrator.
