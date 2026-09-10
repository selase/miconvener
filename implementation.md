# Feature Implementation Plan: Tenant-Configurable Registration Forms, Conditional Fields & Dynamic Pricing

## Context & Objectives
Empower tenants to configure registration forms per event with:
1. Mandatory core identity fields: Title, First Name, Last Name, and Email.
2. Tenant-configurable standard fields: Phone, Dietary Requirements, Accessibility Needs (required, optional, or hidden).
3. Arbitrary tenant-defined custom fields and options (not limited to any fixed list).
4. Conditional visibility rules (e.g. show field B only when field A equals option X).
5. Dynamic option-based pricing (choices directly determine or adjust registration cost).

## Tracks & Tasks
- [ ] Task 1: Create database migrations:
  - Add `title`, `first_name`, `last_name`, and `form_answers` (JSON) to `event_registrations`.
  - Add `registration_settings` (JSON) to `events`.
  - Create `event_form_fields` table with labels, field types, options with prices, and conditional logic rules.
- [ ] Task 2: Update `EventRegistration` and `Event` models with new attributes, casting, and helper methods.
- [ ] Task 3: Create `EventFormField` model with relationships, scopes, and validation helpers.
- [ ] Task 4: Create `RegistrationPricingService` to evaluate form choices and calculate authoritative registration pricing server-side.
- [ ] Task 5: Build `EventFormFieldController` and register tenant endpoints in `routes/subdomain.php`.
- [ ] Task 6: Build `RegistrationFormPanel.jsx` in `resources/js/Pages/Tenant/Events/panels/` for tenants to manage standard field requirements, custom fields, arbitrary options, conditional visibility, and pricing.
- [ ] Task 7: Update public registration in `resources/js/Pages/Public/Events/Show.jsx` with Title, First Name, Last Name, Email, configurable standard fields, dynamic custom inputs, reactive condition evaluation, and live price updates.
- [ ] Task 8: Update `PublicEventController::register` to enforce mandatory identity fields, validate conditional custom inputs, and charge the calculated price.
- [ ] Task 9: Update `EventReportController` and attendee views to include Title, First Name, Last Name, and dynamic custom form answers in exports.
- [ ] Task 10: Write comprehensive Pest tests in `tests/Feature/Events/EventCustomFormFieldTest.php`, run test suite, and run Pint formatter.
