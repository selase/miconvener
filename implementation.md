# Implementation Plan: MiConvener Marketplace (Build 1: Venue Supply, Catalog & Discovery)

## Overview
This implementation builds the foundational supply, listing catalog, amenities transparency grid, and public discovery engine for the **MiConvener Marketplace** (`miconvener.com/marketplace`).

---

## Phase 1: Database Migrations, Seeders & Eloquent Models

- [ ] **1.1 Migrations (`database/migrations/landlord/`)**:
  - `create_shops_table.php`: 1:1 with `tenants`, brand assets, contact info, physical address, GPS coordinates, verification status.
  - `create_store_amenities_table.php`: Standardized amenities with categories (`power_climate`, `furniture`, `av_tech`, `facilities`, `catering_rules`) and icons.
  - `create_store_listings_table.php`: Halls/spaces with integer pricing (`rental_price_pesewas`), `pricing_model`, `price_visibility` (`public` vs `on_request`), capacity breakdown (theater, banquet, cocktail), floor area, and rules.
  - `create_store_listing_amenities_table.php`: Pivot enforcing the **Included vs Excluded Amenities Grid** (`is_included: boolean`, `notes`).
  - `create_store_listing_media_table.php`: Photos and PDF floor plans with sort order and primary flag.
- [ ] **1.2 Standard Seeders**:
  - `StoreAmenitySeeder.php`: Seeds core conference & venue amenities (AC, 500kVA generator, standard banquet chairs, round tables, stage, podium, wireless mics, projector & screens, executive restrooms, parking, outside catering license).
- [ ] **1.3 Eloquent Models & Relationships (`app/Models/`)**:
  - `Shop.php`: `belongsTo(Tenant::class)`, `hasMany(StoreListing::class)`. Scopes: `active()`, `verified()`.
  - `Tenant.php`: Add `hasOne(Shop::class)` relationship.
  - `StoreAmenity.php`: `hasMany(StoreListingAmenity::class)`.
  - `StoreListing.php`: `belongsTo(Shop::class)`, `hasMany(StoreListingAmenity::class)`, `hasMany(StoreListingMedia::class)`. Scopes: `published()`, `venues()`, `nearLocation($lat, $lng, $radiusKm)`.
  - `StoreListingAmenity.php`: `belongsTo(StoreListing::class)`, `belongsTo(StoreAmenity::class)`.
  - `StoreListingMedia.php`: `belongsTo(StoreListing::class)`.

---

## Phase 2: Tenant Console — Venue Profile & Spaces Manager

- [ ] **2.1 Controllers & Requests (`app/Http/Controllers/Tenant/Venue/`)**:
  - `VenueProfileController.php`: Activate or update tenant's `Shop` brand profile, GPS coordinates, and contact details.
  - `VenueListingController.php`: CRUD operations for spaces/halls (`store_listings`).
  - Form Requests: `StoreVenueProfileRequest.php`, `StoreVenueListingRequest.php`.
- [ ] **2.2 Frontend Console Views (`resources/js/Pages/Tenant/Venue/`)**:
  - `Spaces/Index.jsx`: List of spaces with capacity, price visibility, and status pill.
  - `Spaces/Edit.jsx`: Form with basic specs (floor area, ceiling height, theater/banquet capacities), pricing model, and the **Included vs Excluded Amenities checklist**.
  - `Profile/Edit.jsx`: Brand profile, address, GPS picker, and verification document upload.
- [ ] **2.3 Console Navigation Integration**:
  - Update `ConsoleLayout.jsx` to render `Venue Spaces` when tenant has merchant profile active.

---

## Phase 3: Public Marketplace Discovery & Geo-Search Engine

- [ ] **3.1 Routing (`routes/marketplace.php`)**:
  - `GET /marketplace`: Landing hub showcasing featured venues and taxonomy pillars.
  - `GET /marketplace/venues`: Search and filter catalog.
  - `GET /marketplace/venues/{slug}`: Detailed venue listing page.
  - `GET /marketplace/{merchant_slug}`: Merchant Brand Storefront.
- [ ] **3.2 Search Service (`app/Services/Marketplace/MarketplaceSearchService.php`)**:
  - Natively executes PostgreSQL Haversine distance calculations with bounding-box index filtering.
  - Filters by minimum capacity across seating styles (`banquet`, `theater`, `cocktail`).
  - Filters by price visibility and included amenities.
- [ ] **3.3 Public Marketplace Frontend (`resources/js/Pages/Public/Marketplace/`)**:
  - `Index.jsx`: Hero search bar, featured verified venues, category cards.
  - `Venues/Index.jsx`: Filter sidebar (location radius, seating capacity, price visibility, amenities), list/grid cards with distance tag, capacity badge, and price.
  - `Venues/Show.jsx`: Space gallery, specs card, **Included vs Excluded Amenities Grid**, and host cross-sell preview.
  - `Storefront/Show.jsx`: Merchant brand hub showing all spaces and services.

---

## Phase 4: Superadmin Verification & Trust Review Queue

- [ ] **4.1 Controller (`app/Http/Controllers/Admin/MarketplaceVerificationController.php`)**:
  - Protected by `access-superadmin-dashboard` gate.
  - Review business registration and Ghana Card.
  - `approve(Shop $shop)`: Stamps `verified_at = now()` and sets `verification_status = 'verified'`.
  - `reject(Shop $shop)`: Records `rejection_reason`.
- [ ] **4.2 Blade Views (`resources/views/admin/marketplace/verifications/`)**:
  - `index.blade.php`: Queue of pending shops with document preview links.
  - `show.blade.php`: Detailed review sheet with verification decision modal.

---

## Phase 5: Verification, Tests & Quality Gates

- [ ] **5.1 Automated Feature Tests (Pest)**:
  - `tests/Feature/Marketplace/TenantShopActivationTest.php`: Tenant activates shop, verifies 1:1 relationship and GPS storage.
  - `tests/Feature/Marketplace/StoreListingCrudTest.php`: Create hall, assert integer pricing (pesewas) and JSON capacity structure.
  - `tests/Feature/Marketplace/AmenityGridTest.php`: Assert included vs excluded checklist persistence and querying.
  - `tests/Feature/Marketplace/MarketplaceSearchTest.php`: Assert Haversine proximity ordering and capacity/amenity filters.
  - `tests/Feature/Marketplace/ShopVerificationTest.php`: Superadmin approves shop, verifies Blue Tick status and rejection handling.
- [ ] **5.2 Quality Gates**:
  - Run `vendor/bin/pint --dirty` to ensure code style compliance.
  - Run `phpstan analyze` to ensure 0 static analysis errors.
  - Run full test suite with compact reporting.
