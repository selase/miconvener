# MiConvener

MiConvener is an event management platform for organisers — events, programmes, speakers, registration, payments, settlement, attendance, engagement, and reporting.

It is a **server-rendered multi-tenant monolith**, not a headless service: each organiser is a tenant on its own subdomain, the host console is Inertia + React served by Laravel, and attendees use public pages under `/e/{event}`. Built on a private multi-tenant Laravel SaaS starterkit (Laravel 13, PHP 8.5). See [`manual.md`](manual.md) for the starterkit's architecture and conventions, [`STARTERKIT.md`](STARTERKIT.md) for local setup, and [`conductor/tracks.md`](conductor/tracks.md) for what is built and what is outstanding.

> **On [`miconvener.md`](miconvener.md):** it remains the product brief for the domain — §4–9 (domain model, payments and settlement, on-site operations, engagement, exports) is what the build follows. Its §1–3 framing of MiConvener as an API-first product other applications integrate with — `/api/v1`, OAuth2 client-credentials and PKCE scopes, a public webhook catalogue, generated SDKs, embeddable JS widgets — was **deliberately not adopted**, and none of it is built. Do not scope work against those sections.

## Quick start

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
npm run dev
```

See [`manual.md`](manual.md) §1 for prerequisites and a walkthrough of each step.
